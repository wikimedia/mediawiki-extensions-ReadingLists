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
		mw.util = { getUrl: jest.fn( ( path ) => `/wiki/${ path }` ) };
		mw.user = { getName: jest.fn( () => 'testuser' ) };
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

	it( 'should populate the dropdown on click', async () => {
		setupApiStub();

		const NavigationBar = require( '../../../resources/ext.readingLists.special/components/NavigationBar.vue' );
		const wrapper = mount( NavigationBar, { props: { isAllItems: true, showDropdown: true } } );

		const dropdown = wrapper.find( 'button' );
		dropdown.trigger( 'click' );

		await flushPromises();

		expect( wrapper.element ).toMatchSnapshot();
	} );
} );
