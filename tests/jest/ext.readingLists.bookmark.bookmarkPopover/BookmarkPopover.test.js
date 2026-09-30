const { mount } = require( '@vue/test-utils' );

const BookmarkPopover = require( '../../../resources/ext.readingLists.bookmark.bookmarkPopover/BookmarkPopover.vue' );

const configPopoverStub = {
	name: 'ConfigPopover',
	props: [ 'open' ],
	emits: [ 'update:open' ],
	template: '<div v-if="open"><slot name="header" /></div>'
};

describe( 'BookmarkPopover', () => {
	let onDismiss;
	let wrapper;

	beforeEach( () => {
		onDismiss = jest.fn();
		mw.message.mockReturnValue( { parse: jest.fn( () => 'Save success title' ) } );
	} );

	afterEach( () => {
		if ( wrapper ) {
			wrapper.unmount();
			wrapper = null;
		}
	} );

	function mountPopover( props = {} ) {
		wrapper = mount( BookmarkPopover, {
			props: {
				title: 'Test Page',
				isCurrentlySaved: false,
				onDismiss,
				...props
			},
			global: {
				stubs: {
					ConfigPopover: configPopoverStub,
					CdxButton: {
						name: 'CdxButton',
						template: '<button @click="$emit( \'click\' )"><slot /></button>',
						emits: [ 'click' ]
					},
					CdxIcon: {
						name: 'CdxIcon',
						props: [ 'icon' ],
						template: '<span />'
					}
				}
			}
		} );
	}

	describe( 'renders correctly', () => {
		test( 'when article is not yet saved', () => {
			mountPopover( { isCurrentlySaved: false } );
			expect( wrapper.html() ).toMatchSnapshot();
		} );

		test( 'when article is already saved', () => {
			mountPopover( { isCurrentlySaved: true } );
			expect( wrapper.html() ).toMatchSnapshot();
		} );
	} );

	describe( 'handleOpenChange', () => {
		test( 'calls onDismiss with false when the popover closes', () => {
			mountPopover();
			wrapper.vm.handleOpenChange( false );
			expect( onDismiss ).toHaveBeenCalledWith( false );
		} );

		test( 'does not call onDismiss when the popover emits open', () => {
			mountPopover();
			wrapper.vm.handleOpenChange( true );
			expect( onDismiss ).not.toHaveBeenCalled();
		} );
	} );
} );
