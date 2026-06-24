const api = require( 'ext.readingLists.api' );
const { createMwApp } = require( 'vue' );
const Entries = require( './pages/Entries.vue' );

let page = mw.config.get( 'wgPageName' );

if ( page.endsWith( '/' ) ) {
	page = page.slice( 0, -1 );
}

const parts = page.split( '/' );

async function createApp() {
	// Show imported list if limport or lexport parameter is present.
	// Otherwise the PHP special page redirects to Special:ReadingLists/{user_name}.
	if ( parts.length < 2 ) {
		const search = new URLSearchParams( window.location.search );
		const imported = search.get( 'limport' ) || search.get( 'lexport' );

		if ( imported ) {
			return createMwApp( Entries, {
				imported: await api.fromBase64( imported )
			} );
		}
	} else if ( parts.length >= 3 ) {
		// show specific reading list (default or custom, based on reading list id)
		// e.g. Special:ReadingLists/{user_name}/{list_id}
		return createMwApp( Entries, {
			listId: parseInt( parts[ 2 ] )
		} );
	}

	return createMwApp( Entries );
}

async function mountApp() {
	const app = await createApp();

	app.mount( '.reading-lists-container' );
}

mountApp();
