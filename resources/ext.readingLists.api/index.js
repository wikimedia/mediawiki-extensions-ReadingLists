let api = new mw.Api();

/**
 * Enroll the current user in the reading list feature.
 *
 * @return {mw.Api~AbortablePromise}
 */
function setup() {
	return api.postWithToken( 'csrf', {
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
			rlcontinue: next === null ? undefined : next,
			formatversion: 2
		} );

		for ( const list of lists ) {
			if ( list.default ) {
				list.name = mw.msg( 'readinglists-default-title' );
				list.description = mw.msg( 'readinglists-default-description' );
				break;
			}
		}

		return { lists, next: rlcontinue === undefined ? null : rlcontinue.rlcontinue };
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
			list.name = mw.msg( 'readinglists-default-title' );
			list.description = mw.msg( 'readinglists-default-description' );
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
 * Get the entries saved in a specific reading list.
 *
 * @param {number} listId
 * @param {string} sort
 * @param {string} direction
 * @param {number} limit
 * @param {string|null} next
 * @return {Promise<any>}
 */
async function getEntries( listId, sort = 'name', direction = 'asc', limit = 12, next = null ) {
	try {
		const { query: { readinglistentries: entries }, continue: rlecontinue } = await api.get( {
			action: 'query',
			list: 'readinglistentries',
			rlelists: listId,
			rlesort: sort,
			rledir: direction,
			rlelimit: limit,
			rlecontinue: next === null ? undefined : next,
			formatversion: 2
		} );

		const manifest = {};

		for ( const entry of entries ) {
			if ( !Object.prototype.hasOwnProperty.call( manifest, entry.project ) ) {
				manifest[ entry.project ] = [];
			}

			manifest[ entry.project ].push( {
				id: entry.id,
				project: entry.project,
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
			next: rlecontinue === undefined ? null : rlecontinue.rlecontinue
		};
	} catch ( err ) {
		if ( err === 'readinglists-db-error-not-set-up' ) {
			await setup();
			return getEntries( listId, sort, direction, limit, next );
		}

		throw err;
	}
}

async function getPagesFromManifest( project, entries ) {
	try {
		const { query: { normalized, redirects, pages } } = await api.get( {
			action: 'query',
			origin: '*',
			prop: 'info|description|pageimages',
			titles: entries.map( ( entry ) => entry.title ).join( '|' ),
			redirects: true,
			inprop: 'url',
			piprop: 'thumbnail',
			pilicense: 'any',
			pithumbsize: 200,
			pilimit: entries.length,
			formatversion: 2
		}, {
			url: `${ project }/w/api.php`
		} );

		return entries.map( ( entry ) => {
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

			const meta = pages.find( ( page ) => page.title === title );

			return {
				id: entry.id,
				project: entry.project,
				title: meta.title,
				description: meta.description,
				thumbnail: meta.thumbnail.source,
				url: meta.canonicalurl
			};
		} );
	} catch ( err ) {
		return entries;
	}
}

/**
 * Create a new entry in a reading list.
 *
 * @param {number} listId
 * @param {string} title
 * @return {mw.Api~AbortablePromise}
 */
function createEntry( listId, title ) {
	return api.postWithToken( 'csrf', {
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
 * @return {mw.Api~AbortablePromise}
 */
function deleteEntry( entryId ) {
	return api.postWithToken( 'csrf', {
		action: 'readinglists',
		command: 'deleteentry',
		entry: entryId,
		formatversion: 2
	} );
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

module.exports = {
	setup,
	getLists,
	getList,
	getEntries,
	createEntry,
	deleteEntry,
	stubApi,
	legacy: require( './legacy.js' )
};
