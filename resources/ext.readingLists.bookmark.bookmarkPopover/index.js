const { createMwApp } = require( 'vue' );
const BookmarkPopover = require( './BookmarkPopover.vue' );

/**
 * Shows the save/un-save popover.
 *
 * @param {boolean} isNewSave Whether the page was just saved for the first time
 * @param {Object[]} collectionsWithPage List of collections containing the page
 * @return {Promise<boolean>} Returns whether to show an mw.notification on dismiss
 */
function initBookmarkPopover( isNewSave, collectionsWithPage ) {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );

	return new Promise( ( resolve ) => {
		const app = createMwApp( BookmarkPopover, {
			title: mw.config.get( 'wgPageName' ),
			isNewSave,
			collectionsWithPage,
			onDismiss: ( data ) => cleanup( data )
		} );

		/**
		 * Remove the app and resolve the promise with a payload of data.
		 *
		 * @param {Object} data
		 * @param {boolean} data.isSaved Whether the page has been saved to or unsaved from a list
		 * @param {boolean} data.showNotification Whether to show an mw.notification. Only needed if
		 *                                       a page is saved to a custom list or unsaved.
		 * @param {string} listId
		 * @param {string} listName
		 */
		function cleanup( data ) {
			app.unmount();
			container.remove();
			resolve( data );
		}

		app.mount( container );
	} );
}

module.exports = {
	initBookmarkPopover
};
