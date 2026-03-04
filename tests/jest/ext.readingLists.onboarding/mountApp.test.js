jest.mock( 'vue', () => ( {
	createMwApp: jest.fn()
} ) );

const { createMwApp } = require( 'vue' );
const mountApp = require( '../../../resources/ext.readingLists.onboarding/mountApp.js' );

describe( 'mountApp', () => {
	let mockTarget;
	let mockApp;
	let mockComponent;

	beforeEach( () => {
		mockApp = { mount: jest.fn(), unmount: jest.fn() };
		createMwApp.mockReturnValue( mockApp );
		mockComponent = { name: 'MockComponent', template: '<div />' };

		mockTarget = document.createElement( 'div' );
		document.body.appendChild( mockTarget );

		Object.defineProperty( mockTarget, 'offsetParent', {
			get: () => document.body,
			configurable: true
		} );

		mockTarget.getBoundingClientRect = jest.fn( () => ( {
			top: 0,
			bottom: 100
		} ) );

		mw.message = jest.fn( () => ( { exists: () => true } ) );
		mw.storage = { set: jest.fn(), get: jest.fn() };
		mw.util = { throttle: jest.fn( ( fn ) => fn ) };

		global.IntersectionObserver = jest.fn( () => ( {
			observe: jest.fn(),
			disconnect: jest.fn()
		} ) );
	} );

	afterEach( () => {
		if ( mockTarget && mockTarget.parentNode ) {
			mockTarget.parentNode.removeChild( mockTarget );
		}
		jest.clearAllMocks();
	} );

	const defaultConfig = () => ( {
		component: mockComponent,
		target: mockTarget,
		storageKey: 'test-storage-key',
		titleMsgKey: 'readinglists-onboarding-title',
		bodyMsgKey: 'readinglists-onboarding-text'
	} );

	describe( 'onboarding popover mounting', () => {
		test( 'mounts the popover with correct component and props and sets local storage key', async () => {
			await mountApp( defaultConfig() );

			expect( createMwApp ).toHaveBeenCalledWith(
				mockComponent,
				expect.objectContaining( {
					bookmarkElement: mockTarget,
					titleMsgKey: 'readinglists-onboarding-title',
					bodyMsgKey: 'readinglists-onboarding-text'
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
			mockTarget.getBoundingClientRect = jest.fn( () => ( {
				top: -100,
				bottom: -10
			} ) );

			await mountApp( defaultConfig() );

			expect( createMwApp ).not.toHaveBeenCalled();
			expect( mw.storage.set ).not.toHaveBeenCalled();
		} );
	} );
} );
