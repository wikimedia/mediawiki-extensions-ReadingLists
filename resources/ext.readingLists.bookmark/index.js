const initBookmark = require( './bookmark.js' );
const isMinerva = mw.config.get( 'skin' ) === 'minerva';
const bookmarks = document.querySelectorAll( isMinerva ? '#ca-bookmark' : '.reading-lists-bookmark' );

if ( bookmarks.length === 0 ) {
	throw new Error( 'Bookmark not found' );
}

bookmarks.forEach( ( bookmarkElement ) => {
	initBookmark( bookmarkElement, isMinerva );
} );
