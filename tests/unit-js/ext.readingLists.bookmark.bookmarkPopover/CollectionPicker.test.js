import { mount } from '@vue/test-utils';
import CollectionPicker from '../../../resources/ext.readingLists.bookmark.bookmarkPopover/CollectionPicker.vue';

const cdxButtonStub = {
	name: 'CdxButton',
	template: '<button @click="$emit( \'click\', $event )"><slot /></button>',
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
		test( 'after fetch with custom collections', async () => {
			const collections = [
				{ id: 2, name: 'A', default: false },
				{ id: 3, name: 'B', default: false }
			];

			mountPicker( { collections } );

			expect( wrapper.html() ).toMatchSnapshot();
		} );

		test( 'after fetch with no collections', async () => {
			mountPicker();

			expect( wrapper.html() ).toMatchSnapshot();
		} );
	} );

	describe( 'when the "create collection" button is clicked', () => {
		test( 'emits a create-collection event', async () => {
			mountPicker();

			await wrapper.find( '.readinglists-collection-picker__create-button' ).trigger( 'click' );

			expect( wrapper.emitted( 'create-collection' ) ).toBeTruthy();
			expect( wrapper.emitted( 'create-collection' ).length ).toBe( 1 );
		} );
	} );

	describe( 'unsave button', () => {
		test( 'emits unsave-click when clicked', async () => {
			mountPicker();

			await wrapper.find( '.readinglists-collection-picker__unsave-button' ).trigger( 'click' );

			expect( wrapper.emitted( 'unsave-click' ) ).toBeTruthy();
			expect( wrapper.emitted( 'unsave-click' ).length ).toBe( 1 );
		} );
	} );

	describe( 'when one of the "add to collection" buttons is clicked', () => {
		test( 'emits add-to-collection with collection id and name', async () => {
			const customList = { id: 42, name: 'My List', default: false };
			const collections = [ customList ];
			mountPicker( { title: 'Test Article', collections } );

			const addButton = wrapper.find( '.readinglists-collection-picker__collection button' );
			expect( addButton.exists() ).toBe( true );

			await addButton.trigger( 'click' );

			const emitted = wrapper.emitted( 'add-to-collection' );
			expect( emitted ).toBeTruthy();
			expect( emitted[ 0 ] ).toEqual( [ 42, 'My List' ] );
		} );
	} );

	test.each( [
		'.readinglists-collection-picker__collection',
		'.readinglists-collection-picker__collection button'
	] )( 'emits removal once with the collection id and name when clicking %s', async ( selector ) => {
		mountPicker( {
			collections: [ { id: 42, name: 'My List', withTitle: true } ]
		} );

		await wrapper.find( selector ).trigger( 'click' );

		expect( wrapper.emitted( 'remove-from-collection' ) ).toEqual( [ [ 42, 'My List' ] ] );
		expect( wrapper.emitted( 'add-to-collection' ) ).toBeUndefined();
	} );
} );
