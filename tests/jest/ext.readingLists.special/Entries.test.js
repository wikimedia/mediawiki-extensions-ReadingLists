const { mount, flushPromises } = require( '@vue/test-utils' );
const api = require( '../../../resources/ext.readingLists.api/index.js' );

const LIST = require( '../fixtures/list.json' );
const ENTRIES = require( '../fixtures/entries.json' );
const PAGES = require( '../fixtures/pages.json' );
const ALL_ENTRIES = require( '../fixtures/allentries.json' );
const ALL_PAGES = require( '../fixtures/allpages.json' );

function setupEntriesApiStub() {
	return api.stubApi( {
		get: jest.fn( ( { action, meta, rllist, list, rlelists, prop } ) => {
			if ( action === 'query' ) {
				if ( meta === 'readinglists' && rllist === 12345 ) {
					return LIST;
				} else if ( list === 'readinglistentries' && rlelists === 12345 ) {
					return ENTRIES;
				} else if ( prop !== undefined ) {
					return PAGES;
				}
			}
		} )
	} );
}

function setupAllItemsApiStub() {
	return api.stubApi( {
		get: jest.fn( ( { action, list, rlelists, prop } ) => {
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

describe( 'Entries', () => {
	beforeEach( () => {
		mw.config = {
			get: jest.fn( ( key ) => {
				// Disable the beta survey.
				if ( key === 'wgReadingListsEnableBetaQuickSurvey' ) {
					return false;
				}
			} )
		};
		mw.storage = {
			get: jest.fn()
		};
		mw.util = {
			throttle: ( fn ) => fn,
			getUrl: jest.fn( ( path ) => `/wiki/${ path }` )
		};
	} );

	afterEach( () => {
		jest.restoreAllMocks();
	} );

	describe( 'without custom lists', () => {
		test( 'renders with toolbar disabled', async () => {
			setupEntriesApiStub();

			const Entries = require( '../../../resources/ext.readingLists.special/pages/Entries.vue' );
			const wrapper = mount( Entries, { props: { listId: 12345 } } );

			await wrapper.vm.$forceUpdate();
			await wrapper.vm.$nextTick();

			expect( wrapper.element ).toMatchSnapshot();
		} );

		test( 'renders all items from all lists on special page', async () => {
			setupAllItemsApiStub();

			const Entries = require( '../../../resources/ext.readingLists.special/pages/Entries.vue' );
			const wrapper = mount( Entries );

			await flushPromises();

			expect( wrapper.vm.isAllListItems ).toBe( true );
			expect( wrapper.vm.isDefaultList ).toBe( false );
			expect( wrapper.vm.entries.length ).toBeGreaterThan( 0 );
			expect( wrapper.element ).toMatchSnapshot();
		} );

		test( 'renders import dialog slot content', async () => {
			const Entries = require( '../../../resources/ext.readingLists.special/pages/Entries.vue' );
			const wrapper = mount( Entries, {
				props: {
					imported: {
						name: 'Imported list',
						description: '',
						default: false,
						list: []
					}
				},
				slots: {
					'import-dialog': '<div class="mock-import-dialog"></div>'
				}
			} );

			await flushPromises();
			await wrapper.vm.$nextTick();

			expect( wrapper.find( '.mock-import-dialog' ).exists() ).toBe( true );
		} );
	} );

	describe( 'infinite scroll', () => {
		// A faithful stand-in for mw.util.throttle: it always defers through
		// setTimeout and offers no way to cancel a pending call. The default
		// stub above runs the handler synchronously, which cannot reproduce a
		// trailing call arriving after the listener was removed.
		function deferringThrottle( fn, wait ) {
			let timeout = null;
			return () => {
				if ( !timeout ) {
					timeout = setTimeout( () => {
						timeout = null;
						fn();
					}, wait );
				}
			};
		}

		afterEach( () => {
			jest.useRealTimers();
		} );

		test( 'trailing throttled call after unmount does not throw', async () => {
			jest.useFakeTimers();
			setupEntriesApiStub();
			mw.util.throttle = deferringThrottle;

			const Entries = require( '../../../resources/ext.readingLists.special/pages/Entries.vue' );
			const wrapper = mount( Entries, { props: { listId: 12345 } } );

			await flushPromises();

			// Put the component in the state the scroll handler acts on, so the
			// handler would reach $refs.container were it not guarded.
			wrapper.vm.error = '';
			wrapper.vm.loadingInfo = false;
			wrapper.vm.loadingEntries = false;
			wrapper.vm.infinite = true;
			wrapper.vm.next = 'continue-token';

			// Schedule a throttled call, then unmount before it can run.
			document.dispatchEvent( new Event( 'scroll' ) );
			wrapper.unmount();

			expect( wrapper.vm.throttledScroll ).toBe( null );
			expect( () => jest.advanceTimersByTime( 300 ) ).not.toThrow();
		} );
	} );

	describe( 'with custom lists', () => {
		beforeEach( () => {
			jest.resetModules();

			jest.mock(
				'../../../resources/ext.readingLists.special/config.json',
				() => ( {
					ReadingListsCustomLists: true
				} ),
				{ virtual: true }
			);
		} );

		test( 'renders the nav bar when custom lists are enabled', async () => {
			const Entries = require( '../../../resources/ext.readingLists.special/pages/Entries.vue' );
			const wrapper = mount( Entries );

			expect( wrapper.element ).toMatchSnapshot();
		} );
	} );
} );
