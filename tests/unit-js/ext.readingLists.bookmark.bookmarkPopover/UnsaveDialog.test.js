import { mount } from '@vue/test-utils';

// Imported rather than required so the test and the component under test share
// the same module instance, which is what makes `api.stubApi()` take effect.
import apiModule from '../../../resources/ext.readingLists.api/index.js';
import UnsaveDialog from '../../../resources/ext.readingLists.bookmark.bookmarkPopover/UnsaveDialog.vue';

const cdxDialogStub = {
	name: 'CdxDialog',
	props: [ 'open', 'title', 'primaryAction', 'defaultAction' ],
	emits: [ 'update:open', 'primary', 'default' ],
	template: `
		<div v-if="open">
			<slot />
			<button class="cdx-dialog__primary" @click="$emit( 'primary' )">
				{{ primaryAction && primaryAction.label }}
			</button>
			<button class="cdx-dialog__default" @click="$emit( 'default' )">
				{{ defaultAction && defaultAction.label }}
			</button>
		</div>
	`
};

describe( 'UnsaveDialog', () => {
	let wrapper;

	afterEach( () => {
		if ( wrapper ) {
			wrapper.unmount();
			wrapper = null;
		}
	} );

	function mountDialog( props = {} ) {
		wrapper = mount( UnsaveDialog, {
			props: {
				title: 'Test_Page',
				collectionsWithPage: [],
				...props
			},
			global: {
				stubs: { CdxDialog: cdxDialogStub }
			}
		} );
	}

	describe( 'renders correctly', () => {
		test( 'with collections listed', () => {
			mountDialog( { collectionsWithPage: [
				{ id: 1, name: 'Collection A' },
				{ id: 2, name: 'Collection B' }
			] } );
			expect( wrapper.html() ).toMatchSnapshot();
		} );

		test( 'with no collections', () => {
			mountDialog( { collectionsWithPage: [] } );
			expect( wrapper.html() ).toMatchSnapshot();
		} );
	} );

	describe( 'collectionNames', () => {
		test( 'renders a list item for each collection name', () => {
			mountDialog( { collectionsWithPage: [
				{ id: 1, name: 'Collection A' },
				{ id: 2, name: 'Collection B' }
			] } );

			const items = wrapper.findAll( 'li' );
			expect( items ).toHaveLength( 2 );
			expect( items[ 0 ].text() ).toBe( 'Collection A' );
			expect( items[ 1 ].text() ).toBe( 'Collection B' );
		} );
	} );

	describe( 'unsavePage', () => {
		test( 'calls deleteEntryByPageTitle with the page title', async () => {
			const postWithEditToken = vi.fn( () => Promise.resolve( {} ) );
			apiModule.stubApi( { postWithEditToken } );

			mountDialog( { title: 'Test_Page' } );
			wrapper.vm.unsavePage();
			await Promise.resolve();

			expect( postWithEditToken ).toHaveBeenCalledWith( expect.objectContaining( {
				command: 'deleteentry',
				title: 'Test_Page'
			} ) );
		} );

		test( 'emits page-unsaved after the API call resolves', async () => {
			apiModule.stubApi( { postWithEditToken: vi.fn( () => Promise.resolve( {} ) ) } );

			mountDialog();
			wrapper.vm.unsavePage();
			await Promise.resolve();

			expect( wrapper.emitted( 'page-unsaved' ) ).toBeTruthy();
		} );

		test( 'does not emit page-unsaved before the API call resolves', () => {
			let resolveDelete;
			apiModule.stubApi( {
				postWithEditToken: vi.fn( () => new Promise( ( resolve ) => {
					resolveDelete = resolve;
				} ) )
			} );

			mountDialog();
			wrapper.vm.unsavePage();

			expect( wrapper.emitted( 'page-unsaved' ) ).toBeFalsy();

			resolveDelete( {} );
		} );

		test( 'emits close when the default (cancel) action is clicked', async () => {
			mountDialog();

			await wrapper.find( '.cdx-dialog__default' ).trigger( 'click' );

			expect( wrapper.emitted( 'close' ) ).toBeTruthy();
		} );
	} );
} );
