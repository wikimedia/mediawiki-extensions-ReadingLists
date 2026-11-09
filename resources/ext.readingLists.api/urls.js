// URL helpers for Special:Saved. This file must not depend on Vue or Codex,
// because the bookmark module loads it on every article view.

// Canonical base URL of this special page, e.g. "/wiki/Special:Saved".
// wgCanonicalSpecialPageName is the bare name ("Saved") without the
// namespace, so we prepend the canonical "Special:" prefix to build a path that
// matches the links the app renders (mw.util.getUrl( 'Special:Saved/…' )).
const getCollectionsBaseUrl = ( pageName ) => `${ pageName.split( '/' )[ 0 ] }`;
const collectionsBaseUrl = mw.config.get( 'wgCanonicalSpecialPageName' ) === 'Saved' ?
	getCollectionsBaseUrl( mw.config.get( 'wgPageName' ) ) :
	'Special:Saved';

function getCollectionUrl( listId, listName, isRelative = false ) {
	const isAllCollectionsPage = !listId || !listName;
	let url = isAllCollectionsPage ? collectionsBaseUrl : `${ collectionsBaseUrl }/${ listId }/${ listName }`;
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
