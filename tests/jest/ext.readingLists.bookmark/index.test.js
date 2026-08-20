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

function loadIndex() {
	require( '../../../resources/ext.readingLists.bookmark/index.js' );
}

beforeEach( () => {
	jest.resetModules();
	document.body.innerHTML = '';
	setSkin( 'vector-2022' );

	initBookmark = jest.fn();
	jest.doMock( '../../../resources/ext.readingLists.bookmark/bookmark.js', () => ( {
		initBookmark
	} ) );
} );

afterEach( () => {
	document.body.innerHTML = '';
} );

describe( 'ext.readingLists.bookmark index', () => {
	test( 'initializes the Vector bookmark', () => {
		const bookmark = createVectorBookmarkPortlet();

		loadIndex();

		expect( initBookmark ).toHaveBeenCalledWith( bookmark, false, 'toolbar' );
	} );

	test( 'initializes the Minerva bookmark', () => {
		setSkin( 'minerva' );
		const bookmark = createMinervaBookmarkElement();

		loadIndex();

		expect( initBookmark ).toHaveBeenCalledWith( bookmark, true, 'toolbar' );
	} );
} );
