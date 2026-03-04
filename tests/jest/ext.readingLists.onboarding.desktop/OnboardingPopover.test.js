const { mount, config } = require( '@vue/test-utils' );

const OnboardingPopover = require( '../../../resources/ext.readingLists.onboarding.desktop/OnboardingPopover.vue' );

describe( 'OnboardingPopover', () => {
	let mockBookmarkElement;
	let mockOnDismiss;
	let wrapper;

	config.global.stubs = {
		teleport: true,
		CdxPopover: {
			template: `
				<div class="cdx-popover" v-if="open" role="dialog"
					aria-labelledby="readinglists-onboarding-title"
					aria-describedby="readinglists-onboarding-text">
					<header class="cdx-popover__header"><slot name="header" /></header>
					<div class="cdx-popover__body"><slot /></div>
				</div>
			`,
			props: [ 'open', 'anchor', 'placement' ],
			emits: [ 'update:open' ]
		}
	};

	const defaultProps = () => ( {
		bookmarkElement: mockBookmarkElement,
		titleMsgKey: 'readinglists-onboarding-title',
		bodyMsgKey: 'readinglists-onboarding-text',
		bannerImagePath: '/path/to/banner.svg',
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
			wrapper = mount( OnboardingPopover, {
				props: defaultProps()
			} );
			await wrapper.vm.$nextTick();

			expect( wrapper.element ).toMatchSnapshot();
		} );

		test( 'renders with custom title, body message keys, and banner image', async () => {
			wrapper = mount( OnboardingPopover, {
				props: {
					...defaultProps(),
					titleMsgKey: 'readinglists-onboarding-saved-pages-title',
					bodyMsgKey: 'readinglists-onboarding-saved-pages-text'
				}
			} );
			await wrapper.vm.$nextTick();

			expect( wrapper.find( '.readinglists-onboarding-title' ).text() )
				.toBe( 'readinglists-onboarding-saved-pages-title' );
			expect( wrapper.find( '.readinglists-onboarding-text' ).text() )
				.toBe( 'readinglists-onboarding-saved-pages-text' );

			const banner = wrapper.find( '.readinglists-onboarding-banner' );
			expect( banner.attributes( 'style' ) )
				.toContain( '/path/to/banner.svg' );
		} );

		test( 'renders close button with aria-label', async () => {
			wrapper = mount( OnboardingPopover, {
				props: defaultProps()
			} );
			await wrapper.vm.$nextTick();

			const closeButton = wrapper.find( '.readinglists-onboarding-close-button' );
			expect( closeButton.exists() ).toBe( true );
			expect( closeButton.attributes( 'aria-label' ) )
				.toBe( 'readinglists-onboarding-close-button' );
		} );
	} );

	describe( 'popover dismiss behavior', () => {
		test( 'closes popover and calls onDismiss when close button is clicked', async () => {
			wrapper = mount( OnboardingPopover, {
				props: defaultProps()
			} );
			await wrapper.vm.$nextTick();

			expect( wrapper.find( '.cdx-popover' ).exists() ).toBe( true );

			const closeButton = wrapper.find( '.readinglists-onboarding-close-button' );
			await closeButton.trigger( 'click' );

			expect( wrapper.vm.isOpen ).toBe( false );
			expect( mockOnDismiss ).toHaveBeenCalled();
		} );

		test( 'calls onDismiss when popover is closed externally', async () => {
			wrapper = mount( OnboardingPopover, {
				props: defaultProps()
			} );
			await wrapper.vm.$nextTick();

			wrapper.vm.handleOpenChange( false );

			expect( mockOnDismiss ).toHaveBeenCalled();
		} );
	} );
} );
