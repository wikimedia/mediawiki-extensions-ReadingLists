const { mount } = require( '@vue/test-utils' );

import BookmarkCta from '../../../resources/ext.readingLists.common/BookmarkCta.vue';

mw.testKitchen = {
	compat: {
		getExperiment: vi.fn( () => ( {
			send: vi.fn() // Properly mock the `send` method
		} ) )
	}
};

function setViewportWidth( width ) {
	Object.defineProperty( window, 'innerWidth', {
		configurable: true,
		writable: true,
		value: width
	} );
}

function mountPopover() {
	return mount( BookmarkCta, {
		props: {
			anchor: document.createElement( 'button' )
		},
		attachTo: document.body
	} );
}

describe( 'BookmarkCta', () => {
	let wrapper;

	beforeEach( () => {
		setViewportWidth( 1024 );
	} );

	afterEach( () => {
		if ( wrapper ) {
			wrapper.unmount();
			wrapper = null;
		}
		document.body.innerHTML = '';
	} );

	test( 'matches the snapshot', () => {
		wrapper = mountPopover();

		expect( document.body.querySelector( '.readinglists-bookmark-cta' ) ).toMatchSnapshot();
	} );

	test( 'shows the illustration in the mobile bottom sheet', () => {
		setViewportWidth( 400 );
		wrapper = mountPopover();

		expect( document.body.querySelector( '.cdx-popover--bottom-sheet' ) ).not.toBeNull();
		expect( document.body.querySelector( '.readinglists-bookmark-cta__illustration' ) ).not.toBeNull();
	} );

	test( 'hides the illustration in the desktop popover', () => {
		wrapper = mountPopover();

		expect( document.body.querySelector( '.cdx-popover--bottom-sheet' ) ).toBeNull();
		expect( document.body.querySelector( '.readinglists-bookmark-cta__illustration' ) ).toBeNull();
	} );

	describe( 'remembers the page to save after log in or account creation', () => {
		beforeEach( () => {
			mw.config.get.mockImplementation( ( key ) => ( key === 'wgPageName' ? 'Test_Page' : undefined ) );
			mw.storage.session = { set: vi.fn() };
		} );

		test.each( [
			[ 'create account', 0 ],
			[ 'log in', 1 ]
		] )( 'when the %s link is clicked', async ( _label, index ) => {
			wrapper = mountPopover();

			const link = document.body.querySelectorAll( '.readinglists-bookmark-cta__actions a' )[ index ];
			link.click();

			expect( mw.storage.session.set ).toHaveBeenCalledWith(
				'readinglists-cta-autosave',
				'Test_Page',
				3600
			);
		} );
	} );
} );
