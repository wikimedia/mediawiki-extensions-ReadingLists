import { mount } from '@vue/test-utils';
import { createTestingPinia } from '@pinia/testing';
import AllCollections from '../../../resources/ext.readingLists.special/components/AllCollections.vue';

// Base URL that mw.util.getUrl returns when given 'Special:Saved'.
// collectionsBaseUrl in ext.readingLists.api falls back to 'Special:Saved'
// when mw.config.get( 'wgCanonicalSpecialPageName' ) is not 'Saved' (as in the no-op test mock).
const BASE_URL = '/wiki/Special:Saved';

const COLLECTIONS = [
	{
		id: 1,
		title: 'My Collection',
		description: '5 items',
		url: `${ BASE_URL }/1/My_Collection`
	},
	{
		id: 2,
		title: 'Science Articles',
		description: '0 items',
		url: `${ BASE_URL }/2/Science_Articles`
	}
];

const defaultProps = {
	collections: COLLECTIONS,
	loadingEntries: false
};

/**
 * Mount the AllCollections component with the pinia testing plugin.
 *
 * @param {Object} props
 * @param {boolean} loadingInfo To set initial state
 * @return {Object}
 */
function mountAllCollections( props = defaultProps, loadingInfo = false ) {
	return mount( AllCollections, {
		props,
		global: {
			plugins: [
				createTestingPinia( {
					initialState: { readingLists: { loadingInfo } }
				} )
			]
		}
	} );
}

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
		const wrapper = mountAllCollections( { collections, loadingEntries }, loadingInfo );
		expect( wrapper.element ).toMatchSnapshot();
	} );

	it( 'shows one list item per collection', () => {
		const wrapper = mountAllCollections();
		expect( wrapper.find( 'ul' ).exists() ).toBe( true );
		expect( wrapper.findAll( 'li' ) ).toHaveLength( COLLECTIONS.length );
	} );

	describe( 'in list view (mobile)', () => {
		const listViewProps = { ...defaultProps, isListView: true };

		it( 'emits create-collection when the create button is clicked', async () => {
			const wrapper = mountAllCollections( listViewProps );
			await wrapper.get( '.readinglists-create-collection-button' ).trigger( 'click' );
			expect( wrapper.emitted( 'create-collection' ) ).toHaveLength( 1 );
		} );

		it( 'renders the create button and a list of cards with dividers', () => {
			const wrapper = mountAllCollections( listViewProps );
			expect( wrapper.element ).toMatchSnapshot();
		} );
	} );

	describe( 'in grid view (desktop)', () => {
		it( 'renders a grid of outlined cards without the create button', () => {
			// The navigation bar shows the create button in grid view.
			const wrapper = mountAllCollections( { ...defaultProps, isListView: false } );
			expect( wrapper.element ).toMatchSnapshot();
		} );
	} );

	it( 'emits create-collection when the empty-state create button is clicked', async () => {
		const wrapper = mountAllCollections( { collections: [], loadingEntries: false } );
		await wrapper.find( '.reading-lists-all-collections__empty-state__create-button' ).trigger( 'click' );
		expect( wrapper.emitted( 'create-collection' ) ).toHaveLength( 1 );
	} );
} );
