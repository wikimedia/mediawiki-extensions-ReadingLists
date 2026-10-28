import { mount } from '@vue/test-utils';
import apiModule from '../../../resources/ext.readingLists.api/index.js';
import CollectionNameDialog from '../../../resources/ext.readingLists.common/CollectionNameDialog.vue';
import i18n from '../../../i18n/en.json';

const dialogPopoverStub = {
	name: 'DialogPopover',
	props: [ 'open', 'primaryAction' ],
	emits: [ 'primary' ],
	template: '<div v-if="open"><slot /></div>'
};

describe.each( [
	{ command: 'create', isRename: false },
	{ command: 'update', isRename: true }
] )( 'CollectionNameDialog: $command', ( { command, isRename } ) => {
	let wrapper;
	let postWithEditToken;
	const expectedId = isRename ? 7 : 42;

	beforeEach( () => {
		postWithEditToken = vi.fn().mockResolvedValue( { create: { id: 42 } } );
		apiModule.stubApi( { postWithEditToken } );
		wrapper = mount( CollectionNameDialog, {
			props: { isRename, listId: 7, title: 'Existing collection' },
			global: {
				stubs: { DialogPopover: dialogPopoverStub }
			}
		} );
	} );

	afterEach( () => {
		wrapper.unmount();
	} );

	it.each( [
		[ 'readinglists-db-error-empty-name', '   ', 'readinglists-db-error-empty-name', true ],
		[ 'http', 'Dogs', 'readinglists-browser-error', false ],
		[ 'unknown-error', 'Dogs', 'readinglists-browser-error', false ]
	] )( 'shows a visible error for %s and retains the input', async ( code, name, messageKey, isError ) => {
		postWithEditToken.mockRejectedValueOnce( code );
		await wrapper.get( 'input' ).setValue( name );

		await wrapper.vm.saveCollection();

		// CdxMessage's id is on the transition stub, outside the element with role="alert".
		const message = wrapper.get( '.readinglists-collection-name-dialog__error' );
		expect( message.text() ).toBe( i18n[ messageKey ] );
		expect( wrapper.get( 'input' ).element.value ).toBe( name );
		expect( wrapper.get( 'input' ).element.disabled ).toBe( false );
		expect( wrapper.get( 'input' ).attributes( 'aria-invalid' ) )
			.toBe( isError ? 'true' : undefined );
		expect( wrapper.get( 'input' ).attributes( 'aria-errormessage' ) )
			.toBe( isError ? message.attributes( 'id' ) : undefined );
		if ( isError ) {
			expect( message.attributes( 'id' ) ).toBeTruthy();
		}
		expect( wrapper.getComponent( dialogPopoverStub ).props( 'primaryAction' ).disabled )
			.toBe( false );
		expect( wrapper.emitted( 'success' ) ).toBeUndefined();
		expect( wrapper.emitted( 'update:open' ) ).toBeUndefined();
		expect( postWithEditToken ).toHaveBeenCalledWith( expect.objectContaining( {
			action: 'readinglists',
			command,
			name,
			...( isRename ? { list: 7 } : {} )
		} ) );
	} );

	it( 'clears the validation error when the name changes', async () => {
		postWithEditToken.mockRejectedValueOnce( 'readinglists-db-error-empty-name' );
		await wrapper.get( 'input' ).setValue( '   ' );
		await wrapper.vm.saveCollection();
		expect( wrapper.find( '[role="alert"]' ).exists() ).toBe( true );

		await wrapper.get( 'input' ).setValue( 'Dogs' );

		expect( wrapper.find( '[role="alert"]' ).exists() ).toBe( false );
		expect( wrapper.get( 'input' ).attributes( 'aria-invalid' ) ).toBeUndefined();
		expect( wrapper.get( 'input' ).attributes( 'aria-errormessage' ) ).toBeUndefined();
	} );

	it( 'clears a failed save on retry and closes only after success', async () => {
		postWithEditToken.mockRejectedValueOnce( 'http' );
		await wrapper.get( 'input' ).setValue( 'Dogs' );
		await wrapper.vm.saveCollection();
		expect( wrapper.find( '[role="alert"]' ).exists() ).toBe( true );

		let resolveSave;
		postWithEditToken.mockImplementationOnce( () => new Promise( ( resolve ) => {
			resolveSave = resolve;
		} ) );
		const saving = wrapper.vm.saveCollection();
		await wrapper.vm.$nextTick();

		expect( wrapper.find( '[role="alert"]' ).exists() ).toBe( false );
		expect( wrapper.getComponent( dialogPopoverStub ).props( 'open' ) ).toBe( true );
		expect( wrapper.emitted( 'success' ) ).toBeUndefined();

		resolveSave( { create: { id: 42 } } );
		await saving;

		expect( postWithEditToken ).toHaveBeenCalledTimes( 2 );
		expect( wrapper.emitted( 'success' ) ).toEqual( [ [ expectedId, 'Dogs' ] ] );
		expect( wrapper.emitted( 'update:open' ) ).toEqual( [ [ false ] ] );
	} );

	it( 'disables the input and Save and blocks repeated submissions while saving', async () => {
		let resolveSave;
		postWithEditToken.mockImplementationOnce( () => new Promise( ( resolve ) => {
			resolveSave = resolve;
		} ) );
		await wrapper.get( 'input' ).setValue( 'Dogs' );
		const saving = wrapper.vm.saveCollection();
		await wrapper.vm.$nextTick();

		expect( wrapper.get( 'input' ).element.disabled ).toBe( true );
		expect( wrapper.getComponent( dialogPopoverStub ).props( 'primaryAction' ).disabled )
			.toBe( true );
		await wrapper.vm.saveCollection();
		expect( postWithEditToken ).toHaveBeenCalledTimes( 1 );
		expect( wrapper.emitted( 'success' ) ).toBeUndefined();

		resolveSave( { create: { id: 42 } } );
		await saving;
		expect( wrapper.emitted( 'success' ) ).toEqual( [ [ expectedId, 'Dogs' ] ] );
	} );
} );
