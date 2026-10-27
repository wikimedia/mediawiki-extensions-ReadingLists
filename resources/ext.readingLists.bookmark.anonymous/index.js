/**
 * Add interactivity to the bookmark button for anonymous users.
 */

const { createMwApp } = require( 'vue' );
const BookmarkCta = require( './BookmarkCta.vue' );
const { initializePulsatingDot } = require( './pulsatingDot.js' );
const BOOKMARK_SELECTOR = '#ca-bookmark, #ca-bookmark-more';

function launchBookmarkCta( bookmark ) {
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

function onBookmarkClick( event ) {
	const bookmark = event.target.closest( BOOKMARK_SELECTOR );
	if ( !bookmark ) {
		return;
	}
	event.preventDefault();
	launchBookmarkCta( bookmark );
}

function init() {
	const bookmark = document.querySelector( BOOKMARK_SELECTOR );
	if ( !bookmark ) {
		throw new Error( 'Bookmark not found' );
	}

	initializePulsatingDot();
	document.documentElement.addEventListener( 'click', onBookmarkClick );
}

if ( typeof vitest === 'undefined' ) {
	init();
}
module.exports = {
	init
};
