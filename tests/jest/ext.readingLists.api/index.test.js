const api = require( '../../../resources/ext.readingLists.api/index.js' );
const SETUP = require( '../fixtures/setup.json' );
const LISTS = require( '../fixtures/lists.json' );
const LISTS2 = require( '../fixtures/lists2.json' );
const LIST = require( '../fixtures/list.json' );
const DEFAULTLIST = require( '../fixtures/defaultlist.json' );
const ENTRIES = require( '../fixtures/entries.json' );
const ENTRIES2 = require( '../fixtures/entries2.json' );
const PAGES = require( '../fixtures/pages.json' );
const CREATEENTRY = require( '../fixtures/createentry.json' );
const DELETEENTRY = require( '../fixtures/deleteentry.json' );

function translateList( list ) {
	if ( list.default ) {
		return {
			...list,
			name: mw.msg( 'readinglists-default-title' ),
			description: mw.msg( 'readinglists-default-description' )
		};
	}

	return list;
}

function entryToPage( entry ) {
	let meta;

	if ( Object.prototype.hasOwnProperty.call( entry, 'pageid' ) ) {
		meta = PAGES.query.pages.find( ( page ) => page.pageid === entry.pageid );
	} else {
		let title = entry.title.replace( /_/g, ' ' );

		if ( title === 'New York New York' ) {
			title = 'New York City';
		}

		meta = PAGES.query.pages.find( ( page ) => page.title === title );
	}

	if ( meta === undefined ) {
		return {
			id: entry.id,
			project: entry.project,
			title: entry.title || `#${ entry.pageid }`,
			description: null,
			thumbnail: null,
			url: null,
			missing: true
		};
	}

	return {
		id: entry.id,
		project: entry.project,
		title: meta.title,
		description: meta.description || null,
		thumbnail: meta.thumbnail && meta.thumbnail.source || null,
		url: meta.canonicalurl,
		missing: meta.pageid === null
	};
}

describe( 'setup', () => {
	test( 'returns inserted list', async () => {
		api.stubApi( {
			postWithEditToken: jest.fn( ( { action, command } ) => {
				if ( action === 'readinglists' && command === 'setup' ) {
					return SETUP;
				}
			} )
		} );

		const response = await api.setup();
		expect( response ).toStrictEqual( SETUP );
	} );
} );

describe( 'getLists', () => {
	test( 'returns array of multiple lists', async () => {
		api.stubApi( {
			get: jest.fn( ( { action, meta } ) => {
				if ( action === 'query' && meta === 'readinglists' ) {
					return LISTS;
				}
			} )
		} );

		const response = await api.getLists();
		expect( response ).toStrictEqual( {
			lists: LISTS.query.readinglists.map( translateList ),
			next: LISTS.continue.rlcontinue
		} );
	} );

	test( 'paginates with continue token', async () => {
		const continueToken = LISTS.continue.rlcontinue;

		api.stubApi( {
			get: jest.fn( ( { action, meta, rlcontinue } ) => {
				if ( action === 'query' && meta === 'readinglists' && rlcontinue === continueToken ) {
					return LISTS2;
				}
			} )
		} );

		const response = await api.getLists( 'name', 'asc', 12, continueToken );
		expect( response ).toStrictEqual( {
			lists: LISTS2.query.readinglists.map( translateList ),
			next: null
		} );
	} );

	test( 'sets up default list if missing', async () => {
		let isSetup = false;

		api.stubApi( {
			get: jest.fn( ( { action, meta } ) => {
				if ( action === 'query' && meta === 'readinglists' ) {
					if ( isSetup ) {
						return LISTS;
					} else {
						// eslint-disable-next-line no-throw-literal
						throw 'readinglists-db-error-not-set-up';
					}
				}
			} ),
			postWithEditToken: jest.fn( ( { action, command } ) => {
				if ( action === 'readinglists' && command === 'setup' ) {
					isSetup = true;
					return SETUP;
				}
			} )
		} );

		const response = await api.getLists();
		expect( response ).toStrictEqual( {
			lists: LISTS.query.readinglists.map( translateList ),
			next: LISTS.continue.rlcontinue
		} );
	} );

	test( 'throws on unhandled error', async () => {
		const values = [ 'name', 'updated' ];

		api.stubApi( {
			get: jest.fn( ( { action, meta, sort } ) => {
				if ( action === 'query' && meta === 'readinglists' && !values.includes( sort ) ) {
					// eslint-disable-next-line no-throw-literal
					throw 'badvalue';
				}
			} )
		} );

		try {
			await api.getLists( 'foo' );
		} catch ( err ) {
			expect( err ).toBe( 'badvalue' );
		}
	} );
} );

describe( 'getList', () => {
	test( 'returns metadata of one list', async () => {
		const list = LIST.query.readinglists[ 0 ];

		api.stubApi( {
			get: jest.fn( ( { action, meta, rllist } ) => {
				if ( action === 'query' && meta === 'readinglists' && rllist === list.id ) {
					return LIST;
				}
			} )
		} );

		const response = await api.getList( list.id );
		expect( response ).toStrictEqual( list );
	} );

	test( 'translates default list name/description', async () => {
		const defaultList = DEFAULTLIST.query.readinglists[ 0 ];

		api.stubApi( {
			get: jest.fn( ( { action, meta, rllist } ) => {
				if ( action === 'query' && meta === 'readinglists' && rllist === defaultList.id ) {
					return DEFAULTLIST;
				}
			} )
		} );

		const response = await api.getList( defaultList.id );
		expect( response ).toStrictEqual( translateList( defaultList ) );
	} );

	test( 'sets up default list if missing', async () => {
		const firstList = LIST.query.readinglists[ 0 ];
		let isSetup = false;

		api.stubApi( {
			get: jest.fn( ( { action, meta, rllist } ) => {
				if ( action === 'query' && meta === 'readinglists' && rllist === firstList.id ) {
					if ( isSetup ) {
						return LIST;
					} else {
						// eslint-disable-next-line no-throw-literal
						throw 'readinglists-db-error-not-set-up';
					}
				}
			} ),
			postWithEditToken: jest.fn( ( { action, command } ) => {
				if ( action === 'readinglists' && command === 'setup' ) {
					isSetup = true;
					return SETUP;
				}
			} )
		} );

		const response = await api.getList( firstList.id );
		expect( response ).toStrictEqual( translateList( firstList ) );
	} );

	test( 'throws on unhandled error', async () => {
		api.stubApi( {
			get: jest.fn( ( { action, meta, rllist } ) => {
				if ( action === 'query' && meta === 'readinglists' && isNaN( parseInt( rllist ) ) ) {
					// eslint-disable-next-line no-throw-literal
					throw 'badinteger';
				}
			} )
		} );

		try {
			await api.getList( 'oops!' );
		} catch ( err ) {
			expect( err ).toBe( 'badinteger' );
		}
	} );
} );

describe( 'getEntries', () => {
	test( 'returns array of list entries', async () => {
		const listId = ENTRIES.query.readinglistentries[ 0 ].listId;

		api.stubApi( {
			get: jest.fn( ( { action, list, rlelists, titles } ) => {
				if ( action === 'query' ) {
					if ( list === 'readinglistentries' && rlelists === listId ) {
						return ENTRIES;
					} else if ( titles !== undefined ) {
						return PAGES;
					}
				}
			} )
		} );

		const response = await api.getEntries( listId, 'name', 'asc', 4, null );
		expect( response ).toStrictEqual( {
			entries: ENTRIES.query.readinglistentries.map( entryToPage ),
			next: ENTRIES.continue.rlecontinue
		} );
	} );

	test( 'paginates with continue token', async () => {
		const listId = ENTRIES.query.readinglistentries[ 0 ].listId;

		api.stubApi( {
			get: jest.fn( ( { action, list, rlelists, titles } ) => {
				if ( action === 'query' ) {
					if ( list === 'readinglistentries' && rlelists === listId ) {
						return ENTRIES2;
					} else if ( titles !== undefined ) {
						return PAGES;
					}
				}
			} )
		} );

		const response = await api.getEntries( listId, 'name', 'asc', 4, ENTRIES.continue.rlecontinue );
		expect( response ).toStrictEqual( {
			entries: ENTRIES2.query.readinglistentries.map( entryToPage ),
			next: null
		} );
	} );

	test( 'sets up default list if missing', async () => {
		const listId = ENTRIES.query.readinglistentries[ 0 ].listId;
		let isSetup = false;

		api.stubApi( {
			get: jest.fn( ( { action, list, rlelists, titles } ) => {
				if ( action === 'query' ) {
					if ( list === 'readinglistentries' && rlelists === listId ) {
						if ( isSetup ) {
							return ENTRIES;
						} else {
							// eslint-disable-next-line no-throw-literal
							throw 'readinglists-db-error-not-set-up';
						}
					} else if ( titles !== undefined ) {
						return PAGES;
					}
				}
			} ),
			postWithEditToken: jest.fn( ( { action, command } ) => {
				if ( action === 'readinglists' && command === 'setup' ) {
					isSetup = true;
					return SETUP;
				}
			} )
		} );

		const response = await api.getEntries( listId, 'name', 'asc', 4, null );
		expect( response ).toStrictEqual( {
			entries: ENTRIES.query.readinglistentries.map( entryToPage ),
			next: ENTRIES.continue.rlecontinue
		} );
	} );

	test( 'throws on unhandled error', async () => {
		api.stubApi( {
			get: jest.fn( ( { action, list, rlelists } ) => {
				if ( action === 'query' && list === 'readinglistentries' && isNaN( parseInt( rlelists ) ) ) {
					// eslint-disable-next-line no-throw-literal
					throw 'badinteger';
				}
			} )
		} );

		try {
			await api.getEntries( 'oops!' );
		} catch ( err ) {
			expect( err ).toBe( 'badinteger' );
		}
	} );
} );

describe( 'getPagesFromManifest', () => {
	const project = 'https://en.wikipedia.org';
	const manifest = [
		...ENTRIES.query.readinglistentries,
		...ENTRIES2.query.readinglistentries
	].map( ( entry ) => ( { id: entry.id, project, title: entry.title } ) );

	test( 'returns array of page metadata', async () => {
		api.stubApi( {
			get: jest.fn( ( { action, titles } ) => {
				if ( action === 'query' ) {
					const parts = titles.split( '|' );

					for ( let i = 0; i < manifest.length; i++ ) {
						if ( parts[ i ] !== manifest[ i ].title ) {
							return {};
						}
					}

					return PAGES;
				}
			} )
		} );

		const response = await api.getPagesFromManifest( 'http://localhost', manifest );
		expect( response ).toStrictEqual( manifest.map( ( entry ) => entryToPage( {
			...entry,
			project: 'http://localhost'
		} ) ) );
	} );

	test( 'transforms language code to url', async () => {
		api.stubApi( {
			get: jest.fn( ( { action, titles }, { url } ) => {
				if ( action === 'query' && url === `${ project }/w/api.php` ) {
					const parts = titles.split( '|' );

					for ( let i = 0; i < manifest.length; i++ ) {
						if ( parts[ i ] !== manifest[ i ].title ) {
							return {};
						}
					}

					return PAGES;
				}
			} )
		} );

		const response = await api.getPagesFromManifest( 'en', manifest );
		expect( response ).toStrictEqual( manifest.map( entryToPage ) );
	} );

	test( 'resolves page ids to titles', async () => {
		const manifest2 = PAGES.query.pages.map( ( { pageid }, i ) => ( {
			id: i + 1,
			pageid: pageid || 1
		} ) );

		api.stubApi( {
			get: jest.fn( ( { action, pageids }, { url } ) => {
				if ( action === 'query' && url === `${ project }/w/api.php` ) {
					const parts = pageids.split( '|' ).map( ( id ) => parseInt( id ) );

					for ( let i = 0; i < manifest2.length; i++ ) {
						if ( parts[ i ] !== manifest2[ i ].pageid ) {
							return {};
						}
					}

					return PAGES;
				}
			} )
		} );

		const response = await api.getPagesFromManifest( project, manifest2 );
		expect( response ).toStrictEqual( PAGES.query.pages.map( ( page, i ) => entryToPage( {
			id: i + 1,
			project,
			pageid: page.pageid || 1
		} ) ) );
	} );

	test( 'returns fallback on error', async () => {
		api.stubApi( {
			get: jest.fn( ( { action }, { url } ) => {
				if ( action === 'query' && url === `${ project }/w/api.php` ) {
					// eslint-disable-next-line no-throw-literal
					throw 'whatever';
				}
			} )
		} );

		const response = await api.getPagesFromManifest( project, manifest );
		expect( response ).toStrictEqual( manifest.map( ( page ) => entryToPage( {
			id: page.id,
			project,
			title: page.title,
			pageid: null
		} ) ) );
	} );
} );

describe( 'createEntry', () => {
	test( 'returns inserted entry', async () => {
		const entry = CREATEENTRY.createentry.entry;

		api.stubApi( {
			postWithEditToken: jest.fn( ( { action, command, list, project, title } ) => {
				if (
					action === 'readinglists' &&
					command === 'createentry' &&
					list === entry.listId &&
					project === '@local' &&
					title === entry.title
				) {
					return CREATEENTRY;
				}
			} )
		} );

		const response = await api.createEntry( entry.listId, entry.title );
		expect( response ).toStrictEqual( CREATEENTRY );
	} );
} );

describe( 'deleteEntry', () => {
	test( 'returns success message', async () => {
		const entryId = CREATEENTRY.createentry.entry.id;

		api.stubApi( {
			postWithEditToken: jest.fn( ( { action, command, entry } ) => {
				if ( action === 'readinglists' && command === 'deleteentry' && entry === entryId ) {
					return DELETEENTRY;
				}
			} )
		} );

		const response = await api.deleteEntry( entryId );
		expect( response ).toStrictEqual( DELETEENTRY );
	} );
} );

describe( 'fromBase64', () => {
	const project = 'https://en.wikipedia.org';
	const data = {
		description: 'Is this thing on?',
		list: { en: PAGES.query.pages.map( ( { pageid } ) => pageid || 1 ) },
		size: PAGES.query.pages.length
	};

	test( 'returns entries with resolved pages', async () => {
		api.stubApi( {
			get: jest.fn( ( { action, pageids }, { url } ) => {
				if ( action === 'query' && url === `${ project }/w/api.php` ) {
					const parts = pageids.split( '|' ).map( ( id ) => parseInt( id ) );
					const ids = data.list.en;

					for ( let i = 0; i < ids.length; i++ ) {
						if ( parts[ i ] !== ids[ i ] ) {
							return {};
						}
					}

					return PAGES;
				}
			} )
		} );

		const response = await api.fromBase64(
			api.toBase64( data.name, data.description, data.list )
		);
		expect( response ).toStrictEqual( {
			...data,
			name: mw.msg( 'readinglists-no-title' ),
			list: PAGES.query.pages.map( ( page, i ) => entryToPage( {
				id: -1 - i,
				project,
				pageid: page.pageid || 1
			} ) )
		} );
	} );

	test( 'returns error message', async () => {
		const response = await api.fromBase64( 'invalid base64' );
		expect( response ).toStrictEqual( { error: new DOMException(
			'The string to be decoded contains invalid characters.',
			'InvalidCharacterError'
		) } );
	} );
} );

describe( 'toBase64', () => {

} );
