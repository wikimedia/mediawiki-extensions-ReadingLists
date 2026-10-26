import { init } from '../../../resources/ext.readingLists.bookmark.anonymous/index.js';

const HTML_VECTOR_BOOKMARK = `<li id="ca-bookmark" class="mw-list-item vector-menu-item--collapsible"><a href="#" class="cdx-button cdx-button--fake-button cdx-button--fake-button--enabled cdx-button--weight-quiet cdx-button--icon-only reading-lists-bookmark" id="" title="Add this page to your saved items"><span class="vector-icon mw-ui-icon-bookmarkOutline mw-ui-icon-wikimedia-bookmarkOutline cdx-button__icon"></span>
<span>Save</span></a></li>`;
const HTML_VECTOR_BOOKMARK_MORE = `<li id="ca-bookmark-more" class="mw-list-item vector-menu-item--collapsible"><a data-mw-interface="" href="#" class="reading-lists-bookmark" title="Add this page to your saved items"><span class="vector-icon mw-ui-icon-bookmarkOutline mw-ui-icon-wikimedia-bookmarkOutline cdx-button__icon"></span>
<span>Save</span></a>
</li>`;
const HTML_MINERVA_BOOKMARK = `<li id="page-actions-bookmark" class="page-actions-menu__list-item">
		<a role="button" id="ca-bookmark" href="#" data-event-name="menu.bookmark" data-mw-interface="1" title="Add this page to your saved items" class="cdx-button cdx-button--size-large cdx-button--fake-button cdx-button--fake-button--enabled cdx-button--icon-only cdx-button--weight-quiet mw-list-item menu__item--page-actions-bookmark">
			<span class="minerva-icon minerva-icon--bookmarkOutline"></span>
<span>Save</span>
		</a>
</li>`;

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

// BookmarkCta.vue imports useIsMobileResolution from this module. The wire-up
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
		mockApp = { mount: vi.fn(), unmount: vi.fn() };
	} );

	afterEach( () => {
		document.body.innerHTML = '';
		vi.clearAllMocks();
	} );

	it( 'throws error when bookmark element is not found', () => {
		expect( () => init() ).toThrow( 'Bookmark not found' );
	} );

	const testBookmark = async ( html ) => {
		const bookmark = document.createElement( 'ul' );
		bookmark.innerHTML = html;
		document.body.appendChild( bookmark );

		const { createMwApp } = await import( 'vue' );
		createMwApp.mockReturnValue( mockApp );

		init();
		const bookmarkLink = bookmark.querySelector( 'a' );
		bookmarkLink.click();

		expect( createMwApp ).toHaveBeenCalled();
		expect( createMwApp.mock.calls[ 0 ][ 0 ].name ).toBe( 'BookmarkCta' );
		expect( createMwApp.mock.calls[ 0 ][ 1 ].anchor )
			.toBe( bookmarkLink.closest( '#ca-bookmark, #ca-bookmark-more' ) );
		expect( mockApp.mount ).toHaveBeenCalled();
	};

	it( 'mounts BookmarkCta when Vector bookmark is clicked', async () => {
		await testBookmark( HTML_VECTOR_BOOKMARK );
	} );

	it( 'mounts BookmarkCta when Vector bookmark is clicked in more menu', async () => {
		await testBookmark( HTML_VECTOR_BOOKMARK_MORE );
	} );

	it( 'mounts BookmarkCta when Minerva bookmark is clicked', async () => {
		await testBookmark( HTML_MINERVA_BOOKMARK );
	} );
} );
