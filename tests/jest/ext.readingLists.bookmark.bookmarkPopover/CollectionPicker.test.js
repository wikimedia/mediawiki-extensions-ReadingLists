const { mount } = require( '@vue/test-utils' );

const apiModule = require( '../../../resources/ext.readingLists.api/index.js' );
const CollectionPicker = require( '../../../resources/ext.readingLists.bookmark.bookmarkPopover/CollectionPicker.vue' );

async function flushPromises() {
	await Promise.resolve();
}

const cdxButtonStub = {
	name: 'CdxButton',
	template: '<button @click="$emit( \'click\' )"><slot /></button>',
	emits: [ 'click' ]
};

const cdxIconStub = {
	name: 'CdxIcon',
	props: [ 'icon' ],
	template: '<span />'
};

describe( 'CollectionPicker', () => {
	let wrapper;

	afterEach( () => {
		if ( wrapper ) {
			wrapper.unmount();
			wrapper = null;
		}
	} );

	function mountPicker( props = {} ) {
		wrapper = mount( CollectionPicker, {
			props: {
				title: 'Test Article',
				...props
			},
			global: {
				stubs: {
					CdxButton: cdxButtonStub,
					CdxIcon: cdxIconStub
				}
			}
		} );
	}

	describe( 'renders correctly', () => {
		test( 'while fetch is pending', () => {
			jest.spyOn( apiModule, 'getListsAll' ).mockReturnValue( new Promise( () => {} ) );

			mountPicker();

			expect( wrapper.html() ).toMatchSnapshot();
		} );

		test( 'after fetch with custom collections', async () => {
			jest.spyOn( apiModule, 'getListsAll' ).mockResolvedValue( [
				{ id: 2, name: 'A', default: false },
				{ id: 3, name: 'B', default: false }
			] );

			mountPicker();
			await flushPromises();
			await flushPromises();

			expect( wrapper.html() ).toMatchSnapshot();
		} );

		test( 'after fetch with no collections', async () => {
			jest.spyOn( apiModule, 'getListsAll' ).mockResolvedValue( [] );

			mountPicker();
			await flushPromises();
			await flushPromises();

			expect( wrapper.html() ).toMatchSnapshot();
		} );

		test( 'after fetch error', async () => {
			jest.spyOn( apiModule, 'getListsAll' ).mockRejectedValue( new Error( 'network error' ) );

			mountPicker();
			await flushPromises();
			await flushPromises();

			expect( wrapper.html() ).toMatchSnapshot();
		} );
	} );

	describe( 'fetching collections on mount', () => {
		test( 'calls getListsAll on mount', async () => {
			const getListsAllMock = jest.spyOn( apiModule, 'getListsAll' ).mockResolvedValue( [] );

			mountPicker();
			await flushPromises();

			expect( getListsAllMock ).toHaveBeenCalledTimes( 1 );
		} );
	} );

	describe( 'when the "create collection" button is clicked', () => {
		test( 'emits a create-collection event', async () => {
			jest.spyOn( apiModule, 'getListsAll' ).mockResolvedValue( [] );

			mountPicker();

			await wrapper.find( '.readinglists-collection-picker__create-button' ).trigger( 'click' );

			expect( wrapper.emitted( 'create-collection' ) ).toBeTruthy();
			expect( wrapper.emitted( 'create-collection' ).length ).toBe( 1 );
		} );
	} );

	describe( 'when one of the "add to collection" buttons is clicked', () => {
		test( 'emits add-to-collection with collection id and name', async () => {
			const customList = { id: 42, name: 'My List', default: false };
			jest.spyOn( apiModule, 'getListsAll' ).mockResolvedValue( [ customList ] );

			mountPicker( { title: 'Test Article' } );
			await flushPromises();
			await flushPromises();

			const addButton = wrapper.find( '.readinglists-collection-picker__collection button' );
			expect( addButton.exists() ).toBe( true );

			await addButton.trigger( 'click' );

			const emitted = wrapper.emitted( 'add-to-collection' );
			expect( emitted ).toBeTruthy();
			expect( emitted[ 0 ] ).toEqual( [ 42, 'My List' ] );
		} );
	} );
} );
