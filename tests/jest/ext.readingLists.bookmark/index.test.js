let initBookmark;

function setSkin( skin ) {
	mw.config.get.mockImplementation( ( key ) => ( key === 'skin' ? skin : undefined ) );
}

function createVectorBookmarkPortlet() {
	const wrapper = document.createElement( 'li' );
	wrapper.id = 'ca-bookmark';

	const bookmark = document.createElement( 'a' );
	bookmark.className = 'reading-lists-bookmark';

	const icon = document.createElement( 'span' );
	icon.className = 'vector-icon';
	const label = document.createElement( 'span' );
	bookmark.append( icon, label );

	wrapper.appendChild( bookmark );
	document.body.appendChild( wrapper );

	return bookmark;
}

function createMinervaBookmarkElement() {
	const bookmark = document.createElement( 'a' );
	bookmark.id = 'ca-bookmark';
	document.body.appendChild( bookmark );
	return bookmark;
}

// Imported rather than required so the module passes through Vite's transform and
// shares one registry with this test, which is what lets the `vi.doMock()` below
// intercept its `require( './bookmark.js' )`.
async function loadIndex() {
	await import( '../../../resources/ext.readingLists.bookmark/index.js' );
}

beforeEach( () => {
	vi.resetModules();
	document.body.innerHTML = '';
	setSkin( 'vector-2022' );

	initBookmark = vi.fn();
	vi.doMock( '../../../resources/ext.readingLists.bookmark/bookmark.js', () => ( {
		initBookmark
	} ) );
} );

afterEach( () => {
	document.body.innerHTML = '';
} );

describe( 'ext.readingLists.bookmark index', () => {
	test( 'initializes the Vector bookmark', async () => {
		const bookmark = createVectorBookmarkPortlet();

		await loadIndex();

		expect( initBookmark ).toHaveBeenCalledWith( bookmark, false, 'toolbar' );
	} );

	test( 'initializes the Minerva bookmark', async () => {
		setSkin( 'minerva' );
		const bookmark = createMinervaBookmarkElement();

		await loadIndex();

		expect( initBookmark ).toHaveBeenCalledWith( bookmark, true, 'toolbar' );
	} );
} );
