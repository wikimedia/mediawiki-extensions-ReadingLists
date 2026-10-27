import { mount } from '@vue/test-utils';
import i18n from '../../../i18n/en.json';

const DIALOG_PATH = '../../../resources/ext.readingLists.special/components/DeleteCollectionDialog.vue';

async function mountDialog( props = {} ) {
	const DeleteCollectionDialog = ( await import( DIALOG_PATH ) ).default;
	return mount( DeleteCollectionDialog, {
		props: { listName: 'Biographies', ...props }
	} );
}

describe( 'DeleteCollectionDialog', () => {
	beforeEach( () => {
		// The shared mw.msg mock ignores parameters; substitute $1 so the title can be checked.
		vi.spyOn( mw, 'msg' ).mockImplementation(
			( key, param ) => ( i18n[ key ] || key ).replace( '$1', param )
		);
	} );

	afterEach( () => {
		vi.restoreAllMocks();
	} );

	it( 'shows the collection name in the title', async () => {
		const wrapper = await mountDialog();
		expect( wrapper.get( '.cdx-dialog__header__title' ).text() ).toBe( 'Delete "Biographies"?' );
	} );

	it( 'emits confirm from the primary button', async () => {
		const wrapper = await mountDialog();
		await wrapper.get( '.cdx-dialog__footer__primary-action' ).trigger( 'click' );
		expect( wrapper.emitted( 'confirm' ) ).toHaveLength( 1 );
		expect( wrapper.emitted( 'close' ) ).toBeUndefined();
	} );

	it( 'emits close from the cancel button', async () => {
		const wrapper = await mountDialog();
		await wrapper.get( '.cdx-dialog__footer__default-action' ).trigger( 'click' );
		expect( wrapper.emitted( 'close' ) ).toEqual( [ [] ] );
		expect( wrapper.emitted( 'confirm' ) ).toBeUndefined();
	} );

	// Codex always renders the close button and hides it with CSS when
	// useCloseButton is false, so check the header class rather than the element.
	it( 'has no close button on desktop', async () => {
		const wrapper = await mountDialog();
		expect( wrapper.get( '.cdx-dialog__header' ).classes() )
			.toContain( 'cdx-dialog__header--no-close-button' );
	} );

	it( 'emits close from the close button on mobile', async () => {
		vi.spyOn( window, 'innerWidth', 'get' ).mockReturnValue( 375 );
		const wrapper = await mountDialog();
		expect( wrapper.get( '.cdx-dialog__header' ).classes() )
			.not.toContain( 'cdx-dialog__header--no-close-button' );
		await wrapper.get( '.cdx-dialog__header__close-button' ).trigger( 'click' );
		expect( wrapper.emitted( 'close' ) ).toEqual( [ [] ] );
	} );

	it( 'disables both buttons while deleting', async () => {
		const wrapper = await mountDialog( { isDeleting: true } );
		expect( wrapper.get( '.cdx-dialog__footer__primary-action' ).element.disabled ).toBe( true );
		expect( wrapper.get( '.cdx-dialog__footer__default-action' ).element.disabled ).toBe( true );
	} );
} );
