const CREATEENTRY = require( '../fixtures/createentry.json' );
const DELETEENTRY = require( '../fixtures/deleteentry.json' );
const SETUP = require( '../fixtures/setup.json' );

let api;
let confirmUnsaveFromCustomList;
let initBookmark;
let initOnboardingPopover;
let unhandledRejections;
let expectedUnhandledRejections;

const VECTOR_EVENT_SOURCE = 'vector-test-event-source';
const MINERVA_EVENT_SOURCE = 'minerva-test-event-source';

const IS_MINERVA = true;
const IS_NOT_MINERVA = false;

const ONBOARDING_ALREADY_SEEN = 'true';
const DEFAULT_CONFIG_VALUES = {
	wgPageName: 'Test_Page',
	wgTitle: 'Test Page',
	skin: 'vector-2022',
	wgExtensionAssetsPath: '/extensions'
};

async function flushPromises() {
	await Promise.resolve();
}

/**
 * @param {HTMLElement} element
 */
function catchAsyncClicks( element ) {
	const origAddEventListener = element.addEventListener.bind( element );
	element.addEventListener = ( type, handler, ...rest ) => {
		if ( type === 'click' ) {
			origAddEventListener( type, ( event ) => {
				Promise.resolve( handler( event ) ).catch( ( err ) => {
					unhandledRejections.push( err );
				} );
			}, ...rest );
		} else {
			origAddEventListener( type, handler, ...rest );
		}
	};
}

/**
 * Creates a bookmark <a> element with icon and label spans.
 *
 * @param {Object} options
 * @param {string} [options.iconClass]
 * @param {number} [options.entryId]
 * @param {string} [options.inCustomList]
 * @param {number} [options.listId]
 * @param {number} [options.listPageCount]
 * @return {HTMLAnchorElement}
 */
function createBookmarkElement( {
	iconClass = 'vector-icon',
	entryId,
	inCustomList,
	listId,
	listPageCount
} = {} ) {
	const bookmark = document.createElement( 'a' );
	const icon = document.createElement( 'span' );
	const label = document.createElement( 'span' );

	// The following CSS classes are used here:
	// * vector-icon
	// * minerva-icon
	icon.classList.add( iconClass );
	bookmark.appendChild( icon );
	bookmark.appendChild( label );

	if ( entryId !== undefined ) {
		bookmark.dataset.mwEntryId = entryId;
	}
	if ( inCustomList !== undefined ) {
		bookmark.dataset.mwInCustomList = inCustomList;
	}
	if ( listId !== undefined ) {
		bookmark.dataset.mwListId = listId;
	}
	if ( listPageCount !== undefined ) {
		bookmark.dataset.mwListPageCount = listPageCount;
	}

	catchAsyncClicks( bookmark );
	document.body.appendChild( bookmark );
	return bookmark;
}

const VECTOR_SOLID_BOOKMARK_CLASSES = [
	'mw-ui-icon-bookmark',
	'mw-ui-icon-wikimedia-bookmark'
];
const VECTOR_OUTLINE_BOOKMARK_CLASSES = [
	'mw-ui-icon-bookmarkOutline',
	'mw-ui-icon-wikimedia-bookmarkOutline'
];
const MINERVA_SOLID_BOOKMARK_CLASSES = [ 'minerva-icon--bookmark' ];
const MINERVA_OUTLINE_BOOKMARK_CLASSES = [ 'minerva-icon--bookmarkOutline' ];

function expectIconClasses( icon, expectedClasses ) {
	expectedClasses.forEach( ( className ) => {
		expect( icon.classList.contains( className ) ).toBe( true );
	} );
}

function expectIconClassesAbsent( icon, unexpectedClasses ) {
	unexpectedClasses.forEach( ( className ) => {
		expect( icon.classList.contains( className ) ).toBe( false );
	} );
}

/**
 * @return {Function}
 */
function createHookRegistry() {
	const hooks = {};
	const getHandlers = ( name ) => hooks[ name ] || ( hooks[ name ] = [] );

	return ( name ) => ( {
		add( fn ) {
			getHandlers( name ).push( fn );
		},
		fire( ...args ) {
			getHandlers( name ).forEach( ( fn ) => fn( ...args ) );
		}
	} );
}

/**
 * Apply the `mw` overrides needed by bookmark tests.
 *
 * @param {Object} options
 * @param {Function} options.hookFn Hook registry implementation used by the tests.
 */
function applyMwOverrides( { hookFn } ) {
	Object.assign( mw.config, {
		get: jest.fn( ( key ) => DEFAULT_CONFIG_VALUES[ key ] )
	} );
	Object.assign( mw.user, {
		getName: jest.fn( () => 'TestUser' ),
		options: {
			get: jest.fn( () => 0 )
		}
	} );
	Object.assign( mw.util, {
		getUrl: jest.fn( ( title ) => `/wiki/${ title }` )
	} );
	Object.assign( mw.storage, {
		get: jest.fn( () => null )
	} );
	Object.assign( mw, {
		msg: jest.fn( ( key ) => key ),
		hook: hookFn,
		requestIdleCallback: jest.fn( ( fn ) => fn() )
	} );
}

function setConfigValues( overrides ) {
	const configValues = {
		...DEFAULT_CONFIG_VALUES,
		...overrides
	};

	mw.config.get.mockImplementation( ( key ) => configValues[ key ] );
}

beforeEach( () => {
	jest.resetModules();
	unhandledRejections = [];
	expectedUnhandledRejections = [];
	applyMwOverrides( { hookFn: createHookRegistry() } );
	confirmUnsaveFromCustomList = jest.fn();
	jest.doMock(
		'ext.readingLists.bookmark.confirmPopover',
		() => ( { confirmUnsaveFromCustomList } ),
		{ virtual: true }
	);

	api = require( '../../../resources/ext.readingLists.api/index.js' );
	( { initBookmark, initOnboardingPopover } =
		require( '../../../resources/ext.readingLists.bookmark/bookmark.js' ) );
} );

afterEach( () => {
	expect( unhandledRejections ).toStrictEqual( expectedUnhandledRejections );
	jest.useRealTimers();
	document.body.innerHTML = '';
} );

describe( 'initBookmark', () => {

	describe( 'initialization', () => {
		describe( 'Vector skin initBookmark', () => {
			test( 'sets outline icon and add bookmark label for unsaved page', () => {
				const bookmark = createBookmarkElement( { listId: 1, listPageCount: 5 } );
				initBookmark( bookmark, IS_NOT_MINERVA, VECTOR_EVENT_SOURCE );

				const icon = bookmark.querySelector( '.vector-icon' );
				expectIconClasses( icon, VECTOR_OUTLINE_BOOKMARK_CLASSES );
				expectIconClassesAbsent( icon, VECTOR_SOLID_BOOKMARK_CLASSES );

				expect( bookmark.lastElementChild.textContent ).toBe( 'readinglists-add-bookmark' );
			} );

			test( 'sets solid icon and remove bookmark label for saved page', () => {
				const bookmark = createBookmarkElement( {
					entryId: 99,
					listId: 1,
					listPageCount: 5
				} );
				initBookmark( bookmark, IS_NOT_MINERVA, VECTOR_EVENT_SOURCE );

				const icon = bookmark.querySelector( '.vector-icon' );
				expectIconClasses( icon, VECTOR_SOLID_BOOKMARK_CLASSES );
				expectIconClassesAbsent( icon, VECTOR_OUTLINE_BOOKMARK_CLASSES );

				expect( bookmark.lastElementChild.textContent ).toBe( 'readinglists-remove-bookmark' );
			} );
		} );

		describe( 'Minerva skin initBookmark', () => {
			test( 'uses Minerva icon classes', () => {
				const bookmark = createBookmarkElement( {
					iconClass: 'minerva-icon',
					listId: 1,
					listPageCount: 5
				} );
				initBookmark( bookmark, IS_MINERVA, MINERVA_EVENT_SOURCE );

				const icon = bookmark.querySelector( '.minerva-icon' );
				expectIconClasses( icon, MINERVA_OUTLINE_BOOKMARK_CLASSES );
				expectIconClassesAbsent( icon, MINERVA_SOLID_BOOKMARK_CLASSES );
			} );

			test( 'sets solid icon and remove bookmark label for saved page', () => {
				const bookmark = createBookmarkElement( {
					iconClass: 'minerva-icon',
					entryId: 99,
					listId: 1,
					listPageCount: 5
				} );
				initBookmark( bookmark, IS_MINERVA, MINERVA_EVENT_SOURCE );

				const icon = bookmark.querySelector( '.minerva-icon' );
				expectIconClasses( icon, MINERVA_SOLID_BOOKMARK_CLASSES );
				expectIconClassesAbsent( icon, MINERVA_OUTLINE_BOOKMARK_CLASSES );
				expect( bookmark.lastElementChild.textContent ).toBe( 'readinglists-remove-bookmark' );
			} );
		} );

	} );

	describe( 'click bookmark button to save page', () => {
		describe( 'Vector skin bookmark click to save', () => {
			test( 'calls createEntry, updates icon, sets entryId, fires hook', async () => {
				const hookCallback = jest.fn();
				mw.hook( 'readingLists.bookmark.edit' ).add( hookCallback );

				const bookmark = createBookmarkElement( { listId: 12345, listPageCount: 3 } );
				api.stubApi( {
					postWithEditToken: jest.fn( () => CREATEENTRY )
				} );

				mw.storage.get.mockReturnValue( ONBOARDING_ALREADY_SEEN );

				initBookmark( bookmark, IS_NOT_MINERVA, 'toolbar' );
				bookmark.click();
				await flushPromises();

				const icon = bookmark.querySelector( '.vector-icon' );
				expectIconClasses( icon, VECTOR_SOLID_BOOKMARK_CLASSES );
				expectIconClassesAbsent( icon, VECTOR_OUTLINE_BOOKMARK_CLASSES );
				expect( bookmark.dataset.mwEntryId ).toBe( '54321' );
				expect( hookCallback ).toHaveBeenCalledWith( true, 54321, 4, 'toolbar' );
			} );

			test( 'triggers onboarding popover on first save when not yet seen', async () => {
				mw.user.options.get.mockImplementation( ( name ) => (
					name === 'growthexperiments-tour-homepage-discovery' ? 1 : 0
				) );

				jest.useFakeTimers();

				const anchor = document.createElement( 'div' );
				anchor.id = 'pt-readinglists-2';
				document.body.appendChild( anchor );

				const bookmark = createBookmarkElement( { listId: 12345, listPageCount: 3 } );
				api.stubApi( {
					postWithEditToken: jest.fn( () => CREATEENTRY )
				} );

				mw.storage.get.mockReturnValue( null );
				mw.requestIdleCallback.mockImplementation( ( fn ) => fn() );

				initBookmark( bookmark, IS_NOT_MINERVA, VECTOR_EVENT_SOURCE );
				bookmark.click();
				await flushPromises();

				jest.advanceTimersByTime( 1000 );

				expect( mw.loader.using ).toHaveBeenCalledWith( 'ext.readingLists.onboarding.desktop' );
			} );

			test( 'shows notify success when onboarding already seen', async () => {
				const bookmark = createBookmarkElement( { listId: 12345, listPageCount: 3 } );
				api.stubApi( {
					postWithEditToken: jest.fn( () => CREATEENTRY )
				} );
				mw.storage.get.mockReturnValue( ONBOARDING_ALREADY_SEEN );

				initBookmark( bookmark, IS_NOT_MINERVA, VECTOR_EVENT_SOURCE );
				bookmark.click();
				await flushPromises();

				expect( mw.notify ).toHaveBeenCalledWith(
					expect.anything(),
					expect.objectContaining( { tag: 'saved', type: 'success' } )
				);
			} );
		} );

		describe( 'Minerva skin bookmark button click to save', () => {
			test( 'calls createEntry, updates icon, sets entryId, fires hook', async () => {
				const hookCallback = jest.fn();
				mw.hook( 'readingLists.bookmark.edit' ).add( hookCallback );

				const bookmark = createBookmarkElement( {
					iconClass: 'minerva-icon',
					listId: 12345,
					listPageCount: 3
				} );
				api.stubApi( {
					postWithEditToken: jest.fn( () => CREATEENTRY )
				} );
				mw.storage.get.mockReturnValue( ONBOARDING_ALREADY_SEEN );

				initBookmark( bookmark, IS_MINERVA, MINERVA_EVENT_SOURCE );
				bookmark.click();
				await flushPromises();

				const icon = bookmark.querySelector( '.minerva-icon' );
				expectIconClasses( icon, MINERVA_SOLID_BOOKMARK_CLASSES );
				expectIconClassesAbsent( icon, MINERVA_OUTLINE_BOOKMARK_CLASSES );
				expect( bookmark.dataset.mwEntryId ).toBe( '54321' );
				expect( hookCallback ).toHaveBeenCalledWith( true, 54321, 4, MINERVA_EVENT_SOURCE );
			} );

			test( 'triggers onboarding popover on first save when not yet seen', async () => {
				mw.user.options.get.mockImplementation( ( name ) => (
					name === 'growthexperiments-tour-homepage-discovery' ? 1 : 0
				) );

				jest.useFakeTimers();

				const anchor = document.createElement( 'div' );
				anchor.className = 'minerva-user-menu';
				document.body.appendChild( anchor );

				const bookmark = createBookmarkElement( {
					iconClass: 'minerva-icon',
					listId: 12345,
					listPageCount: 3
				} );
				api.stubApi( {
					postWithEditToken: jest.fn( () => CREATEENTRY )
				} );
				setConfigValues( { skin: 'minerva' } );
				mw.storage.get.mockReturnValue( null );
				mw.requestIdleCallback.mockImplementation( ( fn ) => fn() );

				initBookmark( bookmark, IS_MINERVA, MINERVA_EVENT_SOURCE );
				bookmark.click();
				await flushPromises();

				jest.advanceTimersByTime( 1000 );

				expect( mw.loader.using ).toHaveBeenCalledWith( 'ext.readingLists.onboarding.mobile' );
			} );

			test( 'shows notify success when onboarding already seen', async () => {
				const bookmark = createBookmarkElement( {
					iconClass: 'minerva-icon',
					listId: 12345,
					listPageCount: 3
				} );
				api.stubApi( {
					postWithEditToken: jest.fn( () => CREATEENTRY )
				} );
				mw.storage.get.mockReturnValue( ONBOARDING_ALREADY_SEEN );

				initBookmark( bookmark, IS_MINERVA, MINERVA_EVENT_SOURCE );
				bookmark.click();
				await flushPromises();

				expect( mw.notify ).toHaveBeenCalledWith(
					expect.anything(),
					expect.objectContaining( { tag: 'saved', type: 'success' } )
				);
			} );
		} );
	} );

	describe( 'click bookmark button to unsave page', () => {
		test( 'calls deleteEntryByPageTitle, updates icon, removes entryId, fires hook on Vector skin', async () => {
			const hookCallback = jest.fn();
			mw.hook( 'readingLists.bookmark.edit' ).add( hookCallback );
			const postWithEditToken = jest.fn( () => DELETEENTRY );

			const bookmark = createBookmarkElement( {
				entryId: 99,
				listId: 12345,
				listPageCount: 5
			} );
			api.stubApi( {
				postWithEditToken
			} );
			mw.storage.get.mockReturnValue( ONBOARDING_ALREADY_SEEN );

			initBookmark( bookmark, IS_NOT_MINERVA, 'toolbar' );
			bookmark.click();
			await flushPromises();

			const icon = bookmark.querySelector( '.vector-icon' );

			expectIconClasses( icon, VECTOR_OUTLINE_BOOKMARK_CLASSES );
			expectIconClassesAbsent( icon, VECTOR_SOLID_BOOKMARK_CLASSES );

			expect( bookmark.dataset.mwEntryId ).toBeUndefined();
			expect( hookCallback ).toHaveBeenCalledWith( false, null, 4, 'toolbar' );
			expect( postWithEditToken ).toHaveBeenCalledWith( expect.objectContaining( {
				action: 'readinglists',
				command: 'deleteentry',
				project: '@local',
				title: 'Test_Page'
			} ) );
			expect( mw.notify ).toHaveBeenCalledWith(
				expect.anything(),
				expect.objectContaining( { tag: 'saved', type: 'notice' } )
			);
		} );

		test( 'calls deleteEntryByPageTitle, updates icon, removes entryId, fires hook on Minerva skin', async () => {
			const hookCallback = jest.fn();
			mw.hook( 'readingLists.bookmark.edit' ).add( hookCallback );
			const postWithEditToken = jest.fn( () => DELETEENTRY );

			const bookmark = createBookmarkElement( {
				iconClass: 'minerva-icon',
				entryId: 99,
				listId: 12345,
				listPageCount: 5
			} );
			api.stubApi( {
				postWithEditToken
			} );
			mw.storage.get.mockReturnValue( ONBOARDING_ALREADY_SEEN );

			initBookmark( bookmark, IS_MINERVA, MINERVA_EVENT_SOURCE );
			bookmark.click();
			await flushPromises();

			const icon = bookmark.querySelector( '.minerva-icon' );

			expectIconClasses( icon, MINERVA_OUTLINE_BOOKMARK_CLASSES );
			expectIconClassesAbsent( icon, MINERVA_SOLID_BOOKMARK_CLASSES );

			expect( bookmark.dataset.mwEntryId ).toBeUndefined();
			expect( hookCallback ).toHaveBeenCalledWith( false, null, 4, MINERVA_EVENT_SOURCE );
			expect( postWithEditToken ).toHaveBeenCalledWith( expect.objectContaining( {
				action: 'readinglists',
				command: 'deleteentry',
				project: '@local',
				title: 'Test_Page'
			} ) );
			expect( mw.notify ).toHaveBeenCalledWith(
				expect.anything(),
				expect.objectContaining( { tag: 'saved', type: 'notice' } )
			);
		} );

		test( 'loads confirm popover before removing page from a custom list', async () => {
			const postWithEditToken = jest.fn( () => DELETEENTRY );
			const bookmark = createBookmarkElement( {
				entryId: 99,
				inCustomList: '1',
				listId: 12345,
				listPageCount: 5
			} );
			api.stubApi( {
				postWithEditToken
			} );
			confirmUnsaveFromCustomList.mockResolvedValue( true );

			initBookmark( bookmark, IS_NOT_MINERVA, VECTOR_EVENT_SOURCE );
			bookmark.click();

			// Two flushes needed:
			// - one for the confirm popover promise,
			// - one for the deleteEntry call that follows confirmation.
			await flushPromises();
			await flushPromises();

			expect( confirmUnsaveFromCustomList ).toHaveBeenCalled();
			expect( postWithEditToken ).toHaveBeenCalledWith( expect.objectContaining( {
				command: 'deleteentry'
			} ) );
		} );

		test( 'does not remove page when custom list confirmation is cancelled', async () => {
			const postWithEditToken = jest.fn( () => DELETEENTRY );
			const bookmark = createBookmarkElement( {
				entryId: 99,
				inCustomList: '1',
				listId: 12345,
				listPageCount: 5
			} );
			api.stubApi( {
				postWithEditToken
			} );
			confirmUnsaveFromCustomList.mockResolvedValue( false );

			initBookmark( bookmark, IS_NOT_MINERVA, VECTOR_EVENT_SOURCE );
			bookmark.click();
			await flushPromises();

			expect( confirmUnsaveFromCustomList ).toHaveBeenCalled();
			expect( postWithEditToken ).not.toHaveBeenCalled();
			expect( bookmark.dataset.mwEntryId ).toBe( '99' );
		} );
	} );

	describe( 'ReadingLists list setup flow', () => {
		test( 'calls api.setup() first when no listId, then calls createEntry on Vector skin', async () => {
			const postWithEditToken = jest.fn( ( { command } ) => {
				if ( command === 'setup' ) {
					return SETUP;
				}
				if ( command === 'createentry' ) {
					return CREATEENTRY;
				}
			} );

			const bookmark = createBookmarkElement();
			api.stubApi( { postWithEditToken } );
			mw.storage.get.mockReturnValue( ONBOARDING_ALREADY_SEEN );

			initBookmark( bookmark, IS_NOT_MINERVA, VECTOR_EVENT_SOURCE );
			bookmark.click();
			await flushPromises();

			expect( postWithEditToken ).toHaveBeenNthCalledWith(
				1,
				expect.objectContaining( { command: 'setup' } )
			);
			expect( postWithEditToken ).toHaveBeenNthCalledWith(
				2,
				expect.objectContaining( { command: 'createentry' } )
			);
			expect( bookmark.dataset.mwListId ).toBe( '1' );
		} );
	} );

	describe( 'error handling', () => {
		test( 'shows error notification when createEntry rejects on Vector skin', async () => {
			const bookmark = createBookmarkElement( { listId: 12345, listPageCount: 3 } );
			expectedUnhandledRejections = [ 'some-api-error' ];
			const postWithEditToken = jest.fn( ( { command } ) => {
				if ( command === 'createentry' ) {
					// eslint-disable-next-line no-throw-literal
					throw 'some-api-error';
				}
			} );
			api.stubApi( {
				postWithEditToken
			} );

			initBookmark( bookmark, IS_NOT_MINERVA, VECTOR_EVENT_SOURCE );
			bookmark.click();
			await flushPromises();

			expect( postWithEditToken ).toHaveBeenCalledWith( expect.objectContaining( {
				command: 'createentry'
			} ) );
			expect( mw.notify ).toHaveBeenCalledWith(
				expect.anything(),
				expect.objectContaining( { tag: 'saved', type: 'error' } )
			);
		} );

		test( 'shows error notification when deleteEntryByPageTitle rejects with unexpected error on Vector skin', async () => {
			const bookmark = createBookmarkElement( {
				entryId: 99,
				listId: 12345,
				listPageCount: 5
			} );
			expectedUnhandledRejections = [ 'unexpected-error' ];
			const postWithEditToken = jest.fn( ( { command } ) => {
				if ( command === 'deleteentry' ) {
					// eslint-disable-next-line no-throw-literal
					throw 'unexpected-error';
				}
			} );
			api.stubApi( {
				postWithEditToken
			} );

			initBookmark( bookmark, IS_NOT_MINERVA, VECTOR_EVENT_SOURCE );
			bookmark.click();
			await flushPromises();

			expect( postWithEditToken ).toHaveBeenCalledWith( expect.objectContaining( {
				command: 'deleteentry'
			} ) );
			expect( mw.notify ).toHaveBeenCalledWith(
				expect.anything(),
				expect.objectContaining( { tag: 'saved', type: 'error' } )
			);
		} );
	} );
} );

describe( 'initOnboardingPopover', () => {
	beforeEach( () => {
		// in most cases, we want to assume the homepage popup has already been seen
		mw.user.options.get.mockImplementation( ( name ) => (
			name === 'growthexperiments-tour-homepage-discovery' ? 1 : 0
		) );
	} );

	test( 'returns early if anchor element not found in DOM', () => {
		initOnboardingPopover(
			'#nonexistent-element',
			'test-storage-key',
			'title-key',
			'body-key',
			null,
			'ext.readingLists.onboarding.mobile'
		);

		expect( mw.storage.get ).not.toHaveBeenCalled();
		expect( mw.loader.using ).not.toHaveBeenCalled();
	} );

	test( 'returns early if storage key already set', () => {
		const anchor = document.createElement( 'div' );
		anchor.id = 'test-anchor';
		document.body.appendChild( anchor );

		mw.storage.get.mockReturnValue( ONBOARDING_ALREADY_SEEN );

		initOnboardingPopover(
			'#test-anchor',
			'test-storage-key',
			'title-key',
			'body-key',
			null,
			'ext.readingLists.onboarding.mobile'
		);

		expect( mw.storage.get ).toHaveBeenCalledWith( 'test-storage-key' );
		expect( mw.loader.using ).not.toHaveBeenCalled();
	} );

	test( 'loads onboarding popover module after timer and idle callback', () => {
		jest.useFakeTimers();

		const anchor = document.createElement( 'div' );
		anchor.id = 'test-anchor';
		document.body.appendChild( anchor );

		mw.storage.get.mockReturnValue( null );

		// Keep the idle callback synchronous in this test so that, after the
		// 1000ms timer fires, the code proceeds to mw.loader.using().
		mw.requestIdleCallback.mockImplementation( ( fn ) => fn() );

		initOnboardingPopover(
			'#test-anchor',
			'test-storage-key',
			'title-key',
			'body-key',
			'/path/to/banner.svg',
			'ext.readingLists.onboarding.desktop'
		);

		expect( mw.loader.using ).not.toHaveBeenCalled();

		jest.advanceTimersByTime( 1000 );

		expect( mw.requestIdleCallback ).toHaveBeenCalledWith(
			expect.any( Function ),
			{ timeout: 2000 }
		);
		expect( mw.loader.using ).toHaveBeenCalledWith( 'ext.readingLists.onboarding.desktop' );
	} );

	test( 'defers loading onboarding popover if the homepage tour hasn\'t been seen yet', () => {
		jest.useFakeTimers();

		mw.user.options.get.mockRestore();

		const anchor = document.createElement( 'div' );
		anchor.id = 'test-anchor';
		document.body.appendChild( anchor );

		initOnboardingPopover(
			'#test-anchor',
			'test-storage-key',
			'title-key',
			'body-key',
			null,
			'ext.readingLists.onboarding.mobile'
		);

		jest.advanceTimersByTime( 1000 );

		expect( mw.user.options.get ).toHaveBeenCalledWith( 'growthexperiments-tour-homepage-discovery' );
		expect( mw.loader.using ).not.toHaveBeenCalled();
	} );
} );
