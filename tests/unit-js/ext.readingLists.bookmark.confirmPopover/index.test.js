const { createMwApp } = vi.hoisted( () => ( {
	createMwApp: vi.fn()
} ) );

vi.mock( 'vue', () => ( {
	createMwApp
} ) );

vi.mock(
	'../../../resources/ext.readingLists.bookmark.confirmPopover/ConfirmUnsavePopover.vue',
	() => ( { default: { name: 'ConfirmUnsavePopover' } } )
);

import { confirmUnsaveFromCustomList } from '../../../resources/ext.readingLists.bookmark.confirmPopover/index.js';

describe( 'confirmUnsaveFromCustomList', () => {
	let mockApp;
	let capturedProps;

	beforeEach( () => {
		mockApp = { mount: vi.fn(), unmount: vi.fn() };
		createMwApp.mockImplementation( ( _component, props ) => {
			capturedProps = props;
			return mockApp;
		} );
	} );

	test( 'mounts the app with the anchor element and isMinerva prop', async () => {
		const anchor = document.createElement( 'button' );
		const promise = confirmUnsaveFromCustomList( anchor, false );

		expect( createMwApp ).toHaveBeenCalledWith(
			expect.any( Object ),
			expect.objectContaining( {
				anchorElement: anchor,
				isMinerva: false
			} )
		);
		expect( mockApp.mount ).toHaveBeenCalled();

		capturedProps.onCancel();
		await promise;
	} );

	test( 'resolves with true and unmounts when onConfirm is called', async () => {
		const anchor = document.createElement( 'button' );
		const promise = confirmUnsaveFromCustomList( anchor, false );

		capturedProps.onConfirm();

		await expect( promise ).resolves.toBe( true );
		expect( mockApp.unmount ).toHaveBeenCalled();
	} );

	test( 'resolves with false and unmounts when onCancel is called', async () => {
		const anchor = document.createElement( 'button' );
		const promise = confirmUnsaveFromCustomList( anchor, true );

		capturedProps.onCancel();

		await expect( promise ).resolves.toBe( false );
		expect( mockApp.unmount ).toHaveBeenCalled();
	} );
} );
