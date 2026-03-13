jest.mock( 'vue', () => ( {
	createMwApp: jest.fn(),
	defineComponent: jest.fn()
} ) );

const { createMwApp } = require( 'vue' );

jest.mock( '../../../codex.js', () => ( {
	CdxDialog: {
		name: 'CdxDialog',
		template: '<div class="cdx-dialog"><slot></slot><slot name="footer"></slot></div>',
		props: [ 'title', 'useCloseButton', 'renderInPlace' ]
	}
} ) );

describe( 'Anonymous bookmark button', () => {
	let mockApp;

	beforeEach( () => {
		mockApp = { mount: jest.fn(), unmount: jest.fn() };
		createMwApp.mockReturnValue( mockApp );
	} );

	afterEach( () => {
		document.body.innerHTML = '';
		jest.clearAllMocks();
	} );

	it( 'throws error when bookmark element is not found', () => {
		expect( () => {
			require( '../../../resources/ext.readingLists.bookmark.anonymous/index.js' );
		} ).toThrow( 'Bookmark not found' );
	} );

	it( 'mounts CtaDialog when bookmark is clicked', () => {
		const bookmark = document.createElement( 'a' );
		bookmark.id = 'ca-bookmark';
		document.body.appendChild( bookmark );

		// Needs to be required after bookmark exists.
		require( '../../../resources/ext.readingLists.bookmark.anonymous/index.js' );

		bookmark.click();

		expect( createMwApp ).toHaveBeenCalled();
		expect( createMwApp.mock.calls[ 0 ][ 0 ].name ).toBe( 'CtaDialog' );
		expect( mockApp.mount ).toHaveBeenCalled();
	} );
} );
