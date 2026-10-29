/**
 * Add interactivity to the bookmark button for anonymous users.
 *
 * This module loads on every article view, so it must not depend on Vue.
 * The popover is in ext.readingLists.common, which loads when the user clicks the button.
 */

const { initializePulsatingDot } = require( './pulsatingDot.js' );
const BOOKMARK_SELECTOR = '#ca-bookmark, #ca-bookmark-more';

// True while ext.readingLists.common loads or while the popover is open.
let isCtaActive = false;

function launchBookmarkCta( bookmark, createMwApp, BookmarkCta ) {
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
		isCtaActive = false;
	}
}

function onBookmarkClick( event ) {
	const bookmark = event.target.closest( BOOKMARK_SELECTOR );
	if ( !bookmark ) {
		return;
	}
	event.preventDefault();

	// Ignore clicks while the module loads or while the popover is open.
	if ( isCtaActive ) {
		return;
	}
	isCtaActive = true;

	mw.loader.using( 'ext.readingLists.common' ).then( ( req ) => {
		const { createMwApp } = req( 'vue' );
		const { BookmarkCta } = req( 'ext.readingLists.common' );
		launchBookmarkCta( bookmark, createMwApp, BookmarkCta );
	} ).catch( ( error ) => {
		isCtaActive = false;
		mw.log.error( 'Error loading ext.readingLists.common module:', error );
	} );
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
