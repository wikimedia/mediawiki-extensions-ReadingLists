/**
 * Fires when a user performed a tracked reading lists action.
 *
 * @event readingLists.actions
 * @memberof mw.Hooks
 * @param {string} action Interaction type, e.g. `click` or `init`
 * @param {string} type What was interacted with, e.g. `save_custom_list`
 */
const logEvent = ( action, type ) => {
	mw.hook( 'readingLists.actions' ).fire( action, type );
};

const actionCreateCollectionButtonClick = () => {
	logEvent(
		'click',
		'create_custom_list'
	);
};

const actionAddToCustomList = () => {
	logEvent(
		'click',
		'add_to_custom_list'
	);
};

const actionRemoveFromCustomList = () => {
	logEvent(
		'click',
		'remove_from_custom_list'
	);
};

const actionLoadPage = ( subtype ) => {
	logEvent(
		'init',
		subtype
	);
};

const actionSaveCustomList = () => {
	logEvent(
		'click',
		'save_custom_list'
	);
};

const actionUnsaveInitClick = () => {
	logEvent(
		'click',
		'unsave_init'
	);
};

const actionSettingsRenameClick = () => {
	logEvent(
		'click',
		'rename_custom_list'
	);
};

const actionSettingsDeleteClick = () => {
	logEvent(
		'click',
		'delete_custom_list'
	);
};

const actionSettingsDeleteConfirmClick = () => {
	logEvent(
		'click',
		'delete_custom_list_confirm'
	);
};

module.exports = {
	actionSettingsRenameClick,
	actionSettingsDeleteClick,
	actionSettingsDeleteConfirmClick,
	actionCreateCollectionButtonClick,
	actionAddToCustomList,
	actionRemoveFromCustomList,
	actionSaveCustomList,
	actionUnsaveInitClick,
	actionLoadPage
};
