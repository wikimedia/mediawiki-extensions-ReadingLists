const { mount, flushPromises } = require( '@vue/test-utils' );

const api = require( '../../../resources/ext.readingLists.api/index.js' );

const LISTS = require( '../fixtures/lists.json' );

function setupApiStub() {
	return api.stubApi( {
		get: jest.fn( ( { action, meta } ) => {
			if ( action === 'query' ) {
				if ( meta === 'readinglists' ) {
					return LISTS;
				}
			}
		} )
	} );
}

describe( 'NavigationBar', () => {
	beforeEach( () => {
		mw.util.getUrl = jest.fn( ( path ) => `/wiki/${ path }` );
		mw.user.getName = jest.fn( () => 'testuser' );
		setupApiStub();
	} );

	afterEach( () => {
		jest.restoreAllMocks();
	} );

	it.each( [
		[ 'Collections view on mobile', false, false ],
		[ 'Collections view on desktop', false, true ],
		[ 'All items view on mobile', true, false ],
		[ 'All items view on desktop', true, true ]
	] )( 'renders %s', async ( _, isAllItems, showDropdown ) => {
		const NavigationBar = require( '../../../resources/ext.readingLists.special/components/NavigationBar.vue' );
		const wrapper = mount( NavigationBar, { props: { isAllItems, showDropdown } } );

		expect( wrapper.element ).toMatchSnapshot();
	} );

	it( 'shows the settings button only on a custom collection', async () => {
		const NavigationBar = require( '../../../resources/ext.readingLists.special/components/NavigationBar.vue' );

		const allItems = mount( NavigationBar, {
			props: { isAllItems: true, isCustomList: false }
		} );
		await flushPromises();
		expect( allItems.find( '.readinglists-nav-bar-settings-button' ).exists() ).toBe( false );

		const customList = mount( NavigationBar, {
			props: { isAllItems: false, isCustomList: true }
		} );
		await flushPromises();
		const button = customList.find( '.readinglists-nav-bar-settings-button' );
		expect( button.exists() ).toBe( true );
		expect( button.attributes( 'aria-label' ) ).toBe( 'Collection settings' );
	} );
} );
