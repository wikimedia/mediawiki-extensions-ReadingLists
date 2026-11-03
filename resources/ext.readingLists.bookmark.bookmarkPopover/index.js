const { createMwApp } = require( 'vue' );
const BookmarkPopover = require( './BookmarkPopover.vue' );

/**
 * @typedef {import('../types.js').CollectionWithTitle} CollectionWithTitle
 * @typedef {import('../types.js').BookmarkPopoverResult} BookmarkPopoverResult
 */

/**
 * Shows the save/un-save popover.
 *
 * @param {boolean} isNewSave Whether the page was just saved for the first time
 * @param {CollectionWithTitle[]} collections List of collections, including their titles
 * @param {boolean} isInDefaultList Whether the page is saved in the default list
 * @return {Promise<BookmarkPopoverResult>} Saved state and notification details on dismiss
 */
function initBookmarkPopover( isNewSave, collections, isInDefaultList ) {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );

	return new Promise( ( resolve ) => {
		const app = createMwApp( BookmarkPopover, {
			title: mw.config.get( 'wgPageName' ),
			isNewSave,
			collections,
			isInDefaultList,
			/**
			 * @param {BookmarkPopoverResult} data
			 * @return {void}
			 */
			onDismiss: ( data ) => cleanup( data )
		} );

		/**
		 * Remove the app and resolve the promise with a payload of data.
		 *
		 * @param {BookmarkPopoverResult} data
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
