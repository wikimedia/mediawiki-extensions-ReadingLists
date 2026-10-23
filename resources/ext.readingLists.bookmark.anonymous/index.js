/**
 * Add interactivity to the bookmark button for anonymous users.
 */

const { createMwApp } = require( 'vue' );
const BookmarkCta = require( './BookmarkCta.vue' );
const { initializePulsatingDot } = require( './pulsatingDot.js' );

const bookmark = document.getElementById( 'ca-bookmark' );

if ( !bookmark ) {
	throw new Error( 'Bookmark not found' );
}

initializePulsatingDot();

function launchBookmarkCta() {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );

	const app = createMwApp( BookmarkCta, {
		anchor: bookmark,
		onClose: () => cleanup()
	} );
	app.mount( container );

	function cleanup() {
		app.unmount();
		container.remove();
	}
}

bookmark.addEventListener( 'click', ( event ) => {
	event.preventDefault();
	launchBookmarkCta();
} );
