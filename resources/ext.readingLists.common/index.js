const ConfigPopover = require( './ConfigPopover.vue' );
const CreateCollectionButton = require( './CreateCollectionButton.vue' );
const CollectionNameDialog = require( './CollectionNameDialog.vue' );
const DialogPopover = require( './DialogPopover.vue' );
const { useIsMobileResolution, useIsAboveMobileResolution, MAX_WIDTH_MOBILE } = require( './composables.js' );
const actions = require( './actions.js' );

// Canonical base URL of this special page, e.g. "/wiki/Special:ReadingLists".
// wgCanonicalSpecialPageName is the bare name ("ReadingLists") without the
// namespace, so we prepend the canonical "Special:" prefix to build a path that
// matches the links the app renders (mw.util.getUrl( 'Special:ReadingLists/…' )).
const getCollectionsBaseUrl = ( pageName ) => `${ pageName.split( '/' )[ 0 ] }`;
const collectionsBaseUrl = mw.config.get( 'wgCanonicalSpecialPageName' ) === 'ReadingLists' ?
	getCollectionsBaseUrl( mw.config.get( 'wgPageName' ) ) :
	'Special:ReadingLists';

function getCollectionUrl( listId, listName, isRelative = false ) {
	const isAllCollectionsPage = !listId || !listName;
	const allCollectionsUrl = `${ collectionsBaseUrl }/${ mw.user.getName() }`;
	let url = isAllCollectionsPage ? allCollectionsUrl : `${ allCollectionsUrl }/${ listId }/${ listName }`;
	if ( isRelative ) {
		// Remove the base URL prefix and encode the path to match MediaWiki's URLs.
		url = mw.util.wikiUrlencode( url.slice( collectionsBaseUrl.length ) ) || '/';
	}
	return url;
}

module.exports = {
	getCollectionsBaseUrl,
	actions,
	MAX_WIDTH_MOBILE,
	useIsMobileResolution,
	useIsAboveMobileResolution,
	getCollectionUrl,
	collectionsBaseUrl,
	ConfigPopover,
	CreateCollectionButton,
	CollectionNameDialog,
	DialogPopover
};
