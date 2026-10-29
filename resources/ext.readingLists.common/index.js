const ConfigPopover = require( './ConfigPopover.vue' );
const CreateCollectionButton = require( './CreateCollectionButton.vue' );
const CollectionNameDialog = require( './CollectionNameDialog.vue' );
const DialogPopover = require( './DialogPopover.vue' );
const BookmarkCta = require( './BookmarkCta.vue' );
const { useIsMobileResolution, useIsAboveMobileResolution, MAX_WIDTH_MOBILE } = require( './composables.js' );
const actions = require( './actions.js' );

module.exports = {
	actions,
	MAX_WIDTH_MOBILE,
	useIsMobileResolution,
	useIsAboveMobileResolution,
	ConfigPopover,
	CreateCollectionButton,
	CollectionNameDialog,
	DialogPopover,
	BookmarkCta
};
