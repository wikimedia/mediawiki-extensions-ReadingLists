let api = new mw.Api();
const { session } = require( 'mediawiki.storage' );
const origin = window.location.protocol + '//' + window.location.hostname;
const CACHE_LIFETIME_SECONDS = 60 * 20; // 20 minutes

/**
 * Enroll the current user in the reading list feature.
 *
 * @return {Promise<any>}
 */
function setup() {
	return api.postWithEditToken( {
		action: 'readinglists',
		command: 'setup',
		formatversion: 2
	} );
}

/**
 * Get the reading lists created by the current user.
 *
 * @param {string} sort
 * @param {string} direction
 * @param {number} limit
 * @param {string|null} next
 * @return {Promise<any>}
 */
async function getLists( sort = 'name', direction = 'ascending', limit = 12, next = null ) {
	try {
		const { query: { readinglists: lists }, continue: rlcontinue } = await api.get( {
			action: 'query',
			meta: 'readinglists',
			rlsort: sort,
			rldir: direction,
			rllimit: limit,
			rlcontinue: next || undefined,
			formatversion: 2
		} );

		return {
			lists: lists.map( ( list ) => {
				if ( list.default ) {
					return Object.assign( {}, list, {
						name: mw.msg( 'readinglists-default-title' ),
						description: mw.msg( 'readinglists-default-description' )
					} );
				}

				return list;
			} ),
			next: rlcontinue && rlcontinue.rlcontinue || null
		};
	} catch ( err ) {
		if ( err === 'readinglists-db-error-not-set-up' ) {
			await setup();
			return getLists( sort, direction, limit, next );
		}

		throw err;
	}
}

/**
 * Get the metadata of a specific reading list.
 *
 * @param {number} listId
 * @return {Promise<any>}
 */
async function getList( listId ) {
	try {
		const response = await api.get( {
			action: 'query',
			meta: 'readinglists',
			rllist: listId,
			formatversion: 2
		} );

		const list = response.query.readinglists[ 0 ];

		if ( list.default ) {
			return Object.assign( {}, list, {
				name: mw.msg( 'readinglists-default-title' ),
				description: mw.msg( 'readinglists-default-description' )
			} );
		}

		return list;
	} catch ( err ) {
		if ( err === 'readinglists-db-error-not-set-up' ) {
			await setup();
			return getList( listId );
		}

		throw err;
	}
}

/**
 * Get a cache key for a specific reading list resource for use with session storage.
 *
 * @param {string} resourceKey
 * @return {string}
 */
const getCacheKey = ( resourceKey ) => `USER-READING-LISTS-${ resourceKey }`;

/**
 * Get all reading lists created by the current user sorted by
 * updated without the default list. Cached to session storage.
 *
 * @return {Promise<any>}
 */
async function getListsAll() {
	const cacheKey = getCacheKey( 'all' );
	const all = session.get( cacheKey );

	if ( all ) {
		return JSON.parse( all ).filter( ( list ) => !list.default );
	}
	const LIMIT_PER_QUERY = 5;
	const getListsFromApi = async ( next = null ) => {
		const result = await getLists( 'updated', 'descending', LIMIT_PER_QUERY, next );
		if ( result.next ) {
			const moreLists = await getListsFromApi( result.next );
			return result.lists.concat( moreLists );
		} else {
			return result.lists || [];
		}
	};
	const lists = await getListsFromApi();
	// store for 20 minutes until invalidated.
	session.set( cacheKey, JSON.stringify( lists ), CACHE_LIFETIME_SECONDS );
	return lists.filter( ( list ) => !list.default );
}

/**
 * Internal method for APIs to invalidate the local cache.
 *
 * @param {string} cacheKey
 */
function invalidateSessionCache( cacheKey ) {
	session.remove( getCacheKey( cacheKey ) );
}

/**
 * Get the entries saved in a specific reading list
 * or from all reading lists if listId is not provided.
 *
 * @param {number} listId
 * @param {string} sort
 * @param {string} direction
 * @param {number} limit
 * @param {string|null} next
 * @param {string[]} projects
 * @return {Promise<any>}
 */
async function getEntries( listId = null, sort = 'name', direction = 'asc', limit = 12, next = null, projects = [] ) {
	try {
		const apiParams = {
			action: 'query',
			list: 'readinglistentries',
			rlesort: sort,
			rledir: direction,
			rlelimit: limit,
			rlecontinue: next || undefined,
			formatversion: 2
		};

		if ( listId ) {
			apiParams.rlelists = listId;
		}

		if ( projects.length ) {
			apiParams.rleprojects = projects;
		}

		const {
			query: { readinglistentries: entries },
			continue: rlecontinue
		} = await api.get( apiParams );

		const manifest = {};

		for ( const entry of entries ) {
			if ( !Object.prototype.hasOwnProperty.call( manifest, entry.project ) ) {
				manifest[ entry.project ] = [];
			}

			manifest[ entry.project ].push( {
				id: entry.id,
				title: entry.title
			} );
		}

		const promises = [];

		for ( const [ project, entries2 ] of Object.entries( manifest ) ) {
			promises.push( getPagesFromManifest( project, entries2 ) );
		}

		const pages = Array.prototype.concat.apply( [], await Promise.all( promises ) );

		return {
			entries: entries.map( ( entry ) => pages.find( ( page ) => page.id === entry.id ) ),
			next: rlecontinue && rlecontinue.rlecontinue || null
		};
	} catch ( err ) {
		if ( err === 'readinglists-db-error-not-set-up' ) {
			await setup();
			return getEntries( listId, sort, direction, limit, next, projects );
		}

		throw err;
	}
}

const languageCodePattern = /^[A-Za-z-]+$/;

/**
 * Get the page info associated with the project and entries.
 *
 * @param {string} project
 * @param {Object[]} entries
 * @return {Promise<any>}
 */
async function getPagesFromManifest( project, entries ) {
	if ( languageCodePattern.test( project ) ) {
		project = `https://${ project }.wikipedia.org`;
	}

	const options = {};

	if ( project !== origin ) {
		options.url = project + '/w/api.php';
	}

	const isPageIds = Object.prototype.hasOwnProperty.call( entries[ 0 ], 'pageid' );

	try {
		const { query: { normalized, redirects, pages } } = await api.get( {
			action: 'query',
			origin: '*',
			prop: 'info|description|pageimages',
			titles: !isPageIds ? entries.map( ( entry ) => entry.title ).join( '|' ) : undefined,
			pageids: isPageIds ? entries.map( ( entry ) => entry.pageid ).join( '|' ) : undefined,
			redirects: true,
			inprop: 'url',
			piprop: 'thumbnail',
			pilicense: 'any',
			pithumbsize: 200,
			pilimit: entries.length,
			formatversion: 2
		}, options );

		return entries.map( ( entry, i ) => {
			let meta;
			let redirectUrl;
			let redirectTitle;
			let displayTitle;

			if ( isPageIds ) {
				meta = pages.find( ( page ) => page.pageid === entry.pageid );
			} else {
				let title = entry.title;
				if ( normalized !== undefined ) {
					const match = normalized.find( ( normal ) => normal.from === title );

					if ( match !== undefined ) {
						title = match.to;
					}
				}

				// The title the entry should display. For a redirect this stays
				// as the original (source) page's title.
				displayTitle = title;

				// The title used to find the page in the API response. The API
				// resolves redirects and returns the target page, so look the
				// page up by the redirect target when the entry is a redirect.
				let lookupTitle = title;

				if ( redirects !== undefined ) {
					const match = redirects.find( ( redirect ) => redirect.from === title );

					if ( match !== undefined ) {
						// Note where the entry redirects to, and point the URL back
						// to the original (redirect) page so a bookmarked redirect
						// can be navigated to and removed, e.g. when it duplicates
						// its redirect target.
						redirectTitle = match.to;
						redirectUrl = mw.util.getUrl( match.from, {
							redirect: 'no'
						} );
						lookupTitle = match.to;
					}
				}

				meta = pages.find( ( page ) => page.title === lookupTitle );
			}

			if ( meta === undefined ) {
				return {
					id: entry.id || -1 - i,
					project,
					title: entry.title || `#${ entry.pageid }`,
					description: null,
					thumbnail: null,
					url: redirectUrl || null,
					missing: true
				};
			}

			return {
				id: entry.id || -1 - i,
				project,
				redirectTitle,
				title: redirectTitle ? displayTitle : meta.title,
				description: meta.description || null,
				thumbnail: meta.thumbnail && meta.thumbnail.source || null,
				url: redirectUrl || meta.canonicalurl || null,
				missing: meta.missing === true
			};
		} );
	} catch ( err ) {
		if ( mw && mw.log && mw.log.error ) {
			mw.log.error( err );
		}

		return entries.map( ( entry, i ) => ( {
			id: entry.id || -1 - i,
			project,
			title: entry.title || `#${ entry.pageid }`,
			description: null,
			thumbnail: null,
			url: null,
			missing: true
		} ) );
	}
}

/**
 * Create a new entry in a reading list.
 *
 * @param {number} listId
 * @param {string} title
 * @return {Promise<any>}
 */
function createEntry( listId, title ) {
	return api.postWithEditToken( {
		action: 'readinglists',
		command: 'createentry',
		list: listId,
		project: '@local',
		title,
		formatversion: 2
	} );
}

/**
 * Save entry to default reading list.
 *
 * @param {string} title
 * @return {Promise<any>}
 */
function saveToDefaultList( title ) {
	return api.postWithEditToken( {
		action: 'readinglists',
		command: 'createentry',
		project: '@local',
		title,
		formatversion: 2
	} );
}

/**
 * Delete an existing entry from a reading list.
 *
 * @param {number} entryId
 * @return {Promise<any>}
 */
function deleteEntry( entryId ) {
	return api.postWithEditToken( {
		action: 'readinglists',
		command: 'deleteentry',
		entry: entryId,
		formatversion: 2
	} );
}

/**
 * Delete all entries for a given page title on the local project.
 *
 * @param {string} title
 * @return {Promise<any>}
 */
function deleteEntryByPageTitle( title ) {
	return api.postWithEditToken( {
		action: 'readinglists',
		command: 'deleteentry',
		title,
		project: '@local',
		formatversion: 2
	} );
}

/**
 * @param {string} data
 * @return {Object}
 */
async function fromBase64( data ) {
	let output;

	try {
		output = JSON.parse( atob( data ) );
	} catch ( err ) {
		return { error: err };
	}

	if ( !output.name ) {
		output.name = mw.msg( 'readinglists-no-title' );
	}

	const projectGroups = Object.entries( output.list );

	// Shared-list imports currently support Wikipedia language codes, not project URLs.
	if ( projectGroups.some( ( [ project ] ) => !languageCodePattern.test( project ) ) ) {
		return { error: 'readinglists-import-error' };
	}

	const promises = [];

	for ( const [ project, entries ] of projectGroups ) {
		promises.push( getPagesFromManifest(
			project,
			entries.map( ( entry ) => ( { pageid: entry } ) )
		) );
	}

	output.list = Array.prototype.concat.apply( [], await Promise.all( promises ) );
	output.size = output.list.length;

	for ( let i = 0; i < output.size; i++ ) {
		output.list[ i ].id = -1 - i;
	}

	return output;
}

/**
 * @param {string} name
 * @param {string} description
 * @param {string[]} list
 * @return {string}
 */
function toBase64( name, description, list ) {
	return btoa( JSON.stringify( { name, description, list } ) );
}

/**
 * Override the shared mw.Api with a stub class.
 * This should not be used outside test environment.
 *
 * @param {mw.Api} stub
 */
function stubApi( stub ) {
	api = stub;
}

/**
 * @param {string} name
 * @return {Promise<any>}
 */
const createList = async ( name ) => {
	const response = await api.postWithEditToken( {
		action: 'readinglists',
		command: 'create',
		name
	} );
	// Clear cache for all lists
	exports.invalidateSessionCache( 'all' );
	return response;
};

/**
 * @param {string} id
 * @param {string} name
 * @param {string} description
 * @return {Promise<any>}
 */
const updateList = async ( id, name, description ) => {
	const response = await api.postWithEditToken( {
		action: 'readinglists',
		command: 'update',
		list: id,
		name,
		description
	} );
	// Clear cache for all lists
	exports.invalidateSessionCache( 'all' );
	return response;
};

/**
 * @param {string} id
 * @return {Promise<any>}
 */
const deleteList = async ( id ) => {
	const response = await api.postWithEditToken( {
		action: 'readinglists',
		command: 'delete',
		list: id
	} );
	// Clear cache for all lists
	exports.invalidateSessionCache( 'all' );
	return response;
};

module.exports = exports = {
	setup,
	createList,
	updateList,
	deleteList,
	getListsAll,
	getLists,
	getList,
	getEntries,
	getPagesFromManifest,
	createEntry,
	saveToDefaultList,
	deleteEntry,
	deleteEntryByPageTitle,
	fromBase64,
	toBase64,
	stubApi,
	invalidateSessionCache
};
