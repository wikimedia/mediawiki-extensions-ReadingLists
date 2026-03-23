const { mount } = require( '@vue/test-utils' );

const ConfirmUnsavePopover = require( '../../../resources/ext.readingLists.bookmark.confirmPopover/ConfirmUnsavePopover.vue' );

const cdxPopoverStub = {
	name: 'CdxPopover',
	template: `
		<div class="cdx-popover" v-if="open" role="dialog" :data-placement="placement">
			<header class="cdx-popover__header">{{ title }}</header>
			<div class="cdx-popover__body"><slot /></div>
			<footer class="cdx-popover__footer">
				<button class="cdx-popover__footer__primary" @click="$emit( 'primary' )">
					{{ primaryAction.label }}
				</button>
				<button class="cdx-popover__footer__default" @click="$emit( 'default' )">
					{{ defaultAction.label }}
				</button>
			</footer>
		</div>
	`,
	props: [ 'open', 'anchor', 'title', 'primaryAction', 'defaultAction', 'placement' ],
	emits: [ 'primary', 'default', 'update:open' ]
};

describe( 'ConfirmUnsavePopover', () => {
	let wrapper;
	let onConfirm;
	let onCancel;

	function mountPopover() {
		wrapper = mount( ConfirmUnsavePopover, {
			props: {
				anchorElement: document.createElement( 'button' ),
				isMinerva: false,
				onConfirm,
				onCancel
			},
			global: {
				stubs: {
					teleport: true,
					CdxPopover: cdxPopoverStub
				}
			}
		} );
	}

	beforeEach( () => {
		onConfirm = jest.fn();
		onCancel = jest.fn();
	} );

	afterEach( () => {
		if ( wrapper ) {
			wrapper.unmount();
			wrapper = null;
		}
		jest.clearAllMocks();
	} );

	describe( 'ConfirmUnsavePopover rendering', () => {
		test( 'matches the snapshot', async () => {
			mountPopover();
			await wrapper.vm.$nextTick();

			expect( wrapper.element ).toMatchSnapshot();
		} );
	} );

	describe( 'ConfirmUnsavePopover actions', () => {
		test( 'calls onConfirm when the primary action is clicked', async () => {
			mountPopover();
			await wrapper.vm.$nextTick();

			await wrapper.find( '.cdx-popover__footer__primary' ).trigger( 'click' );

			expect( onConfirm ).toHaveBeenCalled();
			expect( onCancel ).not.toHaveBeenCalled();
		} );

		test( 'calls onCancel when the default action is clicked', async () => {
			mountPopover();
			await wrapper.vm.$nextTick();

			await wrapper.find( '.cdx-popover__footer__default' ).trigger( 'click' );

			expect( onCancel ).toHaveBeenCalled();
			expect( onConfirm ).not.toHaveBeenCalled();
		} );

		test( 'calls onCancel when the popover is closed externally', async () => {
			mountPopover();
			await wrapper.vm.$nextTick();

			wrapper.findComponent( cdxPopoverStub ).vm.$emit( 'update:open', false );

			expect( onCancel ).toHaveBeenCalled();
			expect( onConfirm ).not.toHaveBeenCalled();
		} );
	} );
} );
