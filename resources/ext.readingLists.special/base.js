// Canonical base URL of this special page, e.g. "/wiki/Special:ReadingLists".
// wgCanonicalSpecialPageName is the bare name ("ReadingLists") without the
// namespace, so we prepend the canonical "Special:" prefix to build a path that
// matches the links the app renders (mw.util.getUrl( 'Special:ReadingLists/…' )).
module.exports = mw.util.getUrl(
	'Special:' + mw.config.get( 'wgCanonicalSpecialPageName' )
);
