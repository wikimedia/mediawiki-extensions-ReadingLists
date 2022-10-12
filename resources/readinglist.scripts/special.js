const api = require( './api.js' );
const ReadingListPage = require( './views/ReadingListPage.vue' );

/**
 * Renders the special page.
 *
 * @param {Vue.VueConstructor} Vue
 * @param {string} username of current user
 * @param {number|null} initialCollection identifer
 * @param {string} importData base64 encoded JSON of a collection
 * @param {boolean} isImport
 */
function init( Vue, username, initialCollection, importData, isImport ) {
	let initialTitles, initialName, initialDescription, disclaimer;
	try {
		const data = api.fromBase64( importData );
		initialName = data.name || mw.msg( 'readinglists-no-title' );
		initialDescription = data.description || '';
		initialTitles = data.list;
		disclaimer = mw.msg( 'readinglists-import-disclaimer' );
	} catch ( e ) {
		// continue to render an errors
	}
	return Vue.createMwApp( ReadingListPage, {
		initialName,
		initialDescription,
		username,
		disclaimer,
		api,
		isImport,
		initialTitles,
		initialCollection
	} ).mount( '#reading-list-container' );
}

module.exports = {
	init
};