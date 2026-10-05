const { mount, flushPromises } = require( '@vue/test-utils' );
const api = require( '../../../resources/ext.readingLists.api/index.js' );

const LIST = require( '../fixtures/list.json' );
const ENTRIES = require( '../fixtures/entries.json' );
const ENTRIES2 = require( '../fixtures/entries2.json' );
const PAGES = require( '../fixtures/pages.json' );
const ALL_ENTRIES = require( '../fixtures/allentries.json' );
const ALL_PAGES = require( '../fixtures/allpages.json' );

function setupEntriesApiStub() {
	return api.stubApi( {
		get: jest.fn( ( { action, meta, rllist, list, rlelists, prop, rlecontinue } ) => {
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
		mw.config.get = jest.fn( ( key ) => {
			// Disable the beta survey.
			if ( key === 'wgReadingListsEnableBetaQuickSurvey' ) {
				return false;
			}
		} );
		mw.util.getUrl = jest.fn( ( path ) => `/wiki/${ path }` );
	} );

	afterEach( () => {
		jest.restoreAllMocks();
	} );

	describe( 'without custom lists', () => {
		test( 'renders with toolbar disabled', async () => {
			setupEntriesApiStub();

			const Entries = require( '../../../resources/ext.readingLists.special/pages/Entries.vue' );
			const wrapper = mount( Entries, { props: { listId: 12345 } } );

			await flushPromises();

			expect( wrapper.element ).toMatchSnapshot();
		} );

		test( 'renders "Show more items" button', async () => {
			setupEntriesApiStub();

			const Entries = require( '../../../resources/ext.readingLists.special/pages/Entries.vue' );
			const wrapper = mount( Entries, { props: { listId: 12345 } } );

			await flushPromises();

			const showMoreButton = wrapper.find( '.cdx-button' );
			expect( showMoreButton.exists() ).toBe( true );
			expect( showMoreButton.text() ).toBe( 'Show more items' );
		} );

		test( 'measures the mounted wrapper before rendering the list to load complete rows', async () => {
			setupEntriesApiStub();
			const getEntries = jest.spyOn( api, 'getEntries' );
			// A grid with 5 columns.
			const getComputedStyle = jest.spyOn( window, 'getComputedStyle' ).mockReturnValue( {
				gridTemplateColumns: '200px 200px 200px 200px 200px'
			} );

			const Entries = require( '../../../resources/ext.readingLists.special/pages/Entries.vue' );
			const wrapper = mount( Entries, { props: { listId: 12345 } } );
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
			jest.spyOn( api, 'getEntries' ).mockResolvedValue( { entries: [], next: null } );

			const Entries = require( '../../../resources/ext.readingLists.special/pages/Entries.vue' );
			const wrapper = mount( Entries, { props: { listId: 12345 } } );
			await flushPromises();

			expect( wrapper.get( '.reading-lists-items-wrapper' ).element.children ).toHaveLength( 0 );
			expect( wrapper.find( '.reading-lists-items' ).exists() ).toBe( false );
			expect( wrapper.find( '.reading-lists-empty' ).exists() ).toBe( true );
			wrapper.unmount();
		} );

		test( 'loads 12 entries in mobile list view without measuring columns', async () => {
			jest.replaceProperty( window, 'innerWidth', 375 );
			setupEntriesApiStub();
			const getEntries = jest.spyOn( api, 'getEntries' );
			const getComputedStyle = jest.spyOn( window, 'getComputedStyle' );

			const Entries = require( '../../../resources/ext.readingLists.special/pages/Entries.vue' );
			const wrapper = mount( Entries, { props: { listId: 12345 } } );

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
			const getEntries = jest.spyOn( api, 'getEntries' )
				.mockResolvedValueOnce( { entries: entries.slice( 0, 15 ), next: 'next-page' } )
				.mockResolvedValueOnce( { entries: entries.slice( 15 ), next: null } );
			const getComputedStyle = jest.spyOn( window, 'getComputedStyle' ).mockReturnValue( {
				gridTemplateColumns: '200px 200px 200px 200px 200px'
			} );

			const Entries = require( '../../../resources/ext.readingLists.special/pages/Entries.vue' );
			const wrapper = mount( Entries, { props: { listId: 12345 } } );
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

			const Entries = require( '../../../resources/ext.readingLists.special/pages/Entries.vue' );
			const wrapper = mount( Entries );

			await flushPromises();

			expect( wrapper.vm.isAllListItems ).toBe( true );
			expect( wrapper.vm.isDefaultList ).toBe( false );
			expect( wrapper.vm.entries.length ).toBeGreaterThan( 0 );
			expect( wrapper.find( 'ul.reading-lists-items' ).attributes( 'aria-label' ) )
				.toBe( 'All items' );
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

	describe( 'with custom lists', () => {
		test( 'renders the nav bar when custom lists are enabled', async () => {
			setupAllItemsApiStub();

			const Entries = require( '../../../resources/ext.readingLists.special/pages/Entries.vue' );
			const wrapper = mount( Entries, { props: { isCustomListsEnabled: true } } );

			await flushPromises();

			expect( wrapper.vm.loadingEntries ).toBe( false );
			expect( wrapper.vm.entries.length ).toBeGreaterThan( 0 );
			expect( wrapper.vm.showNavBar ).toBe( true );
			expect( wrapper.element ).toMatchSnapshot();
		} );

		test( 'nav bar only renders after content has finished loading', async () => {
			const Entries = require( '../../../resources/ext.readingLists.special/pages/Entries.vue' );
			const wrapper = mount( Entries, { props: { isCustomListsEnabled: true } } );

			expect( wrapper.vm.loadingEntries ).toBe( true );
			expect( wrapper.vm.entries.length ).toBe( 0 );
			expect( wrapper.vm.showNavBar ).toBe( false );
			expect( wrapper.element ).toMatchSnapshot();
		} );

		test( 'nav bar does not disappear when show more is clicked', async () => {
			setupEntriesApiStub();

			const Entries = require( '../../../resources/ext.readingLists.special/pages/Entries.vue' );
			const wrapper = mount(
				Entries,
				{ props: { listId: 12345, isCustomListsEnabled: true } }
			);

			await flushPromises();

			const showMoreButton = wrapper.find( '.cdx-button' );
			await showMoreButton.trigger( 'click' );

			expect( wrapper.element ).toMatchSnapshot();
		} );
	} );
} );
