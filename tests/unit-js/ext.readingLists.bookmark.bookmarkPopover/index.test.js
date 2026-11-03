vi.mock( 'vue', () => ( {
	createMwApp: vi.fn()
} ) );

// index.js's `require( './BookmarkPopover.vue' )` is hoisted to a default import.
vi.mock(
	'../../../resources/ext.readingLists.bookmark.bookmarkPopover/BookmarkPopover.vue',
	() => ( { default: { name: 'BookmarkPopover' } } )
);

import { createMwApp } from 'vue';
import { initBookmarkPopover } from '../../../resources/ext.readingLists.bookmark.bookmarkPopover/index.js';

describe( 'initBookmarkPopover', () => {
	let mockApp;
	let capturedProps;

	beforeEach( () => {
		mockApp = { mount: vi.fn(), unmount: vi.fn() };
		createMwApp.mockImplementation( ( _component, props ) => {
			capturedProps = props;
			return mockApp;
		} );
	} );

	test.each( [ true, false ] )( 'passes default-list membership to the popover (%s)', async ( isInDefaultList ) => {
		const collections = [ { id: 1, name: 'My List' } ];
		const promise = initBookmarkPopover( false, collections, isInDefaultList );

		expect( createMwApp ).toHaveBeenCalledWith(
			expect.any( Object ),
			expect.objectContaining( { isNewSave: false, collections, isInDefaultList } )
		);
		expect( mockApp.mount ).toHaveBeenCalled();

		capturedProps.onDismiss( { isSaved: false, showNotification: false } );
		await promise;
	} );

	test( 'mounts the app with isNewSave=true and collections', async () => {
		const collections = [ { id: 2, name: 'Another List' } ];
		const promise = initBookmarkPopover( true, collections, true );

		expect( createMwApp ).toHaveBeenCalledWith(
			expect.any( Object ),
			expect.objectContaining( { isNewSave: true, collections, isInDefaultList: true } )
		);

		capturedProps.onDismiss( { isSaved: true, showNotification: false } );
		await promise;
	} );

	test( 'resolves with the data object and unmounts when onDismiss is called', async () => {
		const promise = initBookmarkPopover( false, [], false );

		capturedProps.onDismiss( { isSaved: false, showNotification: true } );

		await expect( promise ).resolves.toMatchObject( {
			isSaved: false,
			showNotification: true
		} );
		expect( mockApp.unmount ).toHaveBeenCalled();
	} );

	test( 'removes the container from the document body after dismiss', async () => {
		const initialChildCount = document.body.children.length;
		const promise = initBookmarkPopover( false, [], false );

		capturedProps.onDismiss( { isSaved: false, showNotification: false } );
		await promise;

		expect( document.body.children.length ).toBe( initialChildCount );
	} );
} );
