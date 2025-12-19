const { createMwApp } = require( 'vue' );
const OnboardingPopover = require( './components/OnboardingPopover.vue' );
const MobileOnboardingPopover = require( './components/MobileOnboardingPopover.vue' );

/**
 * @param {Object} config Configuration object for the onboarding popover.
 * @param {Element} config.target DOM element to anchor the popover to.
 * @param {string} config.storageKey Local storage key for popover display status.
 * @param {string} config.titleMsgKey i18n message key for popover title.
 * @param {string} config.bodyMsgKey i18n message key for popover body text.
 * @param {string} config.bannerImagePath Path to banner image.
 * @return {Promise<void>}
 */
async function mountApp( config ) {
	if ( !config ) {
		throw new Error( 'mountApp requires a config object' );
	}

	validateConfig( config );

	const { target, storageKey, titleMsgKey, bodyMsgKey, bannerImagePath, skinName } = config;

	const bookmarkElement = target;

	if ( !bookmarkElement || !bookmarkElement.offsetParent ) {
		return;
	}

	if ( bookmarkElement.dataset.mwEntryId ) {
		return;
	}

	const rect = bookmarkElement.getBoundingClientRect();
	const isInViewport = rect.top >= 0 && rect.bottom <= window.innerHeight;

	if ( !isInViewport ) {
		return;
	}

	showPopover( bookmarkElement, titleMsgKey, bodyMsgKey, bannerImagePath, skinName );

	// approximately 6 months expiration time
	mw.storage.set( storageKey, true, 60 * 60 * 24 * 180 );
}

/**
 * @param {Object} config
 * @throws {Error}
 */
function validateConfig( config ) {
	if ( !config || typeof config !== 'object' ) {
		throw new Error( 'config must be an object' );
	}

	if ( !config.target || !( config.target instanceof Element ) ) {
		throw new Error( 'config must include a valid target element' );
	}

	if ( !config.storageKey || typeof config.storageKey !== 'string' ) {
		throw new Error( 'config.storageKey must be a string' );
	}

	if ( !config.titleMsgKey || typeof config.titleMsgKey !== 'string' ) {
		throw new Error( 'config.titleMsgKey must be a string' );
	}

	if ( !config.bodyMsgKey || typeof config.bodyMsgKey !== 'string' ) {
		throw new Error( 'config.bodyMsgKey must be a string' );
	}

	if ( !config.bannerImagePath || typeof config.bannerImagePath !== 'string' ) {
		throw new Error( 'config.bannerImagePath must be a string' );
	}

	if ( !mw.message( config.titleMsgKey ).exists() ) {
		throw new Error( `Message key "${ config.titleMsgKey }" does not exist` );
	}

	if ( !mw.message( config.bodyMsgKey ).exists() ) {
		throw new Error( `Message key "${ config.bodyMsgKey }" does not exist` );
	}

	if ( !config.skinName || typeof config.skinName !== 'string' ) {
		throw new Error( 'config.skinName must be a string' );
	}
}

/**
 * @param {Element} bookmarkElement
 * @param {string} titleMsgKey
 * @param {string} bodyMsgKey
 * @param {string} bannerImagePath
 * @param {string} skinName
 */
function showPopover( bookmarkElement, titleMsgKey, bodyMsgKey, bannerImagePath, skinName ) {
	const isMinerva = skinName === 'minerva';

	const container = document.createElement( 'div' );
	container.classList.add( 'reading-lists-onboarding-container' );
	document.body.appendChild( container );

	const component = isMinerva ? MobileOnboardingPopover : OnboardingPopover;
	const props = {
		bookmarkElement,
		titleMsgKey,
		bodyMsgKey,
		onDismiss: removePopover
	};
	if ( !isMinerva ) {
		const img = new Image();
		img.src = bannerImagePath;
		props.bannerImagePath = bannerImagePath;
	}

	const app = createMwApp( component, props );

	function handleResize() {
		if ( !bookmarkElement.offsetParent ) {
			removePopover();
		}
	}

	const throttledHandleResize = mw.util.throttle( handleResize, 250 );

	const observer = new IntersectionObserver( ( entries ) => {
		entries.forEach( ( entry ) => {
			if ( !entry.isIntersecting ) {
				removePopover();
			}
		} );
	}, {
		threshold: 0.9
	} );

	function removePopover() {
		app.unmount();
		container.remove();
		window.removeEventListener( 'resize', throttledHandleResize );
		bookmarkElement.removeEventListener( 'click', removePopover );
		observer.disconnect();
	}

	observer.observe( bookmarkElement );
	window.addEventListener( 'resize', throttledHandleResize );

	bookmarkElement.addEventListener( 'click', removePopover );

	app.mount( container );
}

/**
 * @param {string} targetSelector CSS selector for the element to anchor popover to
 * @param {string} storageKey Local storage key for popover display status.
 * @param {string} titleMsgKey i18n message key for popover title
 * @param {string} bodyMsgKey i18n message key for popover body text
 * @param {string} bannerImagePath Path to banner image
 * @param {string} skinName Name of the skin
 */
function initOnboardingPopover(
	targetSelector,
	storageKey,
	titleMsgKey,
	bodyMsgKey,
	bannerImagePath,
	skinName
) {
	const targetElement = document.querySelector( targetSelector );

	if ( !targetElement ) {
		return;
	}

	if ( mw.storage.get( storageKey ) ) {
		return;
	}

	if ( skinName !== 'vector-2022' && skinName !== 'minerva' ) {
		return;
	}

	setTimeout( () => {
		mw.requestIdleCallback( () => {
			mw.loader.using( 'ext.readingLists.onboarding' ).then( () => {
				const mountAppFn = require( 'ext.readingLists.onboarding' );
				try {
					mountAppFn( {
						target: targetElement,
						storageKey,
						titleMsgKey,
						bodyMsgKey,
						bannerImagePath,
						skinName
					} ).catch( ( error ) => {
						mw.log.error( 'Failed to mount onboarding popover:', error );
					} );
				} catch ( error ) {
					mw.log.error( 'Failed to mount onboarding popover:', error );
				}
			} );
		}, { timeout: 2000 } );
	}, 1000 );
}

module.exports = mountApp;
module.exports.initOnboardingPopover = initOnboardingPopover;
