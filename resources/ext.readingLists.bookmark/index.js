const initBookmark = require( './bookmark.js' );
const isMinerva = mw.config.get( 'skin' ) === 'minerva';
const bookmarks = document.querySelectorAll( isMinerva ? '#ca-bookmark' : '.reading-lists-bookmark' );

if ( bookmarks.length === 0 ) {
	throw new Error( 'Bookmark not found' );
}

bookmarks.forEach( ( bookmarkElement ) => {
	// ReadingsLists experiments T397532
	// This is for the ReadingList experiment to know which bookmark the user clicked
	// ToDo: Remove after experiment ends
	let eventSource = 'toolbar';

	if ( bookmarkElement.id === 'ca-bookmark-sticky-header' ) {
		eventSource = 'sticky_header';
	} else if ( bookmarkElement.closest( '#ca-more-bookmark' ) ) {
		eventSource = 'page_tools';
	}

	initBookmark( bookmarkElement, isMinerva, eventSource );
} );
