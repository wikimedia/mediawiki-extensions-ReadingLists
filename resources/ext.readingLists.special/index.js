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

	if ( parts.length < 3 ) {
		const search = new URLSearchParams( window.location.search );
		const imported = search.get( 'limport' ) || search.get( 'lexport' );

		if ( imported === null ) {
			app = createMwApp( Lists );
		} else {
			app = createMwApp( Entries, {
				imported: await api.fromBase64( imported )
			} );
		}
	} else {
		app = createMwApp( Entries, {
			listId: parseInt( parts[ 2 ] )
		} );
	}

	app.mount( '.readinglists-container' );
}

mountApp();
