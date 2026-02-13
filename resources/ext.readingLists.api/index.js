let api = new mw.Api();
const origin = window.location.protocol + '//' + window.location.hostname;

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
async function getLists( sort = 'name', direction = 'asc', limit = 12, next = null ) {
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
 * Get the entries saved in a specific reading list
 * or from all reading lists if listId is not provided.
 *
 * @param {number} listId
 * @param {string} sort
 * @param {string} direction
 * @param {number} limit
 * @param {string|null} next
 * @return {Promise<any>}
 */
async function getEntries( listId = null, sort = 'name', direction = 'asc', limit = 12, next = null ) {
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
			return getEntries( listId, sort, direction, limit, next );
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

				if ( redirects !== undefined ) {
					const match = redirects.find( ( redirect ) => redirect.from === title );

					if ( match !== undefined ) {
						title = match.to;
					}
				}

				meta = pages.find( ( page ) => page.title === title );
			}

			if ( meta === undefined ) {
				return {
					id: entry.id || -1 - i,
					project,
					title: entry.title || `#${ entry.pageid }`,
					description: null,
					thumbnail: null,
					url: null,
					missing: true
				};
			}

			return {
				id: entry.id || -1 - i,
				project,
				title: meta.title,
				description: meta.description || null,
				thumbnail: meta.thumbnail && meta.thumbnail.source || null,
				url: meta.canonicalurl || null,
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

	const promises = [];

	for ( const [ project, entries ] of Object.entries( output.list ) ) {
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

module.exports = exports = {
	setup,
	getLists,
	getList,
	getEntries,
	getPagesFromManifest,
	createEntry,
	deleteEntry,
	fromBase64,
	toBase64,
	stubApi
};
