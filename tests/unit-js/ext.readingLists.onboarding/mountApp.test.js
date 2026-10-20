const { createMwApp } = vi.hoisted( () => ( {
	createMwApp: vi.fn()
} ) );

// mountApp.js's `const vue = require( 'vue' )` is hoisted to a default import.
vi.mock( 'vue', () => ( {
	default: { createMwApp }
} ) );

import mountApp from '../../../resources/ext.readingLists.onboarding/mountApp.js';

describe( 'mountApp', () => {
	let mockTarget;
	let mockApp;
	let mockComponent;

	beforeEach( () => {
		mockApp = { mount: vi.fn(), unmount: vi.fn() };
		createMwApp.mockReturnValue( mockApp );
		mockComponent = { name: 'MockComponent', template: '<div />' };

		mockTarget = document.createElement( 'div' );
		document.body.appendChild( mockTarget );

		Object.defineProperty( mockTarget, 'offsetParent', {
			get: () => document.body,
			configurable: true
		} );

		mockTarget.getBoundingClientRect = vi.fn( () => ( {
			top: 0,
			bottom: 100
		} ) );

		mw.message = vi.fn( () => ( { exists: () => true } ) );
		mw.storage = { set: vi.fn(), get: vi.fn() };
		mw.util = { throttle: vi.fn( ( fn ) => fn ) };

		global.IntersectionObserver = vi.fn( function () {
			this.observe = vi.fn();
			this.disconnect = vi.fn();
		} );
	} );

	afterEach( () => {
		if ( mockTarget && mockTarget.parentNode ) {
			mockTarget.parentNode.removeChild( mockTarget );
		}
		vi.clearAllMocks();
	} );

	const defaultConfig = () => ( {
		component: mockComponent,
		target: mockTarget,
		storageKey: 'test-storage-key',
		titleMsgKey: 'readinglists-onboarding-saved-pages-title',
		bodyMsgKey: 'readinglists-onboarding-saved-pages-text'
	} );

	describe( 'onboarding popover mounting', () => {
		test( 'mounts the popover with correct component and props and sets local storage key', async () => {
			await mountApp( defaultConfig() );

			expect( createMwApp ).toHaveBeenCalledWith(
				mockComponent,
				expect.objectContaining( {
					bookmarkElement: mockTarget,
					titleMsgKey: 'readinglists-onboarding-saved-pages-title',
					bodyMsgKey: 'readinglists-onboarding-saved-pages-text'
				} )
			);
			expect( mockApp.mount ).toHaveBeenCalled();

			expect( mw.storage.set ).toHaveBeenCalledWith(
				'test-storage-key', true, expect.any( Number )
			);
		} );

		test( 'bannerImagePath is passed as prop when provided', async () => {
			await mountApp( { ...defaultConfig(), bannerImagePath: '/path/to/banner.svg' } );

			expect( createMwApp ).toHaveBeenCalledWith(
				mockComponent,
				expect.objectContaining( {
					bannerImagePath: '/path/to/banner.svg'
				} )
			);
		} );
	} );

	describe( 'popover not in viewport', () => {
		test( 'does not mount when target is above the viewport', async () => {
			mockTarget.getBoundingClientRect = vi.fn( () => ( {
				top: -100,
				bottom: -10
			} ) );

			await mountApp( defaultConfig() );

			expect( createMwApp ).not.toHaveBeenCalled();
			expect( mw.storage.set ).not.toHaveBeenCalled();
		} );
	} );
} );
