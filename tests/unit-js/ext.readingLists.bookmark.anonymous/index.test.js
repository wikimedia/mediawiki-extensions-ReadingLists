vi.mock( 'vue', () => ( {
	createMwApp: vi.fn(),
	defineComponent: vi.fn()
} ) );

vi.mock( '../../../codex.js', () => ( {
	CdxPopover: {
		name: 'CdxPopover',
		template: '<div class="cdx-popover"><slot></slot><slot name="footer"></slot></div>',
		props: [ 'title', 'anchor', 'useCloseButton', 'useBottomSheet', 'placement' ]
	}
} ) );

// CtaDialog.vue imports useIsMobileResolution from this module. The wire-up
// test does not mount the component, so it does not need the real module.
vi.mock( '../../../resources/ext.readingLists.common/index.js', () => ( {
	useIsMobileResolution: vi.fn()
} ) );

mw.testKitchen = {
	compat: {
		getExperiment: vi.fn( () => ( {
			send: vi.fn() // Properly mock the `send` method
		} ) )
	}
};

describe( 'Anonymous bookmark button', () => {
	let mockApp;

	beforeEach( () => {
		// Reset the module registry so the side-effecting wire-up module re-runs
		// on each dynamic import below.
		vi.resetModules();
		mockApp = { mount: vi.fn(), unmount: vi.fn() };
	} );

	afterEach( () => {
		document.body.innerHTML = '';
		vi.clearAllMocks();
	} );

	it( 'throws error when bookmark element is not found', async () => {
		await expect(
			import( '../../../resources/ext.readingLists.bookmark.anonymous/index.js' )
		).rejects.toThrow( 'Bookmark not found' );
	} );

	it( 'mounts CtaDialog when bookmark is clicked', async () => {
		const bookmark = document.createElement( 'a' );
		bookmark.id = 'ca-bookmark';
		document.body.appendChild( bookmark );

		// Grab the mocked `vue` from the freshly reset registry so it is the same
		// instance the wire-up module imports.
		const { createMwApp } = await import( 'vue' );
		createMwApp.mockReturnValue( mockApp );

		// Needs to be imported after bookmark exists.
		await import( '../../../resources/ext.readingLists.bookmark.anonymous/index.js' );

		bookmark.click();

		expect( createMwApp ).toHaveBeenCalled();
		expect( createMwApp.mock.calls[ 0 ][ 0 ].name ).toBe( 'CtaDialog' );
		expect( createMwApp.mock.calls[ 0 ][ 1 ].anchor ).toBe( bookmark );
		expect( mockApp.mount ).toHaveBeenCalled();
	} );
} );
