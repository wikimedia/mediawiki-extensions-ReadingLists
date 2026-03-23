const { createMwApp } = require( 'vue' );
const ConfirmUnsavePopover = require( './ConfirmUnsavePopover.vue' );

/**
 * Shows the confirmation popover for unsaving a page
 * from a custom reading list.
 *
 * @param {Element} anchorElement
 * @param {boolean} isMinerva
 * @return {Promise<boolean>}
 */
function confirmUnsaveFromCustomList( anchorElement, isMinerva ) {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );

	return new Promise( ( resolve ) => {
		const app = createMwApp( ConfirmUnsavePopover, {
			anchorElement,
			isMinerva,
			onConfirm: () => cleanup( true ),
			onCancel: () => cleanup( false )
		} );

		function cleanup( confirmed ) {
			app.unmount();
			container.remove();
			resolve( confirmed );
		}

		app.mount( container );
	} );
}

module.exports = {
	confirmUnsaveFromCustomList
};
