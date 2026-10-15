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
		[ 'Collection view on mobile', true ],
		[ 'Collection view on desktop', true ],
		[ 'Collections view on mobile', true ],
		[ 'Collections view on desktop', true ],
		[ 'All items view on mobile', false ],
		[ 'All items view on desktop', false ]
	] )( 'renders %s', async ( _, isCustomList ) => {
		const NavigationBar = require( '../../../resources/ext.readingLists.special/components/NavigationBar.vue' );
		const wrapper = mount(
			NavigationBar,
			{ props: { isCustomList } }
		);

		expect( wrapper.element ).toMatchSnapshot();
	} );

	it( 'shows the settings button only on a custom collection', async () => {
		const NavigationBar = require( '../../../resources/ext.readingLists.special/components/NavigationBar.vue' );

		const allItems = mount( NavigationBar, {
			props: { isCustomList: false }
		} );
		await flushPromises();
		expect( allItems.find( '.readinglists-nav-bar-settings-button' ).exists() ).toBe( false );

		const customList = mount( NavigationBar, {
			props: { isCustomList: true }
		} );
		await flushPromises();
		const button = customList.find( '.readinglists-nav-bar-settings-button' );
		expect( button.exists() ).toBe( true );
		expect( button.attributes( 'aria-label' ) ).toBe( 'Collection settings' );
	} );
} );
