// URL helpers for Special:ReadingLists. This file must not depend on Vue or Codex,
// because the bookmark module loads it on every article view.

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
	collectionsBaseUrl,
	getCollectionUrl
};
