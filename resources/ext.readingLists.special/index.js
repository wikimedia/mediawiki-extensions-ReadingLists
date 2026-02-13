const api = require( 'ext.readingLists.api' );
const { createMwApp } = require( 'vue' );
const Lists = require( './pages/Lists.vue' );
const Entries = require( './pages/Entries.vue' );

let page = mw.config.get( 'wgPageName' );

if ( page.endsWith( '/' ) ) {
	page = page.slice( 0, -1 );
}

const parts = page.split( '/' );

async function mountApp() {
	let app;

	// show imported list, if limport or lexport parameter is present,
	// otherwise the php special page redirects to Special:ReadingLists/{user_name}.
	// keeping the logic for Lists.vue here but now we redirect
	// Special:ReadingLists to Special:ReadingLists/{user_name} per T400361.
	if ( parts.length < 2 ) {
		const search = new URLSearchParams( window.location.search );
		const imported = search.get( 'limport' ) || search.get( 'lexport' );

		if ( imported === null ) {
			app = createMwApp( Lists );
		} else {
			app = createMwApp( Entries, {
				imported: await api.fromBase64( imported )
			} );
		}
	// show specific reading list (default or custom, based on reading list id)
	// e.g. Special:ReadingLists/{user_name}/{list_id}
	} else if ( parts.length >= 3 ) {
		app = createMwApp( Entries, {
			listId: parseInt( parts[ 2 ] )
		} );
	// show all items from all reading lists on Special:ReadingLists/{user_name} page
	} else {
		app = createMwApp( Entries );
	}

	app.mount( '.reading-lists-container' );
}

mountApp();
