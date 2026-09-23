const { ref } = require( 'vue' );
const { mount } = require( '@vue/test-utils' );

// Mock useResizeObserver since native ResizeObserver never fires.
jest.mock( '@wikimedia/codex', () => {
	const actual = jest.requireActual( '@wikimedia/codex' );
	return {
		...actual,
		useResizeObserver: jest.fn( () => ref( { height: 100 } ) )
	};
} );

const ConfigPopover = require( '../../../resources/ext.readingLists.configPopover/ConfigPopover.vue' );

// Stub that exposes a `panel` element so ConfigPopover's onMounted can set
// popoverEl = popover.value.panel without throwing.
const cdxPopoverStub = {
	name: 'CdxPopover',
	template: '<div class="cdx-popover" v-if="open"><slot /></div>',
	props: [ 'open', 'useBottomSheet', 'hideArrow', 'doNotPosition' ],
	emits: [ 'update:open' ],
	setup() {
		const panel = document.createElement( 'div' );
		panel.className = 'cdx-popover';
		return { panel };
	}
};

describe( 'ConfigPopover', () => {
	let wrapper;

	function mountPopover( props = {}, slots = {} ) {
		wrapper = mount( ConfigPopover, {
			props: { open: true, ...props },
			slots,
			global: {
				stubs: {
					teleport: true,
					CdxPopover: cdxPopoverStub
				}
			}
		} );
	}

	beforeEach( () => {
		mw.config.get.mockReturnValue( 'vector' );
	} );

	afterEach( () => {
		if ( wrapper ) {
			wrapper.unmount();
			wrapper = null;
		}
	} );

	describe( 'rendering', () => {
		test( 'matches the snapshot', async () => {
			mountPopover();
			await wrapper.vm.$nextTick();
			expect( wrapper.element ).toMatchSnapshot();
		} );

		test( 'renders slot content', async () => {
			mountPopover( {}, { default: '<p class="slot-content">Test content</p>' } );
			await wrapper.vm.$nextTick();
			expect( wrapper.find( '.slot-content' ).exists() ).toBe( true );
		} );
	} );

	describe( 'useBottomSheet', () => {
		test( 'is "responsive" when skin is not Minerva', () => {
			mountPopover();
			expect( wrapper.vm.useBottomSheet ).toBe( 'responsive' );
		} );

		test( 'is "always" when skin is Minerva', () => {
			mw.config.get.mockReturnValue( 'minerva' );
			mountPopover();
			expect( wrapper.vm.useBottomSheet ).toBe( 'always' );
		} );
	} );

	describe( 'update:open event', () => {
		test( 'emits "update:open" with false when the popover closes', () => {
			mountPopover();
			wrapper.vm.onUpdateOpen( false );
			const emitted = wrapper.emitted( 'update:open' );
			expect( emitted ).toBeTruthy();
			expect( emitted[ emitted.length - 1 ] ).toEqual( [ false ] );
		} );

		test( 'emits "update:open" with true when the popover opens', () => {
			mountPopover( { open: false } );
			wrapper.vm.onUpdateOpen( true );
			const emitted = wrapper.emitted( 'update:open' );
			expect( emitted[ emitted.length - 1 ] ).toEqual( [ true ] );
		} );
	} );

	describe( 'notification area styles', () => {
		let notificationArea;

		beforeEach( () => {
			notificationArea = document.createElement( 'div' );
			notificationArea.id = 'mw-notification-area';
			document.body.appendChild( notificationArea );
		} );

		afterEach( () => {
			if ( notificationArea && notificationArea.parentNode ) {
				notificationArea.parentNode.removeChild( notificationArea );
			}
		} );

		test( 'sets top, position, and transform on the notification area when the popover opens', async () => {
			mountPopover( { open: true } );
			await wrapper.vm.$nextTick();

			// top = mocked height (100) + spacing-400 offset (64) = 164px
			expect( notificationArea.style.top ).toBe( '164px' );
			expect( notificationArea.style.position ).toBe( 'fixed' );
			expect( notificationArea.style.transform ).toBe( 'translateX(-50%)' );
		} );

		test( 'restores notification area styles when the popover closes via user interaction', async () => {
			mountPopover( { open: true } );
			await wrapper.vm.$nextTick();

			wrapper.vm.onUpdateOpen( false );

			expect( notificationArea.style.top ).toBe( '' );
			expect( notificationArea.style.position ).toBe( '' );
			expect( notificationArea.style.transform ).toBe( '' );
		} );

		test( 'restores notification area styles when the open prop changes to false', async () => {
			mountPopover( { open: true } );
			await wrapper.vm.$nextTick();

			await wrapper.setProps( { open: false } );
			await wrapper.vm.$nextTick();

			expect( notificationArea.style.top ).toBe( '' );
			expect( notificationArea.style.position ).toBe( '' );
			expect( notificationArea.style.transform ).toBe( '' );
		} );

		test( 'restores notification area styles on unmount', async () => {
			mountPopover( { open: true } );
			await wrapper.vm.$nextTick();

			wrapper.unmount();
			wrapper = null;

			expect( notificationArea.style.top ).toBe( '' );
			expect( notificationArea.style.position ).toBe( '' );
			expect( notificationArea.style.transform ).toBe( '' );
		} );
	} );

	describe( 'MutationObserver', () => {
		test( 'picks up a notification area that is dynamically added to the page', async () => {
			mountPopover( { open: true } );
			await wrapper.vm.$nextTick();

			const notificationArea = document.createElement( 'div' );
			notificationArea.id = 'mw-notification-area';
			document.body.appendChild( notificationArea );

			// MutationObserver callbacks are microtasks; flush them.
			// eslint-disable-next-line no-promise-executor-return
			await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
			await wrapper.vm.$nextTick();

			expect( notificationArea.style.top ).toBe( '164px' );

			document.body.removeChild( notificationArea );
		} );
	} );

	describe( 'window resize handling', () => {
		let notificationArea;

		beforeEach( () => {
			notificationArea = document.createElement( 'div' );
			notificationArea.id = 'mw-notification-area';
			document.body.appendChild( notificationArea );
		} );

		afterEach( () => {
			if ( notificationArea && notificationArea.parentNode ) {
				notificationArea.parentNode.removeChild( notificationArea );
			}
			Object.defineProperty( window, 'innerWidth', { writable: true, configurable: true, value: 1024 } );
		} );

		test( 'adds a resize event listener on mount when skin is not Minerva', () => {
			const addEventListenerSpy = jest.spyOn( window, 'addEventListener' );
			mountPopover();

			const resizeCalls = addEventListenerSpy.mock.calls.filter( ( [ event ] ) => event === 'resize' );
			expect( resizeCalls ).toHaveLength( 1 );

			addEventListenerSpy.mockRestore();
		} );

		test( 'removes the resize event listener on unmount when skin is not Minerva', () => {
			const removeEventListenerSpy = jest.spyOn( window, 'removeEventListener' );
			mountPopover();
			wrapper.unmount();
			wrapper = null;

			const resizeCalls = removeEventListenerSpy.mock.calls.filter( ( [ event ] ) => event === 'resize' );
			expect( resizeCalls ).toHaveLength( 1 );

			removeEventListenerSpy.mockRestore();
		} );

		test( 'does not add a resize event listener on Minerva', () => {
			const addEventListenerSpy = jest.spyOn( window, 'addEventListener' );
			mw.config.get.mockReturnValue( 'minerva' );
			mountPopover();

			const resizeCalls = addEventListenerSpy.mock.calls.filter( ( [ event ] ) => event === 'resize' );
			expect( resizeCalls ).toHaveLength( 0 );

			addEventListenerSpy.mockRestore();
		} );

		test( 'restores notification area styles on resize below the mobile breakpoint', async () => {
			Object.defineProperty( window, 'innerWidth', { writable: true, configurable: true, value: 1024 } );
			mountPopover( { open: true } );
			await wrapper.vm.$nextTick();

			expect( notificationArea.style.top ).toBe( '164px' );

			Object.defineProperty( window, 'innerWidth', { value: 400 } );
			window.dispatchEvent( new Event( 'resize' ) );
			await wrapper.vm.$nextTick();

			expect( notificationArea.style.top ).toBe( '' );
		} );

		test( 'reapplies notification area styles on resize back above the mobile breakpoint', async () => {
			Object.defineProperty( window, 'innerWidth', { writable: true, configurable: true, value: 1024 } );
			mountPopover( { open: true } );
			await wrapper.vm.$nextTick();

			// Resize below mobile to clear styles
			Object.defineProperty( window, 'innerWidth', { value: 400 } );
			window.dispatchEvent( new Event( 'resize' ) );
			await wrapper.vm.$nextTick();

			expect( notificationArea.style.top ).toBe( '' );

			// Resize back above mobile to reapply styles
			Object.defineProperty( window, 'innerWidth', { value: 1024 } );
			window.dispatchEvent( new Event( 'resize' ) );
			await wrapper.vm.$nextTick();

			expect( notificationArea.style.top ).toBe( '164px' );
		} );
	} );
} );
