const { createMwApp } = require( 'vue' );
const OnboardingPopover = require( './components/OnboardingPopover.vue' );

const LOCAL_STORAGE_KEY = 'readinglists-bookmark-dialog-seen';

async function mountApp() {
	if ( mw.storage.get( LOCAL_STORAGE_KEY ) ) {
		return;
	}

	const bookmarkElement = document.querySelector( '#ca-bookmark' );

	if ( !bookmarkElement || !bookmarkElement.offsetParent ) {
		return;
	}

	const rect = bookmarkElement.getBoundingClientRect();
	const isInViewport = rect.top >= 0 && rect.bottom <= window.innerHeight;

	if ( !isInViewport ) {
		return;
	}

	showPopover( bookmarkElement );

	// approximately 6 months expiration time
	mw.storage.set( LOCAL_STORAGE_KEY, true, 60 * 60 * 24 * 180 );
}

function showPopover( bookmarkElement ) {
	const container = document.createElement( 'div' );
	container.classList.add( 'reading-lists-onboarding-container' );
	document.body.appendChild( container );

	const app = createMwApp( OnboardingPopover, {
		bookmarkElement,
		onDismiss: removePopover
	} );

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
		observer.disconnect();
	}

	observer.observe( bookmarkElement );
	window.addEventListener( 'resize', throttledHandleResize );
	app.mount( container );
}

module.exports = mountApp;
