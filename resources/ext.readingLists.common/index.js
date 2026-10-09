const ConfigPopover = require( './ConfigPopover.vue' );
const CreateCollectionDialog = require( './CreateCollectionDialog.vue' );
const DialogPopover = require( './DialogPopover.vue' );
const { useIsMobileResolution, useIsAboveMobileResolution, MAX_WIDTH_MOBILE } = require( './composables.js' );

// Canonical base URL of this special page, e.g. "/wiki/Special:ReadingLists".
// wgCanonicalSpecialPageName is the bare name ("ReadingLists") without the
// namespace, so we prepend the canonical "Special:" prefix to build a path that
// matches the links the app renders (mw.util.getUrl( 'Special:ReadingLists/…' )).
const collectionsBaseUrl = `Special:${ mw.config.get( 'wgCanonicalSpecialPageName' ) || 'ReadingLists' }`;

function getCollectionUrl( listId, listName, isRelative = false ) {
	const isAllCollectionsPage = !listId || !listName;
	const allCollectionsUrl = `${ collectionsBaseUrl }/${ mw.user.getName() }`;
	let url = isAllCollectionsPage ? allCollectionsUrl : `${ allCollectionsUrl }/${ listId }/${ listName }`;
	if ( isRelative ) {
		// Remove the base URL prefix to make it relative
		url = url.slice( collectionsBaseUrl.length ) || '/';
	}
	return url;
}

module.exports = {
	MAX_WIDTH_MOBILE,
	useIsMobileResolution,
	useIsAboveMobileResolution,
	getCollectionUrl,
	collectionsBaseUrl,
	ConfigPopover,
	CreateCollectionDialog,
	DialogPopover
};
