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
		[ 'readinglists-db-error-empty-name', 'Dogs', 'readinglists-db-error-empty-name', true ],
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
		await wrapper.get( 'input' ).setValue( 'Cats' );
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

	it.each( [ '', '   ', '\t', '\u00a0', '\u3000', '\ufeff' ] )(
		'disables Save and blocks submission for blank input %j',
		async ( name ) => {
			await wrapper.get( 'input' ).setValue( name );

			expect( wrapper.getComponent( dialogPopoverStub ).props( 'primaryAction' ).disabled )
				.toBe( true );

			await wrapper.vm.saveCollection();

			expect( postWithEditToken ).not.toHaveBeenCalled();
			expect( wrapper.emitted( 'success' ) ).toBeUndefined();
			expect( wrapper.emitted( 'update:open' ) ).toBeUndefined();
		}
	);

	it.each( [
		[ 'Dogs', 'Dogs' ],
		[ '0', '0' ],
		[ ' Cats ', 'Cats' ],
		[ '\u00a0Cats\u3000', 'Cats' ],
		[ '  Big  cats  ', 'Big  cats' ]
	] )(
		'enables Save and submits and emits the trimmed name for %j',
		async ( input, name ) => {
			await wrapper.get( 'input' ).setValue( input );

			expect( wrapper.getComponent( dialogPopoverStub ).props( 'primaryAction' ).disabled )
				.toBe( false );

			await wrapper.vm.saveCollection();

			expect( postWithEditToken ).toHaveBeenCalledTimes( 1 );
			expect( postWithEditToken ).toHaveBeenCalledWith( expect.objectContaining( {
				action: 'readinglists',
				command,
				name,
				...( isRename ? { list: 7 } : {} )
			} ) );
			expect( wrapper.emitted( 'success' ) ).toEqual( [ [ expectedId, name ] ] );
			expect( wrapper.emitted( 'update:open' ) ).toEqual( [ [ false ] ] );
		}
	);

	it( 'emits the submitted name if the input changes while saving', async () => {
		let resolveSave;
		postWithEditToken.mockImplementation( () => new Promise( ( resolve ) => {
			resolveSave = resolve;
		} ) );
		await wrapper.get( 'input' ).setValue( ' Dogs ' );
		const saving = wrapper.vm.saveCollection();

		// Change component state directly because the input is disabled while saving.
		wrapper.vm.collectionName = 'Cats';
		await wrapper.vm.$nextTick();
		expect( wrapper.emitted( 'success' ) ).toBeUndefined();
		resolveSave( { create: { id: 42 } } );
		await saving;

		expect( postWithEditToken ).toHaveBeenCalledWith( expect.objectContaining( { name: 'Dogs' } ) );
		expect( wrapper.emitted( 'success' ) ).toEqual( [ [ expectedId, 'Dogs' ] ] );
	} );
} );
