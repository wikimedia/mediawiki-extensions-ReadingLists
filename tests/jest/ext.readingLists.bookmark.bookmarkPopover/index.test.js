jest.mock( 'vue', () => ( {
	createMwApp: jest.fn()
} ) );

jest.mock(
	'../../../resources/ext.readingLists.bookmark.bookmarkPopover/BookmarkPopover.vue',
	() => ( { name: 'BookmarkPopover' } )
);

const { createMwApp } = require( 'vue' );
const { initBookmarkPopover } = require( '../../../resources/ext.readingLists.bookmark.bookmarkPopover/index.js' );

describe( 'initBookmarkPopover', () => {
	let mockApp;
	let capturedProps;

	beforeEach( () => {
		mockApp = { mount: jest.fn(), unmount: jest.fn() };
		createMwApp.mockImplementation( ( _component, props ) => {
			capturedProps = props;
			return mockApp;
		} );
	} );

	test( 'mounts the app with isCurrentlySaved=false', async () => {
		const promise = initBookmarkPopover( false );

		expect( createMwApp ).toHaveBeenCalledWith(
			expect.any( Object ),
			expect.objectContaining( { isCurrentlySaved: false } )
		);
		expect( mockApp.mount ).toHaveBeenCalled();

		capturedProps.onDismiss( false );
		await promise;
	} );

	test( 'mounts the app with isCurrentlySaved=true', async () => {
		const promise = initBookmarkPopover( true );

		expect( createMwApp ).toHaveBeenCalledWith(
			expect.any( Object ),
			expect.objectContaining( { isCurrentlySaved: true } )
		);

		capturedProps.onDismiss( false );
		await promise;
	} );

	test( 'resolves with showNotification value and unmounts when onDismiss is called', async () => {
		const promise = initBookmarkPopover( false );

		capturedProps.onDismiss( false );

		await expect( promise ).resolves.toMatchObject( { showNotification: false } );
		expect( mockApp.unmount ).toHaveBeenCalled();
	} );

	test( 'removes the container from the document body after dismiss', async () => {
		const initialChildCount = document.body.children.length;
		const promise = initBookmarkPopover( false );

		capturedProps.onDismiss( false );
		await promise;

		expect( document.body.children.length ).toBe( initialChildCount );
	} );
} );
