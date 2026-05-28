let initBookmark;
let initOnboardingPopover;

function setConfigValues( overrides = {} ) {
	const configValues = {
		skin: 'vector-2022',
		wgAction: 'view',
		wgExtensionAssetsPath: '/extensions',
		wgIsMainPage: false,
		...overrides
	};

	mw.config.get.mockImplementation( ( key ) => configValues[ key ] );
}

function createVectorBookmarkPortlet( { saved = false } = {} ) {
	const wrapper = document.createElement( 'li' );
	wrapper.id = 'ca-bookmark';

	const bookmark = document.createElement( 'a' );
	bookmark.className = 'reading-lists-bookmark';

	const icon = document.createElement( 'span' );
	icon.className = 'vector-icon';
	const label = document.createElement( 'span' );
	bookmark.append( icon, label );

	if ( saved ) {
		bookmark.dataset.mwSaved = '1';
	}

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

function loadIndex( url = '/wiki/France' ) {
	window.history.pushState( {}, '', url );
	require( '../../../resources/ext.readingLists.bookmark/index.js' );
}

beforeEach( () => {
	jest.resetModules();
	document.body.innerHTML = '';
	window.history.pushState( {}, '', '/' );
	setConfigValues();

	initBookmark = jest.fn();
	initOnboardingPopover = jest.fn();
	jest.doMock( '../../../resources/ext.readingLists.bookmark/bookmark.js', () => ( {
		initBookmark,
		initOnboardingPopover
	} ) );
} );

afterEach( () => {
	document.body.innerHTML = '';
} );

describe( 'ext.readingLists.bookmark index', () => {
	test( 'shows onboarding on the current article read view', () => {
		const bookmark = createVectorBookmarkPortlet();

		loadIndex();

		expect( initBookmark ).toHaveBeenCalledWith( bookmark, false, 'toolbar' );
		expect( initOnboardingPopover ).toHaveBeenCalledWith(
			'#ca-bookmark',
			'readinglists-bookmark-dialog-seen',
			'readinglists-onboarding-title',
			'readinglists-onboarding-text',
			'/extensions/ReadingLists/resources/assets/onboarding-save.svg',
			'ext.readingLists.onboarding.desktop'
		);
	} );

	test( 'shows mobile onboarding on Minerva article read view', () => {
		setConfigValues( { skin: 'minerva' } );
		const bookmark = createMinervaBookmarkElement();

		loadIndex();

		expect( initBookmark ).toHaveBeenCalledWith( bookmark, true, 'toolbar' );
		expect( initOnboardingPopover ).toHaveBeenCalledWith(
			'#ca-bookmark',
			'readinglists-bookmark-dialog-seen',
			'readinglists-onboarding-title',
			'readinglists-onboarding-text',
			'/extensions/ReadingLists/resources/assets/onboarding-save.svg',
			'ext.readingLists.onboarding.mobile'
		);
	} );

	test.each( [
		[ 'main page', '/wiki/Main_Page', {}, { wgIsMainPage: true } ],
		[ 'edit action', '/w/index.php?title=France&action=edit', {}, { wgAction: 'edit' } ],
		[ 'submit action', '/w/index.php?title=France&action=submit', {}, { wgAction: 'submit' } ],
		[ 'history action', '/w/index.php?title=France&action=history', {}, { wgAction: 'history' } ],
		[ 'visual editor', '/w/index.php?title=France&veaction=edit' ],
		[ 'visual editor source mode', '/w/index.php?title=France&veaction=editsource' ],
		[ 'diff view', '/w/index.php?title=France&diff=123' ],
		[ 'old revision', '/w/index.php?title=France&oldid=123' ]
	] )( 'does not show onboarding on %s', ( _, url, bookmarkOptions = {}, configOverrides = {} ) => {
		const bookmark = createVectorBookmarkPortlet( bookmarkOptions );
		setConfigValues( configOverrides );

		loadIndex( url );

		expect( initBookmark ).toHaveBeenCalledWith( bookmark, false, 'toolbar' );
		expect( initOnboardingPopover ).not.toHaveBeenCalled();
	} );
} );
