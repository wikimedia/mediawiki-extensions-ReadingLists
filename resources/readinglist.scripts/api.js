const config = require( './config.json' );
const api = new mw.Api();

/**
 * @typedef ApiQueryResponseReadingListEntry
 * @property {string} title
 */

/**
 * @typedef ApiQueryResponseReadingListEntryItem
 * @property {string} title
 * @property {number} id
 */

/**
 * @typedef ApiQueryResponseReadingListItem
 * @property {number} id
 * @property {string} name
 * @property {string} description
 * @property {boolean} default whether it is the default
 */

/**
 * @typedef ApiQueryResponsePage
 * @property {string} title
 */

/**
 * @typedef ApiQueryResponseReadingListsQuery
 * @property {ApiQueryResponseReadingListItem[]} readinglists
 */

/**
 * @typedef ApiQueryResponseReadingListQuery
 * @property {ApiQueryResponseReadingListEntryItem[]} readinglistentries
 */

/**
 * @typedef  ApiQueryResponseTitlesQuery
 * @property {ApiQueryResponsePage[]} pages
 */

/**
 * @typedef ApiQueryResponseReadingListEntries
 * @property {ApiQueryResponseReadingListQuery} query
 */

/**
 * @typedef ApiQueryError
 * @property {number} code
 */

/**
 * @typedef ApiQueryResponseReadingLists
 * @property {ApiQueryResponseReadingListsQuery} query
 * @property {ApiQueryError} [error]
 */

/**
 * @typedef ApiQueryResponseTitles
 * @property {ApiQueryResponseTitlesQuery} query
 */

/**
 * @typedef Card
 * @property {number} [id]
 * @property {string} url
 * @property {string} [ownerName]
 * @property {string} name
 * @property {string} [description]
 */

/**
 * @param {string} project
 * @return {string}
 */
function getProjectHost( project ) {
	const isProjectCode = project.indexOf( '//' ) === -1;
	if ( config.ReadingListsDeveloperMode ) {
		const code = isProjectCode ? project : 'en';
		return `https://${code}.wikipedia.org`;
	}
	if ( isProjectCode ) {
		project = `https://${project}.${window.location.host.split( '.' ).slice( 1 ).join( '.' )}`;
	}
	return project;
}

/**
 * @param {string} project
 * @return {function( ApiQueryResponsePage ): Card}
 */
const transformPage = ( project ) => {
	return ( page ) =>
		Object.assign( page, {
			project: getProjectHost( project ),
			url: new mw.Title( page.title ).getUrl(),
			thumbnail: page.thumbnail ? {
				width: page.thumbnail.width,
				height: page.thumbnail.height,
				url: page.thumbnail.source
			} : null
		} );
};

/**
 * @typedef {Object.<string, string[]>} ProjectTitleMap
 * @typedef {Object.<string, number[]>} ProjectIDMap
 */

/**
 * Gets pages on a given reading list
 *
 * @param {ProjectTitleMap|ProjectIDMap} projectMap
 * @return {jQuery.Promise<any>}
 */
function getPagesFromProjectMap( projectMap ) {
	const projects = Object.keys( projectMap );
	const promises = [];
	for ( let i = 0; i < projects.length; i++ ) {
		promises.push( getPagesFromPageIdentifiers( projects[ i ], projectMap[ projects[ i ] ] ) );
	}
	return Promise.all( promises ).then( ( args ) => {
		return Array.prototype.concat.apply( [], args );
	} );
}

/**
 * From a project identifier work out which API to use.
 *
 * @param {string} project
 * @return {string}
 */
function getProjectApiUrl( project ) {
	if ( config.ReadingListsDeveloperMode ) {
		return `${getProjectHost( project )}/w/api.php`;
	}
	return `${getProjectHost( project )}${mw.config.get( 'wgScriptPath' )}/api.php`;
}

/**
 *
 * @param {string} project e.g. 'http://localhost:8888' or language code e.g. 'en'
 * @param {number[]|string[]} pageidsOrPageTitles
 * @return {jQuery.Promise<any>}
 */
function getThumbnailsAndDescriptions( project, pageidsOrPageTitles ) {
	const isPageIds = pageidsOrPageTitles[ 0 ] && typeof pageidsOrPageTitles[ 0 ] === 'number';
	const pageids = isPageIds ? pageidsOrPageTitles : undefined;
	const titles = isPageIds ? undefined : pageidsOrPageTitles;
	const ajaxOptions = {
		url: `${getProjectApiUrl( project )}`
	};

	return pageidsOrPageTitles.length ? api.get( {
		action: 'query',
		format: 'json',
		origin: '*',
		formatversion: 2,
		prop: 'pageimages|description',
		pageids,
		titles,
		piprop: 'thumbnail',
		pithumbsize: 200
	}, ajaxOptions ).then( function ( /** @type {ApiQueryResponseTitles} */ pageData ) {
		return pageData && pageData.query ?
			pageData.query.pages.map( transformPage( project ) ) : [];
	} ) : Promise.resolve( [] );
}
/**
 * Gets pages from a given project and list of pageids
 *
 * @param {string} project
 * @param {number[]|string[]} pageids
 * @return {jQuery.Promise<any>}
 */
function getPagesFromPageIdentifiers( project, pageids ) {
	const LIMIT = 50;
	if ( pageids.length > LIMIT ) {
		const promises = [];
		for ( let i = 0; i < pageids.length; i += LIMIT ) {
			promises.push( getPagesFromPageIdentifiers( project, pageids.slice( i, i + LIMIT ) ) );
		}
		return Promise.all( promises ).then( ( args ) => {
			return Array.prototype.concat.apply( [], args );
		} );
	}
	if ( pageids.length > 250 ) {
		return Promise.reject( 'readinglists-import-size-error' );
	}
	return getThumbnailsAndDescriptions( project, pageids );
}

/**
 * @param {string} name
 * @param {string} description
 * @param {string[]} titles
 * @return {string}
 */
function toBase64( name, description, titles ) {
	return btoa( JSON.stringify( { name, description, titles } ) );
}

/**
 * @param {string} data
 * @return {Object}
 */
function fromBase64( data ) {
	return JSON.parse( atob( data ) );
}

module.exports = {
	fromBase64,
	toBase64,
	getPagesFromProjectMap
};
