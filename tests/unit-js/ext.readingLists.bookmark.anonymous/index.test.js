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

const COMMON_MODULE = 'ext.readingLists.common';
const BookmarkCta = { name: 'BookmarkCta' };

async function flushPromises() {
	await new Promise( ( resolve ) => {
		setTimeout( resolve );
	} );
}

function addBookmark( html ) {
	const list = document.createElement( 'ul' );
	list.innerHTML = html;
	document.body.appendChild( list );
	return list.querySelector( 'a' );
}

describe( 'Anonymous bookmark button', () => {
	let mockApp;
	let createMwApp;

	beforeEach( () => {
		mockApp = { mount: vi.fn(), unmount: vi.fn() };
		createMwApp = vi.fn( () => mockApp );
		const modules = {
			vue: { createMwApp },
			[ COMMON_MODULE ]: { BookmarkCta }
		};
		mw.loader.using.mockImplementation( () => Promise.resolve( ( name ) => modules[ name ] ) );
	} );

	afterEach( () => {
		// Close every popover so the next test can open one again.
		createMwApp.mock.calls.forEach( ( [ , props ] ) => props.onClose() );
		mw.loader.using.mockReset();
		mw.loader.using.mockImplementation( () => Promise.resolve() );
		document.body.innerHTML = '';
		vi.clearAllMocks();
	} );

	it( 'throws error when bookmark element is not found', () => {
		expect( () => init() ).toThrow( 'Bookmark not found' );
	} );

	it( 'does not load ext.readingLists.common on page load', () => {
		addBookmark( HTML_VECTOR_BOOKMARK );
		init();

		expect( mw.loader.using ).not.toHaveBeenCalledWith( COMMON_MODULE );
	} );

	it.each( [
		[ 'Vector bookmark', HTML_VECTOR_BOOKMARK ],
		[ 'Vector bookmark in more menu', HTML_VECTOR_BOOKMARK_MORE ],
		[ 'Minerva bookmark', HTML_MINERVA_BOOKMARK ]
	] )( 'loads ext.readingLists.common and mounts BookmarkCta when the %s is clicked', async ( _label, html ) => {
		const bookmarkLink = addBookmark( html );
		init();

		bookmarkLink.click();
		await flushPromises();

		expect( mw.loader.using ).toHaveBeenCalledWith( COMMON_MODULE );
		expect( createMwApp ).toHaveBeenCalledTimes( 1 );
		expect( createMwApp.mock.calls[ 0 ][ 0 ] ).toBe( BookmarkCta );
		expect( createMwApp.mock.calls[ 0 ][ 1 ].anchor )
			.toBe( bookmarkLink.closest( '#ca-bookmark, #ca-bookmark-more' ) );
		expect( mockApp.mount ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'prevents navigation when the bookmark is clicked', () => {
		const bookmarkLink = addBookmark( HTML_VECTOR_BOOKMARK );
		init();

		const event = new MouseEvent( 'click', { bubbles: true, cancelable: true } );
		bookmarkLink.dispatchEvent( event );

		expect( event.defaultPrevented ).toBe( true );
	} );

	it( 'mounts BookmarkCta only once for a double click', async () => {
		const bookmarkLink = addBookmark( HTML_VECTOR_BOOKMARK );
		init();

		bookmarkLink.click();
		bookmarkLink.click();
		await flushPromises();

		expect( createMwApp ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'unmounts BookmarkCta when it closes and mounts it again on the next click', async () => {
		const bookmarkLink = addBookmark( HTML_VECTOR_BOOKMARK );
		init();

		bookmarkLink.click();
		await flushPromises();
		createMwApp.mock.calls[ 0 ][ 1 ].onClose();

		expect( mockApp.unmount ).toHaveBeenCalledTimes( 1 );

		bookmarkLink.click();
		await flushPromises();

		expect( createMwApp ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'logs an error and allows a new click when ext.readingLists.common does not load', async () => {
		const bookmarkLink = addBookmark( HTML_VECTOR_BOOKMARK );
		init();
		mw.loader.using.mockImplementationOnce( () => Promise.reject( new Error( 'Network error' ) ) );

		bookmarkLink.click();
		await flushPromises();

		expect( mw.log.error ).toHaveBeenCalled();
		expect( createMwApp ).not.toHaveBeenCalled();

		bookmarkLink.click();
		await flushPromises();

		expect( createMwApp ).toHaveBeenCalledTimes( 1 );
	} );
} );
