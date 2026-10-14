const { mount, flushPromises } = require( '@vue/test-utils' );

const api = require( '../../../resources/ext.readingLists.api/index.js' );

describe( 'NavigationBar', () => {
	beforeEach( () => {
		mw.util.getUrl = jest.fn( ( path ) => `/wiki/${ path }` );
		mw.util.wikiUrlencode = jest.fn( ( path ) => path );
		mw.user.getName = jest.fn( () => 'testuser' );
		jest.spyOn( api, 'getListsAll' ).mockResolvedValue( [
			{ id: 42, name: 'My Collection', default: false }
		] );
	} );

	afterEach( () => {
		jest.restoreAllMocks();
	} );

	it.each( [
		[ 'Collection view on mobile', false, false, false ],
		[ 'Collection view on desktop', false, false, true ],
		[ 'Collections view on mobile', false, true, false ],
		[ 'Collections view on desktop', false, true, true ],
		[ 'All items view on mobile', true, false, false ],
		[ 'All items view on desktop', true, false, true ]
	] )( 'renders %s', async ( _, isAllItems, isCollections, showDropdown ) => {
		const NavigationBar = require( '../../../resources/ext.readingLists.special/components/NavigationBar.vue' );
		const wrapper = mount(
			NavigationBar,
			{ props: { isAllItems, isCollections, showDropdown } }
		);

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
