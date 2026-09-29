const api = require( 'ext.readingLists.api' );
const { createMwApp } = require( 'vue' );
const Entries = require( './pages/Entries.vue' );
const App = require( './App.vue' );
const router = require( './router.js' );

const { ReadingListsCustomLists } = require( './config.json' );

/**
 * Mounts the import/export view. This is only reachable on initial load: it
 * async-loads a separate module and decodes the shared list, so it is kept out
 * of the router entirely.
 *
 * @param {string} imported base64 encoded list from the limport/lexport param
 * @return {Promise<void>}
 */
async function mountImportApp( imported ) {
	const ImportDialog = ( await mw.loader.using( 'ext.readingLists.special.importDialog' ) )(
		'ext.readingLists.special.importDialog'
	);
	const importedList = await api.fromBase64( imported );

	const app = createMwApp( {
		components: {
			Entries,
			ImportDialog
		},
		data() {
			return { importedList, ReadingListsCustomLists };
		},
		template: `
			<entries :imported="importedList" :isCustomListsEnabled="ReadingListsCustomLists">
				<template #import-dialog>
					<import-dialog></import-dialog>
				</template>
			</entries>
		`
	} );

	// The import view does not navigate, but Entries still renders NavigationBar
	// (whose links are router-links) when custom lists are enabled, so the router
	// must be installed for those to resolve.
	app.use( router );
	app.mount( '.reading-lists-container' );
}

async function mountApp() {
	const search = new URLSearchParams( window.location.search );
	const imported = search.get( 'limport' ) || search.get( 'lexport' );

	if ( imported ) {
		return mountImportApp( imported );
	}

	const app = createMwApp( App );
	app.use( router );

	// Wait for the initial route to resolve before mounting, otherwise Entries
	// would first mount against the START_LOCATION and immediately remount (and
	// refetch) once the real route resolves.
	await router.isReady();
	app.mount( '.reading-lists-container' );
}

mountApp();
