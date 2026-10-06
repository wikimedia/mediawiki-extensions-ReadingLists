const { createMwApp } = require( 'vue' );
const BookmarkPopover = require( './BookmarkPopover.vue' );

/**
 * Shows the save/un-save popover.
 *
 * @param {boolean} isCurrentlySaved The existing state of the article
 * @return {Promise<boolean>} Returns whether to show an mw.notification on dismiss
 */
function initBookmarkPopover( isCurrentlySaved ) {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );

	return new Promise( ( resolve ) => {
		const app = createMwApp( BookmarkPopover, {
			title: mw.config.get( 'wgPageName' ),
			isCurrentlySaved,
			onDismiss: ( showNotification, listId = null, listName = null ) => cleanup( showNotification, listId, listName )
		} );

		function cleanup( showNotification, listId, listName ) {
			app.unmount();
			container.remove();
			resolve( { showNotification, listId, listName } );
		}

		app.mount( container );
	} );
}

module.exports = {
	initBookmarkPopover
};
