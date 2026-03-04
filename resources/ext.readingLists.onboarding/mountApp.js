const { createMwApp } = require( 'vue' );

/**
 * @param {Object} config Configuration object for the onboarding popover.
 * @param {Object} config.component Vue component to create and render in showPopover.
 * @param {Element} config.target DOM element to anchor the popover to.
 * @param {string} config.storageKey Local storage key for popover display status.
 * @param {string} config.titleMsgKey i18n message key for popover title.
 * @param {string} config.bodyMsgKey i18n message key for popover body text.
 * @param {string|null} [config.bannerImagePath] Path to banner image (desktop only).
 * @return {Promise<void>}
 */
async function mountApp( config ) {
	if ( !config ) {
		throw new Error( 'mountApp requires a config object' );
	}

	validateConfig( config );

	const { component, target, storageKey, titleMsgKey, bodyMsgKey, bannerImagePath } = config;

	if ( !target || !target.offsetParent ) {
		return;
	}

	const rect = target.getBoundingClientRect();
	const isInViewport = rect.top >= 0 && rect.bottom <= window.innerHeight;

	if ( !isInViewport ) {
		return;
	}

	showPopover( component, target, titleMsgKey, bodyMsgKey, bannerImagePath );

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

	if ( !config.component ) {
		throw new Error( 'config must include a component' );
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

	if ( !mw.message( config.titleMsgKey ).exists() ) {
		throw new Error( `Message key "${ config.titleMsgKey }" does not exist` );
	}

	if ( !mw.message( config.bodyMsgKey ).exists() ) {
		throw new Error( `Message key "${ config.bodyMsgKey }" does not exist` );
	}
}

/**
 * @param {Object} component Vue component to render
 * @param {Element} anchorElement
 * @param {string} titleMsgKey
 * @param {string} bodyMsgKey
 * @param {string} bannerImagePath
 */
function showPopover( component, anchorElement, titleMsgKey, bodyMsgKey, bannerImagePath ) {
	const container = document.createElement( 'div' );
	container.classList.add( 'reading-lists-onboarding-container' );
	document.body.appendChild( container );

	const props = {
		bookmarkElement: anchorElement,
		titleMsgKey,
		bodyMsgKey,
		onDismiss: removePopover
	};
	if ( bannerImagePath ) {
		const img = new Image();
		img.src = bannerImagePath;
		props.bannerImagePath = bannerImagePath;
	}

	const app = createMwApp( component, props );

	function handleResize() {
		if ( !anchorElement.offsetParent ) {
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
		anchorElement.removeEventListener( 'click', removePopover );
		observer.disconnect();
	}

	observer.observe( anchorElement );
	window.addEventListener( 'resize', throttledHandleResize );

	anchorElement.addEventListener( 'click', removePopover );

	app.mount( container );
}

module.exports = mountApp;
