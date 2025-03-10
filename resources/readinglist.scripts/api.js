const DEFAULT_READING_LIST_NAME = mw.msg( 'readinglists-default-title' );
const DEFAULT_READING_LIST_DESCRIPTION = mw.msg( 'readinglists-default-description' );
const { getReadingListUrl } = require( './utils.js' );
const config = require( './config.json' );
const api = new mw.Api();
const WATCHLIST_ID = -10;
const WATCHLIST_NAME = mw.msg( 'readinglists-watchlist' );
const WATCHLIST_DESCRIPTION = mw.msg( 'readinglists-watchlist-description' );

/**
 * @typedef ApiQueryResponseReadingListEntry
 * @property {string} title
 */

/**
 * @typedef {Object} ImportedList
 * @property {string} name
 * @property {string} description
 * @param {ProjectTitleMap|ProjectTitleMap} list
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
 * @property {number} [id] id relating to reading list
 * @property {number} [pageid] id relating to the page title
 * @property {string} url
 * @property {string} [ownerName]
 * @property {string} name
 * @property {string} [description]
 */

/**
 * Converts API response to WVUI compatible response.
 *
 * @param {ApiQueryResponseReadingListItem} collection from API response
 * @param {string} ownerName of collection
 * @return {Card} modified collection
 */
const readingListToCard = ( collection, ownerName ) => {
	const description = collection.default ?
		DEFAULT_READING_LIST_DESCRIPTION : collection.description;
	const name = collection.default ? DEFAULT_READING_LIST_NAME : collection.name;
	const url = getReadingListUrl( ownerName, collection.id, name );
	return Object.assign( {}, collection, { ownerName, name, description, url } );
};

/**
 * @param {string} project
 * @return {boolean}
 */
const isLanguageCode = ( project ) => {
	const hasProtocol = project.indexOf( '//' ) > -1;
	return !hasProtocol && project.indexOf( '.' ) === -1 && project.indexOf( ':' ) === -1;
};

/**
 * From a project identifier work out which API to use.
 *
 * @param {string} project
 * @return {string}
 */
function getProjectHost( project ) {
	const isLang = isLanguageCode( project );
	const hasProtocol = project.indexOf( '//' ) > -1;
	if ( config.ReadingListsDeveloperMode ) {
		if ( project.indexOf( 'localhost' ) > -1 ) {
			return 'https://en.wikipedia.org';
		} else if ( isLang ) {
			return `https://${ project }.wikipedia.org`;
		} else {
			return hasProtocol ? project : `//${ project }`;
		}
	}
	if ( isLang ) {
		return `https://${ project }.${ window.location.host.split( '.' ).slice( 1 ).join( '.' ) }`;
	} else {
		return hasProtocol ? project : `//${ project }`;
	}
}

/**
 * @param {string} project
 * @return {function( ApiQueryResponsePage ): Card}
 */
const transformPage = ( project ) => ( page ) => Object.assign( page, {
	project: getProjectHost( project ),
	// T320293
	url: `${ getProjectHost( project ) }${ new mw.Title( page.title ).getUrl() }`,
	pageid: page.pageid,
	thumbnail: page.thumbnail ? {
		width: page.thumbnail.width,
		height: page.thumbnail.height,
		url: page.thumbnail.source
	} : null
} );

/**
 * Sets up the reading list feature for new users who have never used it before.
 *
 * @return {jQuery.Promise<any>}
 */
function setupCollections() {
	return api.postWithToken( 'csrf', {
		action: 'readinglists',
		command: 'setup'
	} );
}

/**
 * Create a card representing the user's watchlist.
 *
 * @param {string} ownerName
 * @return {Card}
 */
const watchlistCard = ( ownerName ) => readingListToCard( {
	id: WATCHLIST_ID,
	name: WATCHLIST_NAME,
	description: WATCHLIST_DESCRIPTION
}, ownerName );

/**
 * @param {string} ownerName (username)
 * @param {number[]} marked a list of collection IDs which have a certain title
 * @return {Promise<Card[]>}
 */
function getCollections( ownerName, marked ) {
	return new Promise( ( resolve, reject ) => {
		api.get( {
			action: 'query',
			format: 'json',
			rldir: 'descending',
			rlsort: 'updated',
			meta: 'readinglists',
			formatversion: 2
		} ).then( ( /** @type {ApiQueryResponseReadingLists} */ data ) => {
			const list = ( data.query.readinglists || [] );
			resolve(
				list.map( ( collection ) => readingListToCard(
					collection, ownerName )
				).concat(
					watchlistCard( ownerName )
				)
			);
		}, ( /** @type {string} */ err ) => {
			// setup a reading list and try again.
			if ( err === 'readinglists-db-error-not-set-up' ) {
				setupCollections().then( () => getCollections( ownerName, marked ) )
					.then( ( /** @type {Card[]} */ collections ) => resolve( collections ) );
			} else {
				reject( err );
			}
		} );
	} );
}

/**
 * Gets pages on a given reading list
 *
 * @param {string} ownerName
 * @param {number} id of collection
 * @return {jQuery.Promise<Card>}
 */
function getCollectionMeta( ownerName, id ) {
	if ( id === WATCHLIST_ID ) {
		return Promise.resolve( watchlistCard( ownerName ) );
	}
	return api.get( { action: 'query', format: 'json', meta: 'readinglists', rllist: id, formatversion: 2 } )
		.then( ( /** @type {ApiQueryResponseReadingLists} */ data ) => {
			if ( data.error && data.error.code ) {
				throw new Error( `Error: ${ data.error.code }` );
			}
			return readingListToCard(
				data.query.readinglists[ 0 ],
				ownerName,
				[]
			);
		} );
}

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
	return Promise.all( promises ).then( ( args ) => Array.prototype.concat.apply( [], args ) );
}

/**
 * From a project identifier work out which API to use.
 *
 * @param {string} project
 * @return {string}
 */
function getProjectApiUrl( project ) {
	if ( config.ReadingListsDeveloperMode ) {
		return `${ getProjectHost( project ) }/w/api.php`;
	}
	return `${ getProjectHost( project ) }${ mw.config.get( 'wgScriptPath' ) }/api.php`;
}

/**
 * @param {string} project e.g. 'http://localhost:8888' or language code e.g. 'en'
 * @param {number[]|string[]} pageidsOrPageTitles
 * @return {jQuery.Promise<any>}
 */
function getThumbnailsAndDescriptions( project, pageidsOrPageTitles ) {
	const isPageIds = pageidsOrPageTitles[ 0 ] && typeof pageidsOrPageTitles[ 0 ] === 'number';
	const pageids = isPageIds ? pageidsOrPageTitles : undefined;
	const titles = isPageIds ? undefined : pageidsOrPageTitles;

	const ajaxOptions = {
		url: `${ getProjectApiUrl( project ) }`
	};

	const filterOutMissingPagesIfIDsPassed = ( page ) => isPageIds ? !page.missing : true;

	return pageidsOrPageTitles.length ? api.get( {
		action: 'query',
		format: 'json',
		origin: '*',
		formatversion: 2,
		redirects: true,
		pilimit: pageidsOrPageTitles.length,
		prop: 'pageimages|description',
		pageids,
		titles,
		piprop: 'thumbnail',
		pithumbsize: 200
	}, ajaxOptions ).then( ( /** @type {ApiQueryResponseTitles} */ pageData ) => pageData && pageData.query ?
		pageData.query.pages.filter(
			filterOutMissingPagesIfIDsPassed ).map( transformPage( project )
		) : [] ) : Promise.resolve( [] );
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
		return Promise.all( promises ).then( ( args ) => Array.prototype.concat.apply( [], args ) );
	}
	if ( pageids.length > 250 ) {
		return Promise.reject( 'readinglists-import-size-error' );
	}
	return getThumbnailsAndDescriptions( project, pageids );
}

/**
 * @param {ApiQueryResponsePage} pages
 * @return {ProjectTitleMap}
 */
function toProjectTitlesMap( pages ) {
	const /** @type {ProjectTitleMap} */projectTitleMap = {};
	pages.forEach( ( page ) => {
		if ( !projectTitleMap[ page.project ] ) {
			projectTitleMap[ page.project ] = [];
		}
		projectTitleMap[ page.project ].push( page.title );
	} );
	return projectTitleMap;
}

/**
 * @param {ApiQueryResponsePage} pages
 * @return {jQuery.Promise<any>}
 */
function getPagesFromReadingListPages( pages ) {
	return getPagesFromProjectMap( toProjectTitlesMap( pages ) );
}

/**
 * Get the current project.
 *
 * @return {string}
 */
function getCurrentProjectName() {
	// Use wgServer to avoid issues with ".m." domain
	const server = mw.config.get( 'wgServer' );
	return server.indexOf( '//' ) === 0 ?
		window.location.protocol + server : server;
}

/**
 * @param {Object} continueQuery
 * @return {jQuery.Promise<ApiQueryResponseReadingListEntryItem[]>}
 */
function getWatchlistPages( continueQuery = {} ) {
	return api.get( Object.assign( {
		action: 'query',
		format: 'json',
		formatversion: 2,
		wrnamespace: 0,
		list: 'watchlistraw',
		wrlimit: 'max'
	}, continueQuery ) ).then( ( data ) => {
		const pages = data.watchlistraw.map( ( page ) => Object.assign( {}, page, {
			project: getCurrentProjectName()
		} ) );
		if ( data.continue ) {
			return getWatchlistPages( data.continue )
				.then( ( extraPages ) => pages.concat( extraPages ) );
		}
		return pages;
	} );
}

/**
 * @param {number} collectionId
 * @param {Object} [continueQuery]
 * @return {jQuery.Promise<ApiQueryResponseReadingListEntryItem[]>}
 */
function getReadingListPages( collectionId, continueQuery = {} ) {
	return api.get( Object.assign( {
		action: 'query',
		format: 'json',
		formatversion: 2,
		list: 'readinglistentries',
		rlelimit: 100,
		rlelists: collectionId
	}, continueQuery ) ).then( ( /** @type {ApiQueryResponseReadingListEntries} */data ) => {
		const pages = data.query.readinglistentries;
		if ( data.continue ) {
			return getReadingListPages( collectionId, data.continue )
				.then( ( extraPages ) => pages.concat( extraPages ) );
		}
		return pages;
	} );
}

/**
 * Gets pages on a given reading list
 *
 * @param {number} collectionId
 * @return {jQuery.Promise<any>}
 */
function getPages( collectionId ) {
	const query = collectionId === WATCHLIST_ID ?
		getWatchlistPages() : getReadingListPages( collectionId );
	return query.then( (
		/** @type {ApiQueryResponseReadingListEntryItem[]} */ readinglistpages
	) => getPagesFromReadingListPages(
		readinglistpages
	).then( ( /** @type {ApiQueryResponsePage} */ pages ) =>
		// make sure project is passed down.
		pages.map( ( page, /** @type {number} */ i ) => Object.assign(
			readinglistpages[ i ], page
		) ).sort( ( a, b ) => a.title < b.title ? -1 : 1 )
	, () => Promise.reject( 'readinglistentries-error' ) ) );
}

/**
 * @param {string} name
 * @param {string} description
 * @param {ProjectTitleMap|ProjectTitleMap} list
 * @return {string}
 */
function toBase64( name, description, list ) {
	try {
		return btoa( JSON.stringify( { name, description, list } ) );
	} catch ( e ) {
		return '';
	}
}

/**
 * @param {ImportedList} importedList
 * @return {ImportedList}
 */
function normalizeImportedData( importedList ) {
	Object.keys( importedList.list ).forEach( ( key ) => {
		// If encounter a language code (no protocol or subdomain) assume Wikipedia
		if ( isLanguageCode( key ) ) {
			importedList.list[ `https://${ key }.wikipedia.org` ] = importedList.list[ key ];
			delete importedList.list[ key ];
		}
	} );
	return importedList;
}

/**
 * @param {string} data
 * @return {Object}
 */
function fromBase64( data ) {
	return normalizeImportedData( JSON.parse( atob( data ) ) );
}

module.exports = {
	WATCHLIST_ID,
	test: {
		readingListToCard,
		getProjectHost,
		getProjectApiUrl
	},
	fromBase64,
	toBase64,
	getPages,
	getCollectionMeta,
	getCollections,
	getPagesFromProjectMap
};
