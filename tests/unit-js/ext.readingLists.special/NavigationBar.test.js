import { mount, flushPromises } from '@vue/test-utils';

// Imported rather than required so the test and the component under test share
// the same module instance, which is what makes `api.stubApi()` take effect.
import api from '../../../resources/ext.readingLists.api/index.js';

const NAVIGATION_BAR_PATH = '../../../resources/ext.readingLists.special/components/NavigationBar.vue';

describe( 'NavigationBar', () => {
	beforeEach( () => {
		mw.util.getUrl = vi.fn( ( path ) => `/wiki/${ path }` );
		mw.util.wikiUrlencode = vi.fn( ( path ) => path );
		mw.user.getName = vi.fn( () => 'testuser' );
		vi.spyOn( api, 'getListsAll' ).mockResolvedValue( [
			{ id: 42, name: 'My Collection', default: false }
		] );
	} );

	afterEach( () => {
		vi.restoreAllMocks();
	} );

	it.each( [
		[ 'Collection view on mobile', true ],
		[ 'Collection view on desktop', true ],
		[ 'Collections view on mobile', true ],
		[ 'Collections view on desktop', true ],
		[ 'All items view on mobile', false ],
		[ 'All items view on desktop', false ]
	] )( 'renders %s', async ( _, isCustomList ) => {
		const NavigationBar = ( await import( NAVIGATION_BAR_PATH ) ).default;
		const wrapper = mount(
			NavigationBar,
			{ props: { isCustomList } }
		);

		expect( wrapper.element ).toMatchSnapshot();
	} );

	it( 'shows the settings button only on a custom collection', async () => {
		const NavigationBar = ( await import( NAVIGATION_BAR_PATH ) ).default;

		const allItems = mount( NavigationBar, {
			props: { isCustomList: false }
		} );
		await flushPromises();
		expect( allItems.find( '.reading-lists-nav-settings-button' ).exists() ).toBe( false );

		const customList = mount( NavigationBar, {
			props: { isCustomList: true }
		} );
		await flushPromises();
		const button = customList.find( '.reading-lists-nav-settings-button .cdx-button' );
		expect( button.exists() ).toBe( true );
		expect( button.attributes( 'aria-label' ) ).toBe( 'Collection settings' );
	} );

	it( 'emits delete-collection and closes the settings popover when Delete is clicked', async () => {
		const NavigationBar = ( await import( NAVIGATION_BAR_PATH ) ).default;
		const wrapper = mount( NavigationBar, {
			props: { isCustomList: true }
		} );
		await flushPromises();

		await wrapper.get( '.reading-lists-nav-settings-button' ).trigger( 'click' );
		await wrapper.get( '.reading-lists-nav-settings-button .cdx-menu-item--destructive' )
			.trigger( 'click' );

		expect( wrapper.emitted( 'delete-collection' ) ).toHaveLength( 1 );
		expect( wrapper.find( '.reading-lists-collection-settings-actions' ).exists() ).toBe( false );
	} );
} );
