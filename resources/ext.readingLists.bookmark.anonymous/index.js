/**
 * Add interactivity to the bookmark button for anonymous users.
 */

const { createMwApp } = require( 'vue' );
const CtaDialog = require( './CtaDialog.vue' );

// Note that this is currently MinervaNeue-specific.
const bookmark = document.getElementById( 'ca-bookmark' );

if ( !bookmark ) {
	throw new Error( 'Bookmark not found' );
}

function launchCtaDialog() {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );

	const app = createMwApp( CtaDialog, {
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
	launchCtaDialog();
} );
