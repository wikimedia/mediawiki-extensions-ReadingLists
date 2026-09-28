const BOOKMARK_WRAPPER_SELECTOR = '#page-actions-bookmark';
const STORAGE_KEY_IMPRESSION = 'we-reading-list-cta-pulsating-dot-impression';
const { ReadingListsMinervaCTAShowDot } = require( './config.json' );

/**
 * @param {HTMLElement} bookmarkWrapper
 */
function setUpPulsatingDot( bookmarkWrapper ) {
	bookmarkWrapper.classList.add( 'mw-pulsating-dot' );
	bookmarkWrapper.addEventListener( 'click', () => {
		// Set expiry to ~3 months since we intend to show the dot for 2.
		mw.storage.set( STORAGE_KEY_IMPRESSION, '1', 60 * 60 * 24 * 90 );
		bookmarkWrapper.classList.remove( 'mw-pulsating-dot' );
	}, { once: true } );
}

function initializePulsatingDot() {
	const bookmarkWrapper = document.querySelector( BOOKMARK_WRAPPER_SELECTOR );

	if (
		ReadingListsMinervaCTAShowDot &&
		mw.storage.get( STORAGE_KEY_IMPRESSION ) !== '1' &&
		bookmarkWrapper instanceof HTMLElement
	) {
		return mw.loader.using( [ 'mediawiki.pulsatingdot' ] )
			.then( () => {
				setUpPulsatingDot( bookmarkWrapper );
			} )
			.catch( ( error ) => {
				mw.log( 'Error loading mediawiki.pulsatingdot module:', error );
			} );
	}

	return Promise.resolve();
}

module.exports = { initializePulsatingDot };
