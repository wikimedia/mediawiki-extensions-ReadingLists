/**
 * FIXME: The functions in this file precede the API rewrite in
 * I3ea570868466814188f4b584583a6108baed8382.
 * Over time these should be reviewed and either:
 *  - moved to index.js
 *  - removed.
 */
const DEFAULT_READING_LIST_NAME = mw.msg( 'readinglists-default-title' );
const DEFAULT_READING_LIST_DESCRIPTION = mw.msg( 'readinglists-default-description' );
const { getReadingListUrl } = require( './utils.js' );
const config = require( './config.json' );
let api = new mw.Api();
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
const isLanguageCode = ( project ) => !project.includes( '//' ) && !project.includes( '.' ) && !project.includes( ':' );

/**
 * From a project identifier work out which API to use.
 *
 * @param {string} project
 * @return {string}
 */
function getProjectHost( project ) {
	const isLang = isLanguageCode( project );
	const hasProtocol = project.includes( '//' );
	if ( config.ReadingListsDeveloperMode ) {
		if ( project.includes( 'localhost' ) ) {
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
const transformPage = ( project ) => ( page ) => Object.assign( {}, page, {
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
 * @return {mw.Api~AbortablePromise}
 */
function setupCollections() {
	return api.postWithToken( 'csrf', {
		action: 'readinglists',
		command: 'setup',
		formatversion: 2
	} );
}

// Cache the return value of getDefaultReadingList, since it's expensive
let cachedDefaultId = null;

/**
 * Gets the default list ID by iterating through the metadata API in groups of 10
 *
 * @param {string} [title] If provided, returns null if the list doesn't contain this page title.
 * @return {Promise<number|null>}
 */
async function getDefaultReadingList( title ) {
	if ( cachedDefaultId !== null ) {
		return cachedDefaultId;
	}

	try {
		let rlcontinue;

		// Don't loop more than 10 times as fail-safe
		for ( let i = 0; i < 10; i++ ) {
			const res = await api.get( {
				action: 'query',
				meta: 'readinglists',
				rlproject: title === undefined ? undefined : '@local',
				rltitle: title,
				rlcontinue,
				formatversion: 2
			} );

			for ( const list of res.query.readinglists ) {
				if ( list.default === true ) {
					cachedDefaultId = list.id;
					return cachedDefaultId;
				}
			}

			if ( !res.continue || !res.continue.rlcontinue ) {
				break;
			}

			rlcontinue = res.continue.rlcontinue;
		}

		return null;
	} catch ( error ) {
		if ( error !== 'readinglists-db-error-not-set-up' ) {
			throw error;
		}

		await setupCollections();
		return getDefaultReadingList();
	}
}

/**
 * Create a new entry in a reading list.
 *
 * @param {number} list
 * @param {string} title
 * @return {mw.Api~AbortablePromise}
 */
function createEntry( list, title ) {
	return api.postWithToken( 'csrf', {
		action: 'readinglists',
		command: 'createentry',
		list,
		project: '@local',
		title,
		formatversion: 2
	} );
}

/**
 * Deletes an existing entry from a reading list.
 *
 * @param {number} entry
 * @return {mw.Api~AbortablePromise}
 */
function deleteEntry( entry ) {
	return api.postWithToken( 'csrf', {
		action: 'readinglists',
		command: 'deleteentry',
		entry,
		formatversion: 2
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

	return api.get( {
		action: 'query',
		meta: 'readinglists',
		rllist: id,
		formatversion: 2
	} ).then( ( data ) => readingListToCard(
		data.query.readinglists[ 0 ],
		ownerName
	) );
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
async function getPagesFromProjectMap( projectMap ) {
	const promises = [];

	for ( const [ project, titles ] of Object.entries( projectMap ) ) {
		promises.push( getPagesFromPageIdentifiers( project, titles ) );
	}

	return Promise.all( promises ).then( ( pages ) => Array.prototype.concat.apply( [], pages ) );
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
	if ( pageidsOrPageTitles.length === 0 ) {
		return Promise.resolve( [] );
	}

	const isPageIds = pageidsOrPageTitles[ 0 ] && typeof pageidsOrPageTitles[ 0 ] === 'number';

	return api.get( {
		action: 'query',
		origin: '*',
		redirects: true,
		pilimit: pageidsOrPageTitles.length,
		prop: 'pageimages|description',
		pageids: isPageIds ? pageidsOrPageTitles : undefined,
		titles: isPageIds ? undefined : pageidsOrPageTitles,
		piprop: 'thumbnail',
		pithumbsize: 200,
		formatversion: 2
	}, {
		url: `${ getProjectApiUrl( project ) }`
	} ).then( ( data ) => pageidsOrPageTitles.map( ( title ) => {
		const from = title;

		if ( 'normalized' in data.query ) {
			const normal = data.query.normalized.find( ( n ) => n.from === title );

			if ( normal !== undefined ) {
				title = normal.to;
			}
		}

		if ( 'redirects' in data.query ) {
			const redirect = data.query.redirects.find( ( r ) => r.from === title );

			if ( redirect !== undefined ) {
				title = redirect.to;
			}
		}

		return Object.assign( { from }, data.query.pages.find( ( p ) => p.title === title ) );
	} )
		.filter( ( page ) => isPageIds ? !page.missing : true )
		.map( transformPage( project ) )
	);
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
	return getPagesFromProjectMap( toProjectTitlesMap( pages ) ).then(
		( results ) => pages.map( ( page ) => results.find(
			( result ) => result.project === page.project && result.from === page.title
		) )
	);
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
		wrnamespace: 0,
		list: 'watchlistraw',
		wrlimit: 'max',
		formatversion: 2
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
		list: 'readinglistentries',
		rlelimit: 100,
		rlelists: collectionId,
		formatversion: 2
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
	return query.then( ( pages ) => getPagesFromReadingListPages( pages ).then(
		( pages2 ) => pages2.map( ( page, i ) => Object.assign( pages[ i ], page ) )
		, () => {
			throw new Error( 'readinglistentries-error' );
		} ) );
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

/**
 * Allows test to stub the active API.
 * Should not be used outside test environment.
 *
 * @param {Object} newApi
 */
function setApi( newApi ) {
	api = newApi;
}

module.exports = {
	getReadingListUrl,
	config,
	WATCHLIST_ID,
	test: {
		readingListToCard,
		getProjectHost,
		getProjectApiUrl,
		setApi
	},
	fromBase64,
	toBase64,
	getDefaultReadingList,
	createEntry,
	deleteEntry,
	getPages,
	getCollectionMeta,
	getCollections,
	getPagesFromProjectMap,
	getThumbnailsAndDescriptions
};
