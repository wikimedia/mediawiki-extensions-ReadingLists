const { initBookmark } = require( './bookmark.js' );
const skinName = mw.config.get( 'skin' );
const isMinerva = skinName === 'minerva';
const bookmarkSelector = isMinerva ? '#ca-bookmark' : '.reading-lists-bookmark';
const bookmarks = document.querySelectorAll( bookmarkSelector );

if ( bookmarks.length === 0 ) {
	throw new Error( 'Bookmark not found' );
}

bookmarks.forEach( ( bookmarkElement ) => {
	// ReadingLists instrument: track which bookmark the user clicked
	let eventSource = 'toolbar';

	if ( bookmarkElement.id === 'ca-bookmark-sticky-header' ) {
		eventSource = 'sticky_header';
	} else if ( bookmarkElement.closest( '#ca-more-bookmark' ) ) {
		eventSource = 'tool_menu';
	}

	initBookmark( bookmarkElement, isMinerva, eventSource );
} );
