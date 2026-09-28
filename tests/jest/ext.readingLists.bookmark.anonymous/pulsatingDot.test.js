const STORAGE_KEY_IMPRESSION = 'we-reading-list-cta-pulsating-dot-impression';

let initializePulsatingDot;

function createBookmarkWrapper() {
	const el = document.createElement( 'div' );
	el.id = 'page-actions-bookmark';
	document.body.appendChild( el );
	return el;
}

function requireModule( showDot = true ) {
	jest.doMock( '../../../resources/ext.readingLists.bookmark.anonymous/config.json', () => ( {
		ReadingListsMinervaCTAShowDot: showDot
	} ) );
	( { initializePulsatingDot } = require(
		'../../../resources/ext.readingLists.bookmark.anonymous/pulsatingDot.js'
	) );
}

beforeEach( () => {
	jest.resetModules();
	mw.storage.get.mockReturnValue( null );
	mw.log = jest.fn();
	mw.loader.using.mockReturnValue( Promise.resolve() );
	requireModule( true );
} );

afterEach( () => {
	jest.restoreAllMocks();
	document.body.innerHTML = '';
} );

describe( 'initializePulsatingDot', () => {
	describe( 'when ReadingListsMinervaCTAShowDot is false', () => {
		test( 'returns resolved promise without loading module', async () => {
			jest.resetModules();
			requireModule( false );
			createBookmarkWrapper();

			await expect( initializePulsatingDot() ).resolves.toBeUndefined();
			expect( mw.loader.using ).not.toHaveBeenCalled();
		} );
	} );

	describe( 'when ReadingListsMinervaCTAShowDot is true', () => {
		describe( 'and user has clicked bookmark', () => {
			test( 'does not load module', async () => {
				mw.storage.get.mockReturnValue( '1' );
				createBookmarkWrapper();

				await expect( initializePulsatingDot() ).resolves.toBeUndefined();
				expect( mw.loader.using ).not.toHaveBeenCalled();
			} );
		} );

		describe( 'when bookmark wrapper element is absent', () => {
			test( 'does not load module', async () => {
				await expect( initializePulsatingDot() ).resolves.toBeUndefined();
				expect( mw.loader.using ).not.toHaveBeenCalled();
			} );
		} );

		describe( 'when bookmark wrapper is present and user has not clicked bookmark', () => {
			let bookmarkWrapper;

			beforeEach( () => {
				bookmarkWrapper = createBookmarkWrapper();
			} );

			test( 'adds mw-pulsating-dot class after module loads', async () => {
				await initializePulsatingDot();
				expect( bookmarkWrapper.classList.contains( 'mw-pulsating-dot' ) ).toBe( true );
			} );

			test( 'click handler stores impression with 90-day expiry and removes class', async () => {
				await initializePulsatingDot();

				bookmarkWrapper.click();

				expect( mw.storage.set ).toHaveBeenCalledWith(
					STORAGE_KEY_IMPRESSION, '1', 60 * 60 * 24 * 90
				);
				expect( bookmarkWrapper.classList.contains( 'mw-pulsating-dot' ) ).toBe( false );
			} );
		} );
	} );
} );
