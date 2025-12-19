const { mount, config } = require( '@vue/test-utils' );

const MobileOnboardingPopover = require( '../../../resources/ext.readingLists.onboarding/components/MobileOnboardingPopover.vue' );

describe( 'MobileOnboardingPopover', () => {
	let mockBookmarkElement;
	let mockOnDismiss;
	let wrapper;

	config.global.stubs = {
		teleport: true,
		CdxPopover: {
			template: `
				<div class="cdx-popover" v-if="open" role="dialog">
					<header class="cdx-popover__header">
						<span class="cdx-popover__header__title">{{ title }}</span>
					</header>
					<div class="cdx-popover__body"><slot /></div>
					<footer class="cdx-popover__footer" v-if="primaryAction">
						<button class="cdx-popover__footer__primary-action"
							@click="$emit('primary')">
							{{ primaryAction.label }}
						</button>
					</footer>
				</div>
			`,
			props: [ 'open', 'anchor', 'placement', 'title', 'primaryAction', 'renderInPlace' ],
			emits: [ 'update:open', 'primary' ]
		}
	};

	const defaultProps = () => ( {
		bookmarkElement: mockBookmarkElement,
		titleMsgKey: 'readinglists-mobile-onboarding-popover-title',
		bodyMsgKey: 'readinglists-mobile-onboarding-popover-body',
		onDismiss: mockOnDismiss
	} );

	beforeEach( () => {
		mockBookmarkElement = document.createElement( 'div' );
		mockBookmarkElement.id = 'mock-bookmark-element';
		document.body.appendChild( mockBookmarkElement );
		mockOnDismiss = jest.fn();
		mw.msg = jest.fn( ( key ) => key );
	} );

	afterEach( () => {
		if ( wrapper ) {
			wrapper.unmount();
			wrapper = null;
		}
		if ( mockBookmarkElement && mockBookmarkElement.parentNode ) {
			mockBookmarkElement.parentNode.removeChild( mockBookmarkElement );
		}
		jest.clearAllMocks();
	} );

	describe( 'rendering behavior', () => {
		test( 'matches the snapshot', async () => {
			wrapper = mount( MobileOnboardingPopover, {
				props: defaultProps()
			} );
			await wrapper.vm.$nextTick();

			expect( wrapper.element ).toMatchSnapshot();
		} );

		test( 'renders popover content when the popover is open', async () => {
			wrapper = mount( MobileOnboardingPopover, {
				props: defaultProps()
			} );
			await wrapper.vm.$nextTick();

			expect( wrapper.vm.isOpen ).toBe( true );
			expect( wrapper.find( '.cdx-popover' ).exists() ).toBe( true );
			expect( wrapper.find( '.cdx-popover__header__title' ).exists() ).toBe( true );
			expect( wrapper.find( '.cdx-popover__footer__primary-action' ).exists() ).toBe( true );
		} );
	} );

	describe( 'popover dismiss behavior', () => {
		test( 'closes popover and calls onDismiss when user clicks the primary action', async () => {
			wrapper = mount( MobileOnboardingPopover, {
				props: defaultProps()
			} );
			await wrapper.vm.$nextTick();

			expect( wrapper.vm.isOpen ).toBe( true );

			const primaryButton = wrapper.find( '.cdx-popover__footer__primary-action' );
			await primaryButton.trigger( 'click' );

			expect( wrapper.vm.isOpen ).toBe( false );
			expect( mockOnDismiss ).toHaveBeenCalled();
		} );

		test( 'calls onDismiss when user clicks outside the popover', async () => {
			wrapper = mount( MobileOnboardingPopover, {
				props: defaultProps()
			} );
			await wrapper.vm.$nextTick();

			wrapper.vm.handleOpenChange( false );

			expect( mockOnDismiss ).toHaveBeenCalled();
		} );
	} );
} );
