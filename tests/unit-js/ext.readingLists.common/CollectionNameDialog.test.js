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
	const successResponse = {
		[ command ]: { id: expectedId }
	};

	beforeEach( () => {
		postWithEditToken = vi.fn().mockResolvedValue( successResponse );
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

	it( 'does not show an initial validation error', () => {
		expect( wrapper.find( '[role="alert"]' ).exists() ).toBe( false );
	} );

	it.each( [
		[
			'readinglists-db-error-empty-name', 'Dogs',
			'readinglists-customlists-collection-name-dialog-required', true
		],
		[
			'readinglists-db-error-duplicate-list', 'Cats',
			'readinglists-customlists-collection-name-dialog-duplicate', true
		],
		[ 'http', 'Dogs', 'readinglists-browser-error', false ],
		[ undefined, 'Dogs', 'readinglists-browser-error', false ],
		[ 'unknown-error', 'Dogs', 'readinglists-browser-error', false ]
	] )( 'shows an error for %s and retains the input', async ( code, name, messageKey, isNameError ) => {
		postWithEditToken.mockRejectedValueOnce( code );
		await wrapper.get( 'input' ).setValue( name );

		await wrapper.vm.saveCollection();

		const message = wrapper.get( '[role="alert"]' );
		expect( message.text() ).toBe( i18n[ messageKey ] );
		expect( wrapper.get( 'input' ).element.value ).toBe( name );
		expect( wrapper.get( 'input' ).element.disabled ).toBe( false );
		expect( wrapper.get( 'input' ).attributes( 'aria-invalid' ) )
			.toBe( isNameError ? 'true' : undefined );
		expect( wrapper.find( '.cdx-text-input--status-error' ).exists() ).toBe( isNameError );
		expect( wrapper.find( '.cdx-field [role="alert"]' ).exists() ).toBe( isNameError );
		const errorId = wrapper.get( 'input' ).attributes( 'aria-errormessage' );
		if ( isNameError ) {
			expect( errorId ).toBeTruthy();
			expect( wrapper.get( `[id="${ errorId }"]` ).text() ).toBe( i18n[ messageKey ] );
		} else {
			expect( errorId ).toBeUndefined();
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

	it( 'keeps the required-name error while editing until Save is retried', async () => {
		await wrapper.get( 'input' ).setValue( '' );
		await wrapper.vm.saveCollection();
		expect( wrapper.find( '[role="alert"]' ).exists() ).toBe( true );

		await wrapper.get( 'input' ).setValue( 'Dogs' );
		await wrapper.get( 'input' ).trigger( 'blur' );

		expect( wrapper.get( '[role="alert"]' ).text() )
			.toBe( i18n[ 'readinglists-customlists-collection-name-dialog-required' ] );
		expect( wrapper.get( 'input' ).attributes( 'aria-invalid' ) ).toBe( 'true' );
		expect( postWithEditToken ).not.toHaveBeenCalled();

		await wrapper.vm.saveCollection();

		expect( wrapper.emitted( 'success' ) ).toEqual( [ [ expectedId, 'Dogs' ] ] );
	} );

	it( 'replaces a save error with a required-name error only when Save is retried', async () => {
		postWithEditToken.mockRejectedValueOnce( 'http' );
		await wrapper.get( 'input' ).setValue( 'Cats' );
		await wrapper.vm.saveCollection();
		expect( wrapper.get( '[role="alert"]' ).text() ).toBe( i18n[ 'readinglists-browser-error' ] );

		await wrapper.get( 'input' ).setValue( '   ' );
		await wrapper.get( 'input' ).trigger( 'blur' );
		expect( wrapper.get( '[role="alert"]' ).text() ).toBe( i18n[ 'readinglists-browser-error' ] );

		await wrapper.vm.saveCollection();

		expect( wrapper.get( '[role="alert"]' ).text() )
			.toBe( i18n[ 'readinglists-customlists-collection-name-dialog-required' ] );
		expect( wrapper.findAll( '[role="alert"]' ) ).toHaveLength( 1 );
		expect( postWithEditToken ).toHaveBeenCalledTimes( 1 );
		expect( wrapper.emitted( 'success' ) ).toBeUndefined();
	} );

	it.each( [
		'http',
		'readinglists-db-error-duplicate-list'
	] )( 'clears %s on retry and closes only after success', async ( code ) => {
		postWithEditToken.mockRejectedValueOnce( code );
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
		expect( wrapper.get( 'input' ).attributes( 'aria-invalid' ) ).toBeUndefined();
		expect( wrapper.get( 'input' ).attributes( 'aria-errormessage' ) ).toBeUndefined();
		expect( wrapper.getComponent( dialogPopoverStub ).props( 'open' ) ).toBe( true );
		expect( wrapper.emitted( 'success' ) ).toBeUndefined();

		resolveSave( successResponse );
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

		resolveSave( successResponse );
		await saving;
		expect( wrapper.emitted( 'success' ) ).toEqual( [ [ expectedId, 'Dogs' ] ] );
	} );

	it.each( [ '', '   ', '\t', '\u00a0', '\u3000', '\ufeff' ] )(
		'shows a validation error and blocks submission for blank input %j',
		async ( name ) => {
			await wrapper.get( 'input' ).setValue( name );

			expect( wrapper.getComponent( dialogPopoverStub ).props( 'primaryAction' ).disabled )
				.toBe( false );
			expect( wrapper.find( '[role="alert"]' ).exists() ).toBe( false );

			await wrapper.vm.saveCollection();

			expect( postWithEditToken ).not.toHaveBeenCalled();
			expect( wrapper.get( '[role="alert"]' ).text() )
				.toBe( i18n[ 'readinglists-customlists-collection-name-dialog-required' ] );
			expect( wrapper.get( 'input' ).attributes( 'aria-invalid' ) ).toBe( 'true' );
			expect( wrapper.get( 'input' ).element.value ).toBe( '' );
			expect( wrapper.get( 'input' ).element.disabled ).toBe( false );
			expect( wrapper.getComponent( dialogPopoverStub ).props( 'primaryAction' ).disabled )
				.toBe( false );
			expect( wrapper.emitted( 'success' ) ).toBeUndefined();
			expect( wrapper.emitted( 'update:open' ) ).toBeUndefined();
		}
	);

	it.each( [ '', '   ', '\u00a0\u3000' ] )(
		'does not validate blank input %j on blur',
		async ( name ) => {
			await wrapper.get( 'input' ).setValue( name );
			expect( wrapper.find( '[role="alert"]' ).exists() ).toBe( false );

			await wrapper.get( 'input' ).trigger( 'blur' );

			expect( wrapper.find( '[role="alert"]' ).exists() ).toBe( false );
			expect( wrapper.get( 'input' ).attributes( 'aria-invalid' ) ).toBeUndefined();
			expect( wrapper.get( 'input' ).element.value ).toBe( name );
			expect( postWithEditToken ).not.toHaveBeenCalled();
		}
	);

	it( 'does not trim the displayed name while typing or on blur', async () => {
		await wrapper.get( 'input' ).setValue( '  Big  cats  ' );
		expect( wrapper.get( 'input' ).element.value ).toBe( '  Big  cats  ' );

		await wrapper.get( 'input' ).trigger( 'blur' );

		expect( wrapper.get( 'input' ).element.value ).toBe( '  Big  cats  ' );
		expect( postWithEditToken ).not.toHaveBeenCalled();
	} );

	it.each( [ 'Cats', '  Cats ' ] )(
		'shows a duplicate-name error for %j and allows correction',
		async ( input ) => {
			postWithEditToken.mockRejectedValueOnce( 'readinglists-db-error-duplicate-list' );
			await wrapper.get( 'input' ).setValue( input );

			await wrapper.vm.saveCollection();

			const message = wrapper.get( '[role="alert"]' );
			expect( message.text() )
				.toBe( i18n[ 'readinglists-customlists-collection-name-dialog-duplicate' ] );
			expect( wrapper.get( 'input' ).element.value ).toBe( 'Cats' );
			expect( wrapper.get( 'input' ).attributes( 'aria-invalid' ) ).toBe( 'true' );
			const errorId = wrapper.get( 'input' ).attributes( 'aria-errormessage' );
			expect( errorId ).toBeTruthy();
			expect( wrapper.get( `[id="${ errorId }"]` ).text() ).toBe( message.text() );
			expect( wrapper.getComponent( dialogPopoverStub ).props( 'open' ) ).toBe( true );
			expect( wrapper.getComponent( dialogPopoverStub ).props( 'primaryAction' ).disabled )
				.toBe( false );
			expect( wrapper.emitted( 'success' ) ).toBeUndefined();
			expect( wrapper.emitted( 'update:open' ) ).toBeUndefined();
			expect( postWithEditToken ).toHaveBeenCalledWith( expect.objectContaining( {
				command, name: 'Cats'
			} ) );
			await wrapper.get( 'input' ).trigger( 'blur' );
			expect( wrapper.get( '[role="alert"]' ).text() )
				.toBe( i18n[ 'readinglists-customlists-collection-name-dialog-duplicate' ] );

			await wrapper.get( 'input' ).setValue( 'Dogs' );
			await wrapper.get( 'input' ).trigger( 'blur' );
			expect( wrapper.get( '[role="alert"]' ).text() )
				.toBe( i18n[ 'readinglists-customlists-collection-name-dialog-duplicate' ] );
			expect( wrapper.get( 'input' ).attributes( 'aria-invalid' ) ).toBe( 'true' );
			await wrapper.vm.saveCollection();

			expect( postWithEditToken ).toHaveBeenCalledTimes( 2 );
			expect( wrapper.emitted( 'success' ) ).toEqual( [ [ expectedId, 'Dogs' ] ] );
			expect( wrapper.emitted( 'update:open' ) ).toEqual( [ [ false ] ] );
		}
	);

	it( 'shows the submitted name in the field if saving fails', async () => {
		postWithEditToken.mockRejectedValueOnce( 'http' );
		await wrapper.get( 'input' ).setValue( '  Cats ' );

		await wrapper.vm.saveCollection();

		expect( wrapper.get( 'input' ).element.value ).toBe( 'Cats' );
		expect( wrapper.get( '[role="alert"]' ).text() ).toBe( i18n[ 'readinglists-browser-error' ] );
	} );

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
		resolveSave( successResponse );
		await saving;

		expect( postWithEditToken ).toHaveBeenCalledWith( expect.objectContaining( { name: 'Dogs' } ) );
		expect( wrapper.emitted( 'success' ) ).toEqual( [ [ expectedId, 'Dogs' ] ] );
	} );
} );
