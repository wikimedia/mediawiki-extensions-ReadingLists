import { mount } from '@vue/test-utils';
import AllCollections from '../../../resources/ext.readingLists.special/components/AllCollections.vue';

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
		mw.util.getUrl = vi.fn( ( path ) => `/wiki/${ path }` );
	} );

	afterEach( () => {
		vi.restoreAllMocks();
	} );

	it.each( [
		[ 'loading info', [], true, false ],
		[ 'loading entries', [], false, true ],
		[ 'finished loading with no collections', [], false, false ],
		[ 'finished loading with collections', COLLECTIONS, false, false ]
	] )( 'renders %s', ( _, collections, loadingInfo, loadingEntries ) => {
		const wrapper = mount( AllCollections, {
			props: { collections, loadingInfo, loadingEntries }
		} );
		expect( wrapper.element ).toMatchSnapshot();
	} );

	it( 'shows one list item per collection', () => {
		const wrapper = mount( AllCollections, {
			props: { collections: COLLECTIONS, loadingInfo: false, loadingEntries: false }
		} );
		expect( wrapper.find( 'ul' ).exists() ).toBe( true );
		expect( wrapper.findAll( 'li' ) ).toHaveLength( COLLECTIONS.length );
	} );

	it( 'emits create-collection when the create button is clicked', async () => {
		const wrapper = mount( AllCollections, {
			props: { collections: COLLECTIONS, loadingInfo: false, loadingEntries: false }
		} );
		await wrapper.find( '.readinglists-create-collection-button' ).trigger( 'click' );
		expect( wrapper.emitted( 'create-collection' ) ).toHaveLength( 1 );
	} );

	it( 'emits create-collection when the empty-state create button is clicked', async () => {
		const wrapper = mount( AllCollections, {
			props: { collections: [], loadingInfo: false, loadingEntries: false }
		} );
		await wrapper.find( '.reading-lists-all-collections__empty-state__create-button' ).trigger( 'click' );
		expect( wrapper.emitted( 'create-collection' ) ).toHaveLength( 1 );
	} );

	it( 'strips the base URL prefix from collection URLs passed to RouterLink', () => {
		const wrapper = mount( AllCollections, {
			props: { collections: COLLECTIONS, loadingInfo: false, loadingEntries: false }
		} );
		const links = wrapper.findAllComponents( { name: 'RouterLink' } );
		expect( links[ 0 ].props( 'to' ) ).toBe( '/testuser/1/My_Collection' );
		expect( links[ 1 ].props( 'to' ) ).toBe( '/testuser/2/Science_Articles' );
	} );
} );
