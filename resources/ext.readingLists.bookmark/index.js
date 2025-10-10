const initBookmark = require( './bookmark.js' );
const skinName = mw.config.get( 'skin' );
const isMinerva = skinName === 'minerva';
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

// Minerva also has #ca-bookmark, so it is necessary to check
// both the skin name and the presence of the bookmark element
if (
	skinName === 'vector-2022' &&
	!mw.storage.get( 'readinglists-bookmark-dialog-seen' ) &&
	document.querySelector( '#ca-bookmark' )
) {
	setTimeout( () => {
		mw.requestIdleCallback( () => {
			mw.loader.using( 'ext.readingLists.onboarding' );
		}, { timeout: 2000 } );
	}, 1000 );
}
