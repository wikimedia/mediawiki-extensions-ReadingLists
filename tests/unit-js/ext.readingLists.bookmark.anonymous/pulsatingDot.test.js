const STORAGE_KEY_IMPRESSION = 'we-reading-list-cta-pulsating-dot-impression';

let initializePulsatingDot;

function createBookmarkWrapper() {
	const wrapper = document.createElement( 'li' );
	wrapper.id = 'page-actions-bookmark';
	const el = document.createElement( 'a' );
	el.id = 'ca-bookmark';
	wrapper.appendChild( el );
	document.body.appendChild( wrapper );
	return el;
}

async function requireModule( showDot = true ) {
	// The named export is what the hoisted `import { … } from './config.json'`
	// reads; `default` mirrors the shape of the real JSON module.
	vi.doMock( '../../../resources/ext.readingLists.bookmark.anonymous/config.json', () => ( {
		ReadingListsMinervaCTAShowDot: showDot,
		default: { ReadingListsMinervaCTAShowDot: showDot }
	} ) );
	( { initializePulsatingDot } = await import(
		'../../../resources/ext.readingLists.bookmark.anonymous/pulsatingDot.js'
	) );
}

beforeEach( async () => {
	vi.resetModules();
	mw.storage.get.mockReturnValue( null );
	mw.log = vi.fn();
	mw.loader.using.mockReturnValue( Promise.resolve() );
	await requireModule( true );
} );

afterEach( () => {
	vi.restoreAllMocks();
	document.body.innerHTML = '';
} );

describe( 'initializePulsatingDot', () => {
	describe( 'when ReadingListsMinervaCTAShowDot is false', () => {
		test( 'returns resolved promise without loading module', async () => {
			vi.resetModules();
			await requireModule( false );
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
				expect( bookmarkWrapper.parentElement.classList.contains( 'mw-pulsating-dot' ) ).toBe( false );
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
