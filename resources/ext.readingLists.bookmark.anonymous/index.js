/**
 * Add interactivity to the bookmark button for anonymous users.
 */

const { createMwApp } = require( 'vue' );
const CtaDialog = require( './CtaDialog.vue' );
const { initializePulsatingDot } = require( './pulsatingDot.js' );

// Note that this is currently MinervaNeue-specific.
const bookmark = document.getElementById( 'ca-bookmark' );

if ( !bookmark ) {
	throw new Error( 'Bookmark not found' );
}

initializePulsatingDot();

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

	mw.loader.using( 'ext.testKitchen' ).then( () => {
		const experiment = mw.testKitchen.compat.getExperiment( 'account-creation-reading-list-cta' );
		if ( experiment ) {
			// eslint-disable-next-line camelcase
			experiment.send( 'click', { action_subtype: 'save_article_to_reading_list' } );
		}
	} );
} );
