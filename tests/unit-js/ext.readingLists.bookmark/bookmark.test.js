const CREATEENTRY = require( '../fixtures/createentry.json' );
const DELETEENTRY = require( '../fixtures/deleteentry.json' );

let api;
let confirmUnsaveFromCustomList;
let initBookmarkPopover;
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
 * @param {string} [options.saved]
 * @param {string} [options.inCustomList]
 * @return {HTMLAnchorElement}
 */
function createBookmarkElement( {
	iconClass = 'vector-icon',
	saved,
	inCustomList
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

	if ( saved !== undefined ) {
		bookmark.dataset.mwSaved = saved;
	}
	if ( inCustomList !== undefined ) {
		bookmark.dataset.mwInCustomList = inCustomList;
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
		get: vi.fn( ( key ) => DEFAULT_CONFIG_VALUES[ key ] )
	} );
	Object.assign( mw.user, {
		getName: vi.fn( () => 'TestUser' ),
		options: {
			get: vi.fn( () => 0 )
		}
	} );
	Object.assign( mw.util, {
		getUrl: vi.fn( ( title ) => `/wiki/${ title }` )
	} );
	Object.assign( mw.storage, {
		get: vi.fn( () => null ),
		session: {
			get: vi.fn( () => null ),
			remove: vi.fn()
		}
	} );
	Object.assign( mw.log, {
		error: vi.fn()
	} );
	Object.assign( mw, {
		msg: vi.fn( ( key ) => key ),
		hook: hookFn,
		requestIdleCallback: vi.fn( ( fn ) => fn() )
	} );
}

function setConfigValues( overrides ) {
	const configValues = {
		...DEFAULT_CONFIG_VALUES,
		...overrides
	};

	mw.config.get.mockImplementation( ( key ) => configValues[ key ] );
}

beforeEach( async () => {
	vi.resetModules();
	unhandledRejections = [];
	expectedUnhandledRejections = [];
	applyMwOverrides( { hookFn: createHookRegistry() } );
	confirmUnsaveFromCustomList = vi.fn();
	// The transform hoists bookmark.js's `require( 'ext.readingLists.bookmark.confirmPopover' )`
	// into a default import, mirroring the real module's `module.exports = { … }`
	// (CJS default interop), so the mock exposes the same shape under `default`.
	vi.doMock(
		'ext.readingLists.bookmark.confirmPopover',
		() => ( { default: { confirmUnsaveFromCustomList } } )
	);

	api = ( await import( '../../../resources/ext.readingLists.api/index.js' ) ).default;
	( { initBookmark, initOnboardingPopover } =
		await import( '../../../resources/ext.readingLists.bookmark/bookmark.js' ) );
} );

afterEach( () => {
	expect( unhandledRejections ).toStrictEqual( expectedUnhandledRejections );
	vi.useRealTimers();
	document.body.innerHTML = '';
} );

describe( 'initBookmark', () => {

	describe( 'initialization', () => {
		describe( 'Vector skin initBookmark', () => {
			test( 'sets outline icon and add bookmark label for unsaved page', () => {
				const bookmark = createBookmarkElement();
				initBookmark( bookmark, IS_NOT_MINERVA, VECTOR_EVENT_SOURCE );

				const icon = bookmark.querySelector( '.vector-icon' );
				expectIconClasses( icon, VECTOR_OUTLINE_BOOKMARK_CLASSES );
				expectIconClassesAbsent( icon, VECTOR_SOLID_BOOKMARK_CLASSES );

				expect( bookmark.lastElementChild.textContent ).toBe( 'readinglists-add-bookmark' );
			} );

			test( 'sets solid icon and remove bookmark label for saved page', () => {
				const bookmark = createBookmarkElement( {
					saved: '1'
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
					iconClass: 'minerva-icon'
				} );
				initBookmark( bookmark, IS_MINERVA, MINERVA_EVENT_SOURCE );

				const icon = bookmark.querySelector( '.minerva-icon' );
				expectIconClasses( icon, MINERVA_OUTLINE_BOOKMARK_CLASSES );
				expectIconClassesAbsent( icon, MINERVA_SOLID_BOOKMARK_CLASSES );
			} );

			test( 'sets solid icon and remove bookmark label for saved page', () => {
				const bookmark = createBookmarkElement( {
					iconClass: 'minerva-icon',
					saved: '1'
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
			test( 'calls createEntry, updates icon, fires hook', async () => {
				const hookCallback = vi.fn();
				const changeHookCallback = vi.fn();
				mw.hook( 'readingLists.bookmark.edit' ).add( hookCallback );
				mw.hook( 'readingLists.bookmark.change' ).add( changeHookCallback );
				const postWithEditToken = vi.fn( () => CREATEENTRY );

				const bookmark = createBookmarkElement();
				api.stubApi( {
					postWithEditToken
				} );

				mw.storage.get.mockReturnValue( ONBOARDING_ALREADY_SEEN );

				initBookmark( bookmark, IS_NOT_MINERVA, 'toolbar' );
				bookmark.click();
				await flushPromises();

				const icon = bookmark.querySelector( '.vector-icon' );
				expectIconClasses( icon, VECTOR_SOLID_BOOKMARK_CLASSES );
				expectIconClassesAbsent( icon, VECTOR_OUTLINE_BOOKMARK_CLASSES );
				expect( bookmark.dataset.mwSaved ).toBe( '1' );
				expect( hookCallback ).toHaveBeenCalledWith( true, null, null, 'toolbar' );
				expect( changeHookCallback ).toHaveBeenCalledWith( true, 'toolbar' );
				expect( postWithEditToken ).toHaveBeenCalledWith( expect.not.objectContaining( {
					list: expect.anything()
				} ) );
			} );

			describe( 'when onboarding popover has not been seen', () => {
				describe( 'and homepage discovery tour is active', () => {
					test( 'shows notify success', async () => {
						mw.user.options.get.mockRestore();
						vi.useFakeTimers();

						const anchor = document.createElement( 'div' );
						anchor.id = 'pt-readinglists-2';
						document.body.appendChild( anchor );

						const bookmark = createBookmarkElement();
						api.stubApi( {
							postWithEditToken: vi.fn( () => CREATEENTRY )
						} );

						mw.storage.get.mockReturnValue( null );
						mw.requestIdleCallback.mockImplementation( ( fn ) => fn() );

						initBookmark( bookmark, IS_NOT_MINERVA, VECTOR_EVENT_SOURCE );
						bookmark.click();
						await flushPromises();

						vi.advanceTimersByTime( 1000 );

						expect( mw.loader.using ).not.toHaveBeenCalledWith( 'ext.readingLists.onboarding.desktop' );

						expect( mw.notify ).toHaveBeenCalledWith(
							expect.anything(),
							expect.objectContaining( { tag: 'saved', type: 'success' } )
						);
					} );
				} );

				describe( 'and homepage discovery tour is not active', () => {
					test( 'triggers onboarding popover', async () => {
						mw.user.options.get.mockImplementation( ( name ) => (
							name === 'growthexperiments-tour-homepage-discovery' ? 1 : 0
						) );

						vi.useFakeTimers();

						const anchor = document.createElement( 'div' );
						anchor.id = 'pt-readinglists-2';
						document.body.appendChild( anchor );

						const bookmark = createBookmarkElement();
						api.stubApi( {
							postWithEditToken: vi.fn( () => CREATEENTRY )
						} );

						mw.storage.get.mockReturnValue( null );
						mw.requestIdleCallback.mockImplementation( ( fn ) => fn() );

						initBookmark( bookmark, IS_NOT_MINERVA, VECTOR_EVENT_SOURCE );
						bookmark.click();
						await flushPromises();

						vi.advanceTimersByTime( 1000 );

						expect( mw.loader.using ).toHaveBeenCalledWith( 'ext.readingLists.onboarding.desktop' );
					} );
				} );
			} );

			describe( 'when onboarding popover has been seen', () => {
				test( 'shows notify success', async () => {
					const bookmark = createBookmarkElement();
					api.stubApi( {
						postWithEditToken: vi.fn( () => CREATEENTRY )
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
		} );

		describe( 'Minerva skin bookmark button click to save', () => {
			test( 'calls createEntry, updates icon, fires hook', async () => {
				const hookCallback = vi.fn();
				const changeHookCallback = vi.fn();
				mw.hook( 'readingLists.bookmark.edit' ).add( hookCallback );
				mw.hook( 'readingLists.bookmark.change' ).add( changeHookCallback );
				const postWithEditToken = vi.fn( () => CREATEENTRY );

				const bookmark = createBookmarkElement( {
					iconClass: 'minerva-icon'
				} );
				api.stubApi( {
					postWithEditToken
				} );
				mw.storage.get.mockReturnValue( ONBOARDING_ALREADY_SEEN );

				initBookmark( bookmark, IS_MINERVA, MINERVA_EVENT_SOURCE );
				bookmark.click();
				await flushPromises();

				const icon = bookmark.querySelector( '.minerva-icon' );
				expectIconClasses( icon, MINERVA_SOLID_BOOKMARK_CLASSES );
				expectIconClassesAbsent( icon, MINERVA_OUTLINE_BOOKMARK_CLASSES );
				expect( bookmark.dataset.mwSaved ).toBe( '1' );
				expect( hookCallback ).toHaveBeenCalledWith(
					true, null, null, MINERVA_EVENT_SOURCE
				);
				expect( changeHookCallback ).toHaveBeenCalledWith( true, MINERVA_EVENT_SOURCE );
				expect( postWithEditToken ).toHaveBeenCalledWith( expect.not.objectContaining( {
					list: expect.anything()
				} ) );
			} );

			test( 'triggers onboarding popover on first save when not yet seen', async () => {
				mw.user.options.get.mockImplementation( ( name ) => (
					name === 'homepage_mobile_discovery_notice_seen' ? 1 : 0
				) );

				vi.useFakeTimers();

				const anchor = document.createElement( 'div' );
				anchor.className = 'minerva-user-menu';
				document.body.appendChild( anchor );

				const bookmark = createBookmarkElement( {
					iconClass: 'minerva-icon'
				} );
				api.stubApi( {
					postWithEditToken: vi.fn( () => CREATEENTRY )
				} );
				setConfigValues( { skin: 'minerva' } );
				mw.storage.get.mockReturnValue( null );
				mw.requestIdleCallback.mockImplementation( ( fn ) => fn() );

				initBookmark( bookmark, IS_MINERVA, MINERVA_EVENT_SOURCE );
				bookmark.click();
				await flushPromises();

				vi.advanceTimersByTime( 1000 );

				expect( mw.loader.using ).toHaveBeenCalledWith( 'ext.readingLists.onboarding.mobile' );
			} );

			test( 'shows notify success when onboarding already seen', async () => {
				const bookmark = createBookmarkElement( {
					iconClass: 'minerva-icon'
				} );
				api.stubApi( {
					postWithEditToken: vi.fn( () => CREATEENTRY )
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

	describe( 'when the user rapidly clicks the bookmark button twice', () => {
		test( 'ignores the second click while the first is still processing', async () => {
			const postWithEditToken = vi.fn( () => CREATEENTRY );
			const bookmark = createBookmarkElement();
			api.stubApi( { postWithEditToken } );
			mw.storage.get.mockReturnValue( ONBOARDING_ALREADY_SEEN );

			initBookmark( bookmark, IS_NOT_MINERVA, VECTOR_EVENT_SOURCE );
			bookmark.click();
			bookmark.click();

			await flushPromises();

			expect( postWithEditToken ).toHaveBeenCalledTimes( 1 );
		} );
	} );

	describe( 'after log in or account creation from the anonymous bookmark dialog', () => {
		const AUTOSAVE_STORAGE_KEY = 'readinglists-cta-autosave';

		function createMinervaBookmark( options ) {
			setConfigValues( { skin: 'minerva' } );

			const anchor = document.createElement( 'div' );
			anchor.className = 'minerva-user-menu';
			document.body.appendChild( anchor );

			return createBookmarkElement( { iconClass: 'minerva-icon', ...options } );
		}

		test( 'saves the page and shows onboarding', async () => {
			const postWithEditToken = vi.fn( () => CREATEENTRY );
			const bookmark = createMinervaBookmark();
			api.stubApi( { postWithEditToken } );
			mw.storage.session.get.mockReturnValue( 'Test_Page' );
			mw.user.options.get.mockImplementation( ( name ) => (
				name === 'homepage_mobile_discovery_notice_seen' ? 1 : 0
			) );

			initBookmark( bookmark, IS_MINERVA, MINERVA_EVENT_SOURCE );
			await flushPromises();

			expect( mw.storage.session.remove ).toHaveBeenCalledWith( AUTOSAVE_STORAGE_KEY );
			expect( postWithEditToken ).toHaveBeenCalledTimes( 1 );
			expect( postWithEditToken ).toHaveBeenCalledWith( expect.objectContaining( {
				command: 'createentry',
				title: 'Test_Page'
			} ) );
			expect( bookmark.dataset.mwSaved ).toBe( '1' );
			expect( mw.loader.using ).toHaveBeenCalledWith( 'ext.readingLists.onboarding.mobile' );
			expect( mw.notify ).not.toHaveBeenCalled();
		} );

		test( 'shows onboarding when the Growth discovery preference is missing', async () => {
			const bookmark = createMinervaBookmark();
			api.stubApi( { postWithEditToken: vi.fn( () => CREATEENTRY ) } );
			mw.storage.session.get.mockReturnValue( 'Test_Page' );
			mw.user.options.get.mockImplementation( ( _name, fallback = null ) => fallback );

			initBookmark( bookmark, IS_MINERVA, MINERVA_EVENT_SOURCE );
			await flushPromises();

			expect( mw.loader.using ).toHaveBeenCalledWith( 'ext.readingLists.onboarding.mobile' );
			expect( mw.notify ).not.toHaveBeenCalled();
		} );

		test( 'does not save when the page is already saved', async () => {
			const postWithEditToken = vi.fn( () => CREATEENTRY );
			const bookmark = createMinervaBookmark( { saved: '1' } );
			api.stubApi( { postWithEditToken } );
			mw.storage.session.get.mockReturnValue( 'Test_Page' );

			initBookmark( bookmark, IS_MINERVA, MINERVA_EVENT_SOURCE );
			await flushPromises();

			expect( mw.storage.session.remove ).toHaveBeenCalledWith( AUTOSAVE_STORAGE_KEY );
			expect( postWithEditToken ).not.toHaveBeenCalled();
			expect( mw.notify ).not.toHaveBeenCalled();
		} );

		test( 'does not save when the stored page name is for another page', async () => {
			const postWithEditToken = vi.fn( () => CREATEENTRY );
			const bookmark = createMinervaBookmark();
			api.stubApi( { postWithEditToken } );
			mw.storage.session.get.mockReturnValue( 'Other_Page' );

			initBookmark( bookmark, IS_MINERVA, MINERVA_EVENT_SOURCE );
			await flushPromises();

			expect( mw.storage.session.remove ).toHaveBeenCalledWith( AUTOSAVE_STORAGE_KEY );
			expect( postWithEditToken ).not.toHaveBeenCalled();
		} );

		test( 'does not save when there is no stored page name', async () => {
			const postWithEditToken = vi.fn( () => CREATEENTRY );
			const bookmark = createMinervaBookmark();
			api.stubApi( { postWithEditToken } );

			initBookmark( bookmark, IS_MINERVA, MINERVA_EVENT_SOURCE );
			await flushPromises();

			expect( mw.storage.session.remove ).not.toHaveBeenCalled();
			expect( postWithEditToken ).not.toHaveBeenCalled();
		} );

		test( 'logs the error and shows no notification when the save fails', async () => {
			const bookmark = createMinervaBookmark();
			api.stubApi( {
				postWithEditToken: vi.fn( () => Promise.reject( 'some-api-error' ) )
			} );
			mw.storage.session.get.mockReturnValue( 'Test_Page' );

			initBookmark( bookmark, IS_MINERVA, MINERVA_EVENT_SOURCE );
			await flushPromises();
			await flushPromises();

			expect( mw.log.error ).toHaveBeenCalledWith( expect.any( String ), 'some-api-error' );
			expect( mw.notify ).not.toHaveBeenCalled();
			expect( bookmark.dataset.mwSaved ).toBeUndefined();
		} );

	} );

	describe( 'click bookmark button to unsave page', () => {
		test( 'calls deleteEntryByPageTitle, updates icon, fires hook on Vector skin', async () => {
			const hookCallback = vi.fn();
			const changeHookCallback = vi.fn();
			mw.hook( 'readingLists.bookmark.edit' ).add( hookCallback );
			mw.hook( 'readingLists.bookmark.change' ).add( changeHookCallback );
			const postWithEditToken = vi.fn( () => DELETEENTRY );

			const bookmark = createBookmarkElement( {
				saved: '1'
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

			expect( bookmark.dataset.mwSaved ).toBeUndefined();
			expect( hookCallback ).toHaveBeenCalledWith( false, null, null, 'toolbar' );
			expect( changeHookCallback ).toHaveBeenCalledWith( false, 'toolbar' );
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

		test( 'calls deleteEntryByPageTitle, updates icon, fires hook on Minerva skin', async () => {
			const hookCallback = vi.fn();
			const changeHookCallback = vi.fn();
			mw.hook( 'readingLists.bookmark.edit' ).add( hookCallback );
			mw.hook( 'readingLists.bookmark.change' ).add( changeHookCallback );
			const postWithEditToken = vi.fn( () => DELETEENTRY );

			const bookmark = createBookmarkElement( {
				iconClass: 'minerva-icon',
				saved: '1'
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

			expect( bookmark.dataset.mwSaved ).toBeUndefined();
			expect( hookCallback ).toHaveBeenCalledWith( false, null, null, MINERVA_EVENT_SOURCE );
			expect( changeHookCallback ).toHaveBeenCalledWith( false, MINERVA_EVENT_SOURCE );
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
			const postWithEditToken = vi.fn( () => DELETEENTRY );
			const bookmark = createBookmarkElement( {
				saved: '1',
				inCustomList: '1'
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
			const postWithEditToken = vi.fn( () => DELETEENTRY );
			const bookmark = createBookmarkElement( {
				saved: '1',
				inCustomList: '1'
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
		} );
	} );

	describe( 'ReadingLists list setup flow', () => {
		test( 'saves to default list without calling setup on Vector skin', async () => {
			const postWithEditToken = vi.fn( ( { command } ) => {
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

			expect( postWithEditToken ).toHaveBeenCalledTimes( 1 );
			expect( postWithEditToken ).toHaveBeenCalledWith( expect.objectContaining( {
				command: 'createentry',
				project: '@local',
				title: 'Test_Page'
			} ) );
			expect( postWithEditToken ).toHaveBeenCalledWith( expect.not.objectContaining( {
				list: expect.anything()
			} ) );
		} );
	} );

	describe( 'when ReadingListsCustomLists is enabled', () => {
		beforeEach( async () => {
			vi.resetModules();
			vi.doMock( '../../../resources/config.json', () => ( {
				ReadingListsCustomLists: true,
				default: { ReadingListsCustomLists: true }
			} ) );
			initBookmarkPopover = vi.fn( () => ( { isSaved: true, showNotification: false } ) );
			// Default-export shape, as for the confirmPopover mock above. The transform
			// hoists bookmark.js's lazy require() to a top-level import, so the mock
			// delegates to the current `initBookmarkPopover`, which tests may reassign.
			vi.doMock(
				'ext.readingLists.bookmark.bookmarkPopover',
				() => ( { default: {
					initBookmarkPopover: ( ...args ) => initBookmarkPopover( ...args )
				} } )
			);
			api = ( await import( '../../../resources/ext.readingLists.api/index.js' ) ).default;
			( { initBookmark } =
				await import( '../../../resources/ext.readingLists.bookmark/bookmark.js' ) );
			// launchBookmarkPopover now calls api.getListsWithTitle before showing the popover.
			vi.spyOn( api, 'getListsWithTitle' ).mockResolvedValue( [] );
		} );

		afterEach( () => {
			vi.doUnmock( '../../../resources/config.json' );
			vi.doUnmock( 'ext.readingLists.bookmark.bookmarkPopover' );
		} );

		test( 'launches bookmark popover on save instead of mw.notify', async () => {
			const bookmark = createBookmarkElement();
			api.stubApi( {
				postWithEditToken: vi.fn( () => CREATEENTRY )
			} );
			mw.storage.get.mockReturnValue( ONBOARDING_ALREADY_SEEN );

			initBookmark( bookmark, IS_NOT_MINERVA, VECTOR_EVENT_SOURCE );
			bookmark.click();

			// 4 flushes for the save workflow:
			// - API call to save to list
			// - getListsWithTitle call
			// - mw.loader.using() to resolve
			// - Propagate resolved promises through addPageToReadingList()
			await flushPromises();
			await flushPromises();
			await flushPromises();
			await flushPromises();

			expect( initBookmarkPopover ).toHaveBeenCalledWith( false, [] );
			expect( mw.notify ).not.toHaveBeenCalledWith(
				expect.anything(),
				expect.objectContaining( { tag: 'saved', type: 'success' } )
			);
		} );

		test( 'shows mw.notify success when bookmarkPopover module fails to load', async () => {
			const bookmark = createBookmarkElement();
			api.stubApi( {
				postWithEditToken: vi.fn( () => CREATEENTRY )
			} );
			mw.storage.get.mockReturnValue( ONBOARDING_ALREADY_SEEN );
			mw.loader.using.mockRejectedValueOnce( new Error( 'module load failed' ) );

			initBookmark( bookmark, IS_NOT_MINERVA, VECTOR_EVENT_SOURCE );
			bookmark.click();
			await flushPromises();
			await flushPromises();
			await flushPromises();
			await flushPromises();

			expect( mw.notify ).toHaveBeenCalledWith(
				expect.anything(),
				expect.objectContaining( { tag: 'saved', type: 'success' } )
			);
		} );

		test( 'fires bookmark change hooks after save, before the bookmark popover resolves', async () => {
			const hookCallback = vi.fn();
			const changeHookCallback = vi.fn();
			mw.hook( 'readingLists.bookmark.edit' ).add( hookCallback );
			mw.hook( 'readingLists.bookmark.change' ).add( changeHookCallback );

			let resolvePopover;
			initBookmarkPopover = vi.fn( () => new Promise( ( resolve ) => {
				resolvePopover = resolve;
			} ) );

			const bookmark = createBookmarkElement();
			api.stubApi( {
				postWithEditToken: vi.fn( () => CREATEENTRY )
			} );
			mw.storage.get.mockReturnValue( ONBOARDING_ALREADY_SEEN );

			initBookmark( bookmark, IS_NOT_MINERVA, VECTOR_EVENT_SOURCE );
			bookmark.click();

			// One flush for the save API call, one for getListsWithTitle, one for
			// mw.loader.using().
			await flushPromises();
			await flushPromises();
			await flushPromises();

			// Hooks should have fired even though the popover has not yet resolved.
			expect( hookCallback ).toHaveBeenCalledWith( true, null, null, VECTOR_EVENT_SOURCE );
			expect( changeHookCallback ).toHaveBeenCalledWith( true, VECTOR_EVENT_SOURCE );
			expect( initBookmarkPopover ).toHaveBeenCalledWith( false, [] );

			// Resolve the popover so addPageToReadingList() completes cleanly.
			resolvePopover( { isSaved: true, showNotification: false } );
			await flushPromises();
			await flushPromises();
		} );

		describe( 'when onboarding popover has not been seen', () => {
			describe( 'and homepage discovery tour is active', () => {
				test( 'launches bookmark popover without triggering onboarding', async () => {
					mw.user.options.get.mockRestore();
					vi.useFakeTimers();

					const anchor = document.createElement( 'div' );
					anchor.id = 'pt-readinglists-2';
					document.body.appendChild( anchor );

					const bookmark = createBookmarkElement();
					api.stubApi( {
						postWithEditToken: vi.fn( () => CREATEENTRY )
					} );

					mw.storage.get.mockReturnValue( null );
					mw.requestIdleCallback.mockImplementation( ( fn ) => fn() );

					initBookmark( bookmark, IS_NOT_MINERVA, VECTOR_EVENT_SOURCE );
					bookmark.click();
					await flushPromises();
					await flushPromises();
					await flushPromises();
					await flushPromises();

					vi.advanceTimersByTime( 1000 );

					expect( initBookmarkPopover ).toHaveBeenCalledWith( false, [] );
					expect( mw.notify ).not.toHaveBeenCalledWith(
						expect.anything(),
						expect.objectContaining( { tag: 'saved', type: 'success' } )
					);
					expect( mw.loader.using ).not.toHaveBeenCalledWith( 'ext.readingLists.onboarding.desktop' );
				} );
			} );

			describe( 'and homepage discovery tour is not active', () => {
				test( 'triggers onboarding popover', async () => {
					mw.user.options.get.mockImplementation( ( name ) => (
						name === 'growthexperiments-tour-homepage-discovery' ? 1 : 0
					) );

					vi.useFakeTimers();

					const anchor = document.createElement( 'div' );
					anchor.id = 'pt-readinglists-2';
					document.body.appendChild( anchor );

					const bookmark = createBookmarkElement();
					api.stubApi( {
						postWithEditToken: vi.fn( () => CREATEENTRY )
					} );

					mw.storage.get.mockReturnValue( null );
					mw.requestIdleCallback.mockImplementation( ( fn ) => fn() );

					initBookmark( bookmark, IS_NOT_MINERVA, VECTOR_EVENT_SOURCE );
					bookmark.click();
					await flushPromises();
					await flushPromises();
					await flushPromises();
					await flushPromises();

					vi.advanceTimersByTime( 1000 );

					expect( initBookmarkPopover ).toHaveBeenCalledWith( false, [] );
					expect( mw.notify ).not.toHaveBeenCalledWith(
						expect.anything(),
						expect.objectContaining( { tag: 'saved', type: 'success' } )
					);
					expect( mw.loader.using ).toHaveBeenCalledWith( 'ext.readingLists.onboarding.desktop' );
				} );
			} );
		} );

		describe( 'when onboarding popover has been seen', () => {
			test( 'launches the bookmark popover without triggering onboarding', async () => {
				vi.useFakeTimers();
				const bookmark = createBookmarkElement();
				api.stubApi( {
					postWithEditToken: vi.fn( () => CREATEENTRY )
				} );
				mw.storage.get.mockReturnValue( ONBOARDING_ALREADY_SEEN );

				initBookmark( bookmark, IS_NOT_MINERVA, VECTOR_EVENT_SOURCE );
				bookmark.click();
				await flushPromises();
				await flushPromises();
				await flushPromises();
				await flushPromises();

				vi.advanceTimersByTime( 1000 );

				expect( initBookmarkPopover ).toHaveBeenCalledWith( false, [] );
				expect( mw.notify ).not.toHaveBeenCalledWith(
					expect.anything(),
					expect.objectContaining( { tag: 'saved', type: 'success' } )
				);
				expect( mw.loader.using ).not.toHaveBeenCalledWith( 'ext.readingLists.onboarding.desktop' );
			} );
		} );

		test( 'shows custom list notification when popover resolves with a listId', async () => {
			initBookmarkPopover = vi.fn( () => ( { isSaved: true, showNotification: true, listId: 42, listName: 'My List' } ) );

			const bookmark = createBookmarkElement();
			api.stubApi( {
				postWithEditToken: vi.fn( () => CREATEENTRY )
			} );
			mw.storage.get.mockReturnValue( ONBOARDING_ALREADY_SEEN );

			initBookmark( bookmark, IS_NOT_MINERVA, VECTOR_EVENT_SOURCE );
			bookmark.click();
			await flushPromises();
			await flushPromises();
			await flushPromises();
			await flushPromises();

			expect( mw.message ).toHaveBeenCalledWith(
				'readinglists-customlists-add-entry-success',
				expect.anything(),
				expect.anything(),
				expect.anything()
			);
			expect( mw.notify ).toHaveBeenCalledWith(
				expect.anything(),
				expect.objectContaining( { tag: 'saved', type: 'success' } )
			);
		} );

		test( 'shows default list notification when popover resolves without a listId', async () => {
			initBookmarkPopover = vi.fn( () => ( {
				isSaved: true,
				showNotification: true,
				listId: null,
				listName: null
			} ) );

			const bookmark = createBookmarkElement();
			api.stubApi( {
				postWithEditToken: vi.fn( () => CREATEENTRY )
			} );
			mw.storage.get.mockReturnValue( ONBOARDING_ALREADY_SEEN );

			initBookmark( bookmark, IS_NOT_MINERVA, VECTOR_EVENT_SOURCE );
			bookmark.click();
			await flushPromises();
			await flushPromises();
			await flushPromises();
			await flushPromises();

			expect( mw.message ).toHaveBeenCalledWith(
				'readinglists-browser-add-entry-success',
				expect.anything(),
				expect.anything(),
				expect.anything()
			);
			expect( mw.notify ).toHaveBeenCalledWith(
				expect.anything(),
				expect.objectContaining( { tag: 'saved', type: 'success' } )
			);
		} );

		describe( 'when a page is saved and the bookmark is clicked', () => {
			test( 'launches bookmark popover with isNewSave=true', async () => {
				initBookmarkPopover = vi.fn( () => ( { isSaved: true, showNotification: false } ) );

				const bookmark = createBookmarkElement( { saved: '1' } );
				mw.storage.get.mockReturnValue( ONBOARDING_ALREADY_SEEN );

				initBookmark( bookmark, IS_NOT_MINERVA, VECTOR_EVENT_SOURCE );
				bookmark.click();

				// 3 flushes for the configure flow:
				// - getListsWithTitle call
				// - mw.loader.using() to resolve
				// - Propagate resolved promises through configurePageInReadingList()
				await flushPromises();
				await flushPromises();
				await flushPromises();

				expect( initBookmarkPopover ).toHaveBeenCalledWith( true, [] );
			} );

			describe( 'and the page is unsaved from all lists', () => {
				test( 'updates the bookmark button to unsaved', async () => {
					initBookmarkPopover = vi.fn( () => ( {
						isSaved: false,
						showNotification: true
					} ) );

					const bookmark = createBookmarkElement( { saved: '1' } );
					mw.storage.get.mockReturnValue( ONBOARDING_ALREADY_SEEN );

					initBookmark( bookmark, IS_NOT_MINERVA, VECTOR_EVENT_SOURCE );
					bookmark.click();

					await flushPromises();
					await flushPromises();
					await flushPromises();

					expect( bookmark.dataset.mwSaved ).toBeUndefined();
					const icon = bookmark.querySelector( '.vector-icon' );
					expectIconClasses( icon, VECTOR_OUTLINE_BOOKMARK_CLASSES );
					expectIconClassesAbsent( icon, VECTOR_SOLID_BOOKMARK_CLASSES );
				} );

				test( 'fires bookmark change hooks', async () => {
					const hookCallback = vi.fn();
					const changeHookCallback = vi.fn();
					mw.hook( 'readingLists.bookmark.edit' ).add( hookCallback );
					mw.hook( 'readingLists.bookmark.change' ).add( changeHookCallback );
					initBookmarkPopover = vi.fn( () => ( {
						isSaved: false,
						showNotification: true
					} ) );

					const bookmark = createBookmarkElement( { saved: '1' } );
					mw.storage.get.mockReturnValue( ONBOARDING_ALREADY_SEEN );

					initBookmark( bookmark, IS_NOT_MINERVA, VECTOR_EVENT_SOURCE );
					bookmark.click();

					await flushPromises();
					await flushPromises();
					await flushPromises();

					expect( hookCallback ).toHaveBeenCalledWith(
						false, null, null, VECTOR_EVENT_SOURCE
					);
					expect( changeHookCallback ).toHaveBeenCalledWith( false, VECTOR_EVENT_SOURCE );
				} );

				test( 'shows unsave notice notification', async () => {
					initBookmarkPopover = vi.fn( () => ( {
						isSaved: false,
						showNotification: true
					} ) );

					const bookmark = createBookmarkElement( { saved: '1' } );
					mw.storage.get.mockReturnValue( ONBOARDING_ALREADY_SEEN );

					initBookmark( bookmark, IS_NOT_MINERVA, VECTOR_EVENT_SOURCE );
					bookmark.click();

					await flushPromises();
					await flushPromises();
					await flushPromises();

					expect( mw.message ).toHaveBeenCalledWith(
						'readinglists-browser-remove-entry-success',
						expect.anything(),
						expect.anything(),
						expect.anything()
					);
					expect( mw.notify ).toHaveBeenCalledWith(
						expect.anything(),
						expect.objectContaining( { tag: 'saved', type: 'notice' } )
					);
				} );
			} );

			describe( 'and the popover is dismissed without unsaving the page', () => {
				test( 'does not change the bookmark button when the popover resolves with isSaved:true', async () => {
					initBookmarkPopover = vi.fn( () => ( {
						isSaved: true,
						showNotification: false
					} ) );

					const bookmark = createBookmarkElement( { saved: '1' } );
					mw.storage.get.mockReturnValue( ONBOARDING_ALREADY_SEEN );

					initBookmark( bookmark, IS_NOT_MINERVA, VECTOR_EVENT_SOURCE );
					bookmark.click();

					await flushPromises();
					await flushPromises();
					await flushPromises();

					expect( bookmark.dataset.mwSaved ).toBe( '1' );
					const icon = bookmark.querySelector( '.vector-icon' );
					expectIconClasses( icon, VECTOR_SOLID_BOOKMARK_CLASSES );
					expectIconClassesAbsent( icon, VECTOR_OUTLINE_BOOKMARK_CLASSES );
				} );

				test( 'does not fire bookmark change hooks when the page remains saved', async () => {
					const hookCallback = vi.fn();
					const changeHookCallback = vi.fn();
					mw.hook( 'readingLists.bookmark.edit' ).add( hookCallback );
					mw.hook( 'readingLists.bookmark.change' ).add( changeHookCallback );
					initBookmarkPopover = vi.fn( () => ( {
						isSaved: true,
						showNotification: false
					} ) );

					const bookmark = createBookmarkElement( { saved: '1' } );
					mw.storage.get.mockReturnValue( ONBOARDING_ALREADY_SEEN );

					initBookmark( bookmark, IS_NOT_MINERVA, VECTOR_EVENT_SOURCE );
					bookmark.click();

					await flushPromises();
					await flushPromises();
					await flushPromises();

					expect( hookCallback ).not.toHaveBeenCalled();
					expect( changeHookCallback ).not.toHaveBeenCalled();
				} );
			} );

			describe( 'and the page is saved to a new collection', () => {
				test( 'shows success notification when the page is moved to a collection', async () => {
					initBookmarkPopover = vi.fn( () => ( {
						isSaved: true,
						showNotification: true,
						listId: 42,
						listName: 'My List'
					} ) );

					const bookmark = createBookmarkElement( { saved: '1' } );
					mw.storage.get.mockReturnValue( ONBOARDING_ALREADY_SEEN );

					initBookmark( bookmark, IS_NOT_MINERVA, VECTOR_EVENT_SOURCE );
					bookmark.click();

					await flushPromises();
					await flushPromises();
					await flushPromises();

					expect( mw.message ).toHaveBeenCalledWith(
						'readinglists-customlists-add-entry-success',
						expect.anything(),
						expect.anything(),
						expect.anything()
					);
					expect( mw.notify ).toHaveBeenCalledWith(
						expect.anything(),
						expect.objectContaining( { tag: 'saved', type: 'success' } )
					);
				} );
			} );

			test( 'shows mw.notify when bookmarkPopover module fails to load', async () => {
				const bookmark = createBookmarkElement( { saved: '1' } );
				mw.storage.get.mockReturnValue( ONBOARDING_ALREADY_SEEN );
				mw.loader.using.mockRejectedValueOnce( new Error( 'module load failed' ) );

				initBookmark( bookmark, IS_NOT_MINERVA, VECTOR_EVENT_SOURCE );
				bookmark.click();

				await flushPromises();
				await flushPromises();
				await flushPromises();

				expect( mw.notify ).toHaveBeenCalledWith(
					expect.anything(),
					expect.objectContaining( { tag: 'saved', type: 'success' } )
				);
			} );
		} );
	} );

	describe( 'error handling', () => {
		test( 'shows error notification when createEntry rejects on Vector skin', async () => {
			const bookmark = createBookmarkElement();
			expectedUnhandledRejections = [ 'some-api-error' ];
			const postWithEditToken = vi.fn( ( { command } ) => {
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
				saved: '1'
			} );
			expectedUnhandledRejections = [ 'unexpected-error' ];
			const postWithEditToken = vi.fn( ( { command } ) => {
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

	test( 'loads onboarding popover module immediately', () => {
		const anchor = document.createElement( 'div' );
		anchor.id = 'test-anchor';
		document.body.appendChild( anchor );

		mw.storage.get.mockReturnValue( null );

		initOnboardingPopover(
			'#test-anchor',
			'test-storage-key',
			'title-key',
			'body-key',
			'/path/to/banner.svg',
			'ext.readingLists.onboarding.desktop'
		);

		expect( mw.loader.using ).toHaveBeenCalledWith( 'ext.readingLists.onboarding.desktop' );
	} );
} );
