function loadBookmarking() {
	mw.loader.using( 'readinglist.scripts' ).then( ( req ) => {
		req( 'readinglist.scripts' ).initBookmark();
	} );
}

const node = document.querySelector( '.reading-list-bookmark' );
if ( node ) {
	node.addEventListener( 'click', ( ev ) => {
		ev.preventDefault();
		loadBookmarking();
	} );
}
