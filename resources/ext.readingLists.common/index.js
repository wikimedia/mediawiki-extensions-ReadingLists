const ConfigPopover = require( './ConfigPopover.vue' );
const CreateCollectionDialog = require( './CreateCollectionDialog.vue' );
const DialogPopover = require( './DialogPopover.vue' );
const { useIsMobileResolution, useIsAboveMobileResolution, MAX_WIDTH_MOBILE } = require( './composables.js' );

module.exports = {
	MAX_WIDTH_MOBILE,
	useIsMobileResolution,
	useIsAboveMobileResolution,
	ConfigPopover,
	CreateCollectionDialog,
	DialogPopover
};
