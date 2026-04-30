const BOOKMARK_WRAPPER_SELECTOR = '#page-actions-bookmark';
const STORAGE_KEY_IMPRESSION = 'we-reading-list-cta-pulsating-dot-impression';
const EXPERIMENT_END_TIMESTAMP = Date.parse( '2026-06-20T00:00:00Z' );

/**
 * @return {number|null}
 */
function getSecondsUntilExperimentEnd() {
	const now = Date.now();
	if ( now >= EXPERIMENT_END_TIMESTAMP ) {
		return null;
	}
	return Math.floor( ( EXPERIMENT_END_TIMESTAMP - now ) / 1000 );
}

/**
 * @param {HTMLElement} bookmarkWrapper
 * @param {number} secondsUntilExperimentEnd
 */
function setUpPulsatingDot( bookmarkWrapper, secondsUntilExperimentEnd ) {
	bookmarkWrapper.classList.add( 'mw-pulsating-dot' );
	mw.storage.set( STORAGE_KEY_IMPRESSION, '1', secondsUntilExperimentEnd );
	bookmarkWrapper.addEventListener( 'click', () => {
		bookmarkWrapper.classList.remove( 'mw-pulsating-dot' );
	}, { once: true } );
}

function initializePulsatingDot() {
	// Treatment assignment is handled in HookHandler before this module is loaded.
	const secondsUntilExperimentEnd = getSecondsUntilExperimentEnd();
	const bookmarkWrapper = document.querySelector( BOOKMARK_WRAPPER_SELECTOR );

	if (
		secondsUntilExperimentEnd !== null &&
		mw.storage.get( STORAGE_KEY_IMPRESSION ) !== '1' &&
		bookmarkWrapper instanceof HTMLElement
	) {
		return mw.loader.using( [ 'mediawiki.pulsatingdot' ] )
			.then( () => {
				setUpPulsatingDot( bookmarkWrapper, secondsUntilExperimentEnd );
			} )
			.catch( ( error ) => {
				mw.log( 'Error loading mediawiki.pulsatingdot module:', error );
			} );
	}

	return Promise.resolve();
}

module.exports = { initializePulsatingDot };
