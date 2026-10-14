const { mount } = require( '@vue/test-utils' );
const AllCollections = require( '../../../resources/ext.readingLists.special/components/AllCollections.vue' );

// Base URL that mw.util.getUrl returns when given 'Special:ReadingLists'.
// collectionsBaseUrl in ext.readingLists.common uses mw.config.get() || 'ReadingLists',
// which resolves to 'Special:ReadingLists' because mw.config.get is a no-op mock.
const BASE_URL = '/wiki/Special:ReadingLists';

const COLLECTIONS = [
	{
		id: 1,
		title: 'My Collection',
		description: 'A great collection of articles',
		url: `${ BASE_URL }/testuser/1/My_Collection`
	},
	{
		id: 2,
		title: 'Science Articles',
		description: '',
		url: `${ BASE_URL }/testuser/2/Science_Articles`
	}
];

describe( 'AllCollections', () => {
	beforeEach( () => {
		mw.util.getUrl = jest.fn( ( path ) => `/wiki/${ path }` );
	} );

	afterEach( () => {
		jest.restoreAllMocks();
	} );

	it.each( [
		[ 'loading with no collections', [], true ],
		[ 'not loading with no collections', [], false ],
		[ 'not loading with collections', COLLECTIONS, false ],
		[ 'loading with collections (list hidden)', COLLECTIONS, true ]
	] )( 'renders %s', ( _, collections, loadingInfo ) => {
		const wrapper = mount( AllCollections, {
			props: { collections, loadingInfo }
		} );
		expect( wrapper.element ).toMatchSnapshot();
	} );

	it( 'hides the list when loadingInfo is true', () => {
		const wrapper = mount( AllCollections, {
			props: { collections: COLLECTIONS, loadingInfo: true }
		} );
		expect( wrapper.find( 'ul' ).exists() ).toBe( false );
	} );

	it( 'hides the list when collections is empty', () => {
		const wrapper = mount( AllCollections, {
			props: { collections: [], loadingInfo: false }
		} );
		expect( wrapper.find( 'ul' ).exists() ).toBe( false );
	} );

	it( 'shows one list item per collection when not loading', () => {
		const wrapper = mount( AllCollections, {
			props: { collections: COLLECTIONS, loadingInfo: false }
		} );
		expect( wrapper.find( 'ul' ).exists() ).toBe( true );
		expect( wrapper.findAll( 'li' ) ).toHaveLength( COLLECTIONS.length );
	} );

	it( 'emits create-collection when the create button is clicked', async () => {
		const wrapper = mount( AllCollections, {
			props: { collections: [], loadingInfo: false }
		} );
		await wrapper.find( '.readinglists-create-collection-button' ).trigger( 'click' );
		expect( wrapper.emitted( 'create-collection' ) ).toHaveLength( 1 );
	} );

	it( 'strips the base URL prefix from collection URLs passed to RouterLink', () => {
		const wrapper = mount( AllCollections, {
			props: { collections: COLLECTIONS, loadingInfo: false }
		} );
		const links = wrapper.findAllComponents( { name: 'RouterLink' } );
		expect( links[ 0 ].props( 'to' ) ).toBe( '/testuser/1/My_Collection' );
		expect( links[ 1 ].props( 'to' ) ).toBe( '/testuser/2/Science_Articles' );
	} );
} );
