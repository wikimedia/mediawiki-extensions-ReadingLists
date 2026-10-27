import { mount, flushPromises } from '@vue/test-utils';
import { createTestingPinia } from '@pinia/testing';
import { useReadingListsStore } from '../../../resources/ext.readingLists.special/store.js';

// Imported rather than required so the test and the component under test share
// the same module instance, which is what makes `api.stubApi()` take effect.
import api from '../../../resources/ext.readingLists.api/index.js';
import i18n from '../../../i18n/en.json';
import { nextTick } from 'vue';
// Resolves to tests/unit-js/vueRouterStub.js, the same stub instance Entries gets.
import { useRouter } from 'vue-router';

const ENTRIES_PATH = '../../../resources/ext.readingLists.special/pages/Entries.vue';
const NAVIGATION_BAR_PATH = '../../../resources/ext.readingLists.special/components/NavigationBar.vue';
const DELETE_DIALOG_PATH = '../../../resources/ext.readingLists.special/components/DeleteCollectionDialog.vue';

const LIST = require( '../fixtures/list.json' );
const ENTRIES = require( '../fixtures/entries.json' );
const ENTRIES2 = require( '../fixtures/entries2.json' );
const PAGES = require( '../fixtures/pages.json' );
const ALL_ENTRIES = require( '../fixtures/allentries.json' );
const ALL_PAGES = require( '../fixtures/allpages.json' );

function setupEntriesApiStub() {
	return api.stubApi( {
		get: vi.fn( ( { action, meta, rllist, list, rlelists, prop, rlecontinue } ) => {
			if ( action === 'query' ) {
				if ( meta === 'readinglists' && rllist === 12345 ) {
					return LIST;
				} else if ( list === 'readinglistentries' && rlelists === 12345 && !rlecontinue ) {
					return ENTRIES;
				} else if ( list === 'readinglistentries' && rlelists === 12345 && rlecontinue ) {
					return ENTRIES2;
				} else if ( prop !== undefined ) {
					return PAGES;
				}
			}
		} )
	} );
}

function setupAllItemsApiStub() {
	return api.stubApi( {
		get: vi.fn( ( { action, list, rlelists, prop } ) => {
			if ( action === 'query' ) {
				if ( list === 'readinglistentries' && rlelists === undefined ) {
					return ALL_ENTRIES;
				} else if ( prop !== undefined ) {
					return ALL_PAGES;
				}
			}
		} )
	} );
}

/**
 * Mount the Entries component with the pinia testing plugin.
 *
 * @param {Object} props
 * @param {Object} slots
 * @return {Object}
 */
async function mountEntries( props = { listId: 12345 }, slots = undefined ) {
	const Entries = ( await import( ENTRIES_PATH ) ).default;
	const options = {
		props,
		// By default, actions are stubbed into a no-op jest mock. We want the full, working actions
		// from the store for these tests.
		global: { plugins: [ createTestingPinia( { stubActions: false } ) ] }
	};
	if ( slots ) {
		options.slots = slots;
	}
	return mount( Entries, options );
}

describe( 'Entries', () => {
	beforeEach( () => {
		mw.config.get = vi.fn( ( key ) => {
			// Disable the beta survey.
			if ( key === 'wgReadingListsEnableBetaQuickSurvey' ) {
				return false;
			}
		} );
		mw.util.getUrl = vi.fn( ( path ) => `/wiki/${ path }` );
		mw.util.wikiUrlencode = vi.fn( ( path ) => path );
	} );

	afterEach( () => {
		vi.restoreAllMocks();
	} );

	describe( 'without custom lists', () => {
		test( 'renders with toolbar disabled', async () => {
			setupEntriesApiStub();

			const wrapper = await mountEntries();

			await flushPromises();

			expect( wrapper.element ).toMatchSnapshot();
		} );

		test( 'renders "Show more items" button', async () => {
			setupEntriesApiStub();

			const wrapper = await mountEntries();

			await flushPromises();

			const showMoreButton = wrapper.find( '.cdx-button' );
			expect( showMoreButton.exists() ).toBe( true );
			expect( showMoreButton.text() ).toBe( 'Show more items' );
		} );

		test( 'measures the mounted wrapper before rendering the list to load complete rows', async () => {
			setupEntriesApiStub();
			const getEntries = vi.spyOn( api, 'getEntries' );
			// A grid with 5 columns.
			const getComputedStyle = vi.spyOn( window, 'getComputedStyle' ).mockReturnValue( {
				gridTemplateColumns: '200px 200px 200px 200px 200px'
			} );

			const wrapper = await mountEntries();
			const grid = wrapper.get( '.reading-lists-items-wrapper' );

			expect( wrapper.vm.loadingInfo ).toBe( true );
			expect( grid.element.children ).toHaveLength( 0 );
			expect( wrapper.find( '.reading-lists-items' ).exists() ).toBe( false );
			expect( getComputedStyle ).toHaveBeenCalledWith( grid.element );

			await flushPromises();

			const limit = getEntries.mock.calls[ 0 ][ 3 ];
			expect( limit ).toBe( 15 );
			expect( wrapper.get( '.reading-lists-items-wrapper' ).element ).toBe( grid.element );
			expect( grid.get( '.reading-lists-items' ).attributes( 'aria-hidden' ) ).toBeUndefined();
			wrapper.unmount();
		} );

		test( 'renders the empty state without an empty list element', async () => {
			setupEntriesApiStub();
			vi.spyOn( api, 'getEntries' ).mockResolvedValue( { entries: [], next: null } );

			const wrapper = await mountEntries();
			await flushPromises();

			expect( wrapper.get( '.reading-lists-items-wrapper' ).element.children ).toHaveLength( 0 );
			expect( wrapper.find( '.reading-lists-items' ).exists() ).toBe( false );
			expect( wrapper.find( '.reading-lists-empty' ).exists() ).toBe( true );
			wrapper.unmount();
		} );

		test( 'loads 12 entries in mobile list view without measuring columns', async () => {
			vi.spyOn( window, 'innerWidth', 'get' ).mockReturnValue( 375 );
			setupEntriesApiStub();
			const getEntries = vi.spyOn( api, 'getEntries' );
			const getComputedStyle = vi.spyOn( window, 'getComputedStyle' );

			const wrapper = await mountEntries();

			await flushPromises();

			expect( getEntries.mock.calls[ 0 ][ 3 ] ).toBe( 12 );
			expect( getComputedStyle ).not.toHaveBeenCalledWith(
				wrapper.get( '.reading-lists-items-wrapper' ).element
			);
			expect( wrapper.get( '.reading-lists-items-wrapper' ).classes() )
				.not.toContain( 'reading-lists-items-wrapper--grid-view' );
			expect( wrapper.get( '.reading-lists-items' ).classes() )
				.not.toContain( 'reading-lists-items--grid-view' );
			wrapper.unmount();
		} );

		test( 'remeasures the grid when loading more entries after a column change', async () => {
			setupEntriesApiStub();
			const entries = Array.from( { length: 28 }, ( _, i ) => ( {
				id: i + 1,
				title: `Page ${ i + 1 }`,
				url: `/wiki/Page_${ i + 1 }`
			} ) );
			const getEntries = vi.spyOn( api, 'getEntries' )
				.mockResolvedValueOnce( { entries: entries.slice( 0, 15 ), next: 'next-page' } )
				.mockResolvedValueOnce( { entries: entries.slice( 15 ), next: null } );
			const getComputedStyle = vi.spyOn( window, 'getComputedStyle' ).mockReturnValue( {
				gridTemplateColumns: '200px 200px 200px 200px 200px'
			} );

			const wrapper = await mountEntries();
			await flushPromises();

			getComputedStyle.mockClear().mockReturnValue( {
				gridTemplateColumns: '250px 250px 250px 250px'
			} );
			await wrapper.get( '.cdx-button' ).trigger( 'click' );
			await flushPromises();

			expect( getComputedStyle ).toHaveBeenCalledWith(
				wrapper.get( '.reading-lists-items-wrapper' ).element
			);
			expect( getEntries ).toHaveBeenNthCalledWith(
				2, 12345, 'updated', 'descending', 13, 'next-page', [ '@local' ]
			);
			expect( wrapper.findAll( '.reading-lists-item' ) ).toHaveLength( 28 );
			wrapper.unmount();
		} );

		test( 'renders all items from all lists on special page', async () => {
			setupAllItemsApiStub();

			const wrapper = await mountEntries( {} );

			await flushPromises();

			expect( wrapper.vm.entries.length ).toBeGreaterThan( 0 );
			expect( wrapper.find( 'ul.reading-lists-items' ).attributes( 'aria-label' ) )
				.toBe( 'All items' );
			expect( wrapper.element ).toMatchSnapshot();
		} );

		test( 'renders import dialog slot content', async () => {
			const wrapper = await mountEntries(
				{ imported: {
					name: 'Imported list',
					description: '',
					default: false,
					list: []
				} },
				{ 'import-dialog': '<div class="mock-import-dialog"></div>' }
			);

			await flushPromises();
			await wrapper.vm.$nextTick();

			expect( wrapper.find( '.mock-import-dialog' ).exists() ).toBe( true );
		} );
	} );

	describe( 'with custom lists', () => {
		test( 'renders the nav bar when custom lists are enabled', async () => {
			setupAllItemsApiStub();

			const wrapper = await mountEntries( { isCustomListsEnabled: true } );

			await flushPromises();

			expect( wrapper.vm.loadingEntries ).toBe( false );
			expect( wrapper.vm.entries.length ).toBeGreaterThan( 0 );
			expect( wrapper.vm.showNavBar ).toBe( true );
			expect( wrapper.element ).toMatchSnapshot();
		} );

		test( 'nav bar only renders after content has finished loading', async () => {
			const wrapper = await mountEntries( { isCustomListsEnabled: true } );

			expect( wrapper.vm.loadingEntries ).toBe( true );
			expect( wrapper.vm.entries.length ).toBe( 0 );
			expect( wrapper.vm.showNavBar ).toBe( false );
			expect( wrapper.element ).toMatchSnapshot();
		} );

		test( 'nav bar does not disappear when show more is clicked', async () => {
			setupEntriesApiStub();

			const wrapper = await mountEntries( { listId: 12345, isCustomListsEnabled: true } );

			await flushPromises();

			const showMoreButton = wrapper.find( '.cdx-button' );
			await showMoreButton.trigger( 'click' );

			expect( wrapper.element ).toMatchSnapshot();
		} );
	} );

	describe( 'store state reset on re-render', () => {

		async function mountEntriesWithPinia( pinia, props = {} ) {
			const Entries = ( await import( ENTRIES_PATH ) ).default;
			return mount( Entries, {
				props,
				global: { plugins: [ pinia ] }
			} );
		}

		test( 'clears stale store data synchronously when mounted for a different view', async () => {
			// Prime the store with data that would be left behind by a previous
			// collection view, as if the pinia store persisted across routes.
			const pinia = createTestingPinia( {
				stubActions: false,
				initialState: {
					readingLists: {
						title: 'Previous Collection',
						description: 'Old description',
						isDefaultList: false,
						isAllListItems: false
					}
				}
			} );

			setupAllItemsApiStub();

			// Mount for the all-items view.
			const wrapper = await mountEntriesWithPinia( pinia );

			// resetState() is called synchronously in setup() so the stale title
			// is gone before the first render — it must never appear in the DOM.
			// (loadingEntries stays true because the entries fetch is still in flight.)
			expect( wrapper.vm.title ).toBe( '' );
			expect( wrapper.vm.loadingEntries ).toBe( true );
			expect( wrapper.find( '.reading-lists-title' ).exists() ).toBe( false );

			wrapper.unmount();
		} );

		test( 'does not flash a stale collection title when navigating to all items', async () => {
			const pinia = createTestingPinia( { stubActions: false } );
			const store = useReadingListsStore( pinia );

			// First view: a specific collection.
			setupEntriesApiStub();
			const collectionWrapper = await mountEntriesWithPinia( pinia, { listId: 12345 } );
			await flushPromises();

			expect( collectionWrapper.vm.title ).toBe( 'Example list' );
			expect( store.isDefaultList ).toBe( false );
			collectionWrapper.unmount();

			// Navigate to the all-items view. App.vue uses :key="$route.path" so
			// Entries is fully unmounted and remounted, re-running setup().
			setupAllItemsApiStub();
			const allItemsWrapper = await mountEntriesWithPinia( pinia );

			// resetState() has run synchronously in setup() — the stale title is
			// cleared before any async fetch starts, so it can never flicker in.
			// (For the all-items path fetchListInfo has no await so loadingInfo is
			// already false; loadingEntries confirms the entries fetch is in flight.)
			expect( allItemsWrapper.vm.title ).toBe( '' );
			expect( allItemsWrapper.vm.loadingEntries ).toBe( true );
			expect( allItemsWrapper.find( '.reading-lists-title' ).exists() ).toBe( false );

			await flushPromises();

			// After loading completes, the all-items view has no title.
			expect( allItemsWrapper.vm.title ).toBe( '' );
			expect( store.isAllListItems ).toBe( true );
			allItemsWrapper.unmount();
		} );

		test( 'resets isAllListItems when navigating from all items to a collection', async () => {
			const pinia = createTestingPinia( { stubActions: false } );
			const store = useReadingListsStore( pinia );

			// First view: all items.
			setupAllItemsApiStub();
			const allItemsWrapper = await mountEntriesWithPinia( pinia );
			await flushPromises();

			expect( store.isAllListItems ).toBe( true );
			allItemsWrapper.unmount();

			// Navigate to a specific collection.
			setupEntriesApiStub();
			const collectionWrapper = await mountEntriesWithPinia( pinia, { listId: 12345 } );

			// resetState() has run — isAllListItems is cleared before any fetch.
			expect( store.isAllListItems ).toBe( false );
			expect( store.isDefaultList ).toBe( true );
			expect( collectionWrapper.vm.loadingInfo ).toBe( true );

			await flushPromises();

			// After loading: the collection's metadata is populated.
			expect( collectionWrapper.vm.title ).toBe( 'Example list' );
			expect( store.isDefaultList ).toBe( false );
			expect( store.isAllListItems ).toBe( false );
			collectionWrapper.unmount();
		} );
	} );

	describe( 'deleting a collection', () => {
		let NavigationBar, DeleteCollectionDialog;

		beforeEach( async () => {
			mw.user.getName = vi.fn( () => 'testuser' );
			mw.errorLogger = { logError: vi.fn() };
			vi.spyOn( mw, 'msg' ).mockImplementation(
				( key, param ) => ( i18n[ key ] || key ).replace( '$1', () => param )
			);
			NavigationBar = ( await import( NAVIGATION_BAR_PATH ) ).default;
			DeleteCollectionDialog = ( await import( DELETE_DIALOG_PATH ) ).default;
		} );

		// Mounts the custom collection from the list fixture, with the nav bar rendered,
		// and opens the delete dialog the way the nav bar does.
		async function mountWithDialogOpen() {
			setupEntriesApiStub();
			const wrapper = await mountEntries( { listId: 12345, isCustomListsEnabled: true } );
			await flushPromises();

			wrapper.findComponent( NavigationBar ).vm.$emit( 'delete-collection' );
			await nextTick();
			return wrapper;
		}

		test( 'opens the dialog with the collection name when the nav bar asks for it', async () => {
			const wrapper = await mountWithDialogOpen();

			const dialog = wrapper.findComponent( DeleteCollectionDialog );
			expect( dialog.exists() ).toBe( true );
			expect( dialog.props( 'listName' ) ).toBe( 'Example list' );
			expect( dialog.props( 'isDeleting' ) ).toBe( false );
		} );

		test( 'closes on cancel and can be opened again', async () => {
			const wrapper = await mountWithDialogOpen();

			wrapper.findComponent( DeleteCollectionDialog ).vm.$emit( 'close' );
			await nextTick();
			expect( wrapper.findComponent( DeleteCollectionDialog ).exists() ).toBe( false );

			wrapper.findComponent( NavigationBar ).vm.$emit( 'delete-collection' );
			await nextTick();
			expect( wrapper.findComponent( DeleteCollectionDialog ).exists() ).toBe( true );
		} );

		test( 'deletes the list and goes to the collections view on mobile', async () => {
			vi.spyOn( window, 'innerWidth', 'get' ).mockReturnValue( 375 );
			const deleteList = vi.spyOn( api, 'deleteList' ).mockResolvedValue( {} );
			const push = vi.spyOn( useRouter(), 'push' );
			const wrapper = await mountWithDialogOpen();

			wrapper.findComponent( DeleteCollectionDialog ).vm.$emit( 'confirm' );
			await flushPromises();

			expect( deleteList ).toHaveBeenCalledWith( 12345 );
			expect( push ).toHaveBeenCalledWith( '/testuser/collections' );
			expect( mw.notify ).toHaveBeenCalledWith(
				'Collection "Example list" was deleted.',
				{ type: 'notice' }
			);
			expect( wrapper.findComponent( DeleteCollectionDialog ).exists() ).toBe( false );
		} );

		test( 'notifies with the deleted collection name after returning to all items', async () => {
			vi.spyOn( api, 'deleteList' ).mockResolvedValue( {} );
			let resolveNavigation;
			const push = vi.spyOn( useRouter(), 'push' ).mockReturnValue( new Promise( ( resolve ) => {
				resolveNavigation = resolve;
			} ) );
			const wrapper = await mountWithDialogOpen();

			wrapper.findComponent( DeleteCollectionDialog ).vm.$emit( 'confirm' );
			await flushPromises();

			expect( push ).toHaveBeenCalledWith( '/testuser' );
			expect( mw.notify ).not.toHaveBeenCalled();

			// Navigating remounts Entries and resets the shared list metadata.
			useReadingListsStore().resetState();
			resolveNavigation();
			await flushPromises();

			expect( mw.notify ).toHaveBeenCalledWith(
				'Collection "Example list" was deleted.',
				{ type: 'notice' }
			);
		} );

		test( 'keeps the dialog mounted while the request is pending', async () => {
			let resolveDelete;
			vi.spyOn( api, 'deleteList' ).mockReturnValue( new Promise( ( resolve ) => {
				resolveDelete = resolve;
			} ) );
			const wrapper = await mountWithDialogOpen();

			wrapper.findComponent( DeleteCollectionDialog ).vm.$emit( 'confirm' );
			await nextTick();
			expect( wrapper.findComponent( DeleteCollectionDialog ).props( 'isDeleting' ) ).toBe( true );
			expect( mw.notify ).not.toHaveBeenCalled();

			// Cancel, Escape and backdrop clicks all arrive as close events.
			wrapper.findComponent( DeleteCollectionDialog ).vm.$emit( 'close' );
			await nextTick();
			expect( wrapper.findComponent( DeleteCollectionDialog ).exists() ).toBe( true );

			resolveDelete( {} );
			await flushPromises();
			expect( wrapper.findComponent( DeleteCollectionDialog ).exists() ).toBe( false );
		} );

		test( 'shows a localized message when the request fails', async () => {
			vi.spyOn( api, 'deleteList' ).mockRejectedValue( 'http' );
			const push = vi.spyOn( useRouter(), 'push' );
			const wrapper = await mountWithDialogOpen();

			wrapper.findComponent( DeleteCollectionDialog ).vm.$emit( 'confirm' );
			await flushPromises();

			const message = wrapper.get( '.reading-lists-delete-error' ).text();
			expect( message ).toBe( i18n[ 'readinglists-customlists-delete-collection-error' ] );
			expect( message ).not.toContain( 'http' );
			expect( mw.errorLogger.logError ).toHaveBeenCalledWith(
				new Error( 'Failed to delete collection: http' ),
				'error.readinglists'
			);
			expect( push ).not.toHaveBeenCalled();
			expect( mw.notify ).not.toHaveBeenCalled();
			// The collection and its navigation stay on screen so the user can retry.
			expect( wrapper.findComponent( NavigationBar ).exists() ).toBe( true );
			expect( wrapper.findComponent( DeleteCollectionDialog ).exists() ).toBe( false );

			// Retrying clears the message while the new request runs.
			wrapper.findComponent( NavigationBar ).vm.$emit( 'delete-collection' );
			await nextTick();
			wrapper.findComponent( DeleteCollectionDialog ).vm.$emit( 'confirm' );
			await nextTick();
			expect( wrapper.find( '.reading-lists-delete-error' ).exists() ).toBe( false );
		} );

		test( 'shows the same message when the list was already deleted elsewhere', async () => {
			vi.spyOn( api, 'deleteList' ).mockRejectedValue( 'readinglists-db-error-list-deleted' );
			const wrapper = await mountWithDialogOpen();

			wrapper.findComponent( DeleteCollectionDialog ).vm.$emit( 'confirm' );
			await flushPromises();

			expect( wrapper.get( '.reading-lists-delete-error' ).text() )
				.toBe( i18n[ 'readinglists-customlists-delete-collection-error' ] );
			expect( wrapper.findComponent( NavigationBar ).exists() ).toBe( true );
		} );
	} );
} );
