const api = require( 'ext.readingLists.api' ).legacy;
const ENGLISH_WIKIPEDIA_PAGES = require( './fixtures/en_wikipedia.json' );
const ENGLISH_DOG_PAGES = require( './fixtures/en_wikipedia_dog.json' );
const SPANISH_DOG_PAGES = require( './fixtures/es_wikipedia_dog.json' );
const CITY_PAGES = require( './fixtures/cities.json' );
const COLLECTION_ONE = require( './fixtures/collection_1.json' );
const COLLECTION_DOGS = require( './fixtures/collection_dogs.json' );

describe( 'Developer mode', () => {
	test( 'getProjectHost returns en.wikipedia.org', () => {
		expect( api.test.getProjectHost( 'http://localhost:8888' ) ).toBe(
			'https://en.wikipedia.org'
		);
	} );
	test( 'getProjectApiUrl returns en.wikipedia.org', () => {
		expect( api.test.getProjectApiUrl( 'http://localhost:8888' ) ).toBe(
			'https://en.wikipedia.org/w/api.php'
		);
	} );
	test( 'getProjectApiUrl returns fr.wikipedia.org', () => {
		expect( api.test.getProjectApiUrl( 'fr' ) ).toBe(
			'https://fr.wikipedia.org/w/api.php'
		);
	} );
	test( 'getProjectApiUrl adds protocol to en.wikipedia.org', () => {
		expect( api.test.getProjectApiUrl( 'en.wikipedia.org' ) ).toBe(
			'//en.wikipedia.org/w/api.php'
		);
	} );
} );

describe( 'readingListToCard', () => {
	expect(
		api.test.readingListToCard( {
			name: 'list',
			description: 'A list',
			id: 1
		}, 'Jon' )
	).toStrictEqual( {
		name: 'list',
		id: 1,
		description: 'A list',
		ownerName: 'Jon',
		url: '/wiki/ReadingLists/Jon/1/list'
	} );
} );

describe( 'fromBase64', () => {
	test( 'Import and Export works', () => {
		const list = {
			'http://en.wikipedia.org': [ 59874, 31883, 24868, 14381 ],
			'http://ru.wikipedia.org': [ 59874, 31883, 24868, 14381 ]
		};
		const dataString = api.toBase64( 'A list', 'By me', list );
		const imported = api.fromBase64( dataString );
		expect( imported.list ).toStrictEqual( list );
	} );

	test( 'If language codes are passed as projects, these should be converted to project URLs', () => {
		const dataString = api.toBase64( 'A list', 'By me',
			{
				en: [ 59874, 31883, 24868, 14381 ],
				ru: [ 59874, 31883, 24868, 14381 ]
			}
		);
		const list = api.fromBase64( dataString );

		expect(
			list.list
		).toStrictEqual( {
			'https://en.wikipedia.org': [ 59874, 31883, 24868, 14381 ],
			'https://ru.wikipedia.org': [ 59874, 31883, 24868, 14381 ]
		} );
	} );
} );

describe( 'getThumbnailsAndDescriptions', () => {
	test( 'input pages order is preserved in output', () => {
		api.test.setApi( {
			get: jest.fn( () => Promise.resolve( ENGLISH_WIKIPEDIA_PAGES ) )
		} );

		api.getThumbnailsAndDescriptions( 'https://en.wikipedia.org', [
			'Wikimedia_Foundation',
			'Accessing_Wikipedia',
			'Five pillars of Wikipedia',
			'This is missing'
		] ).then( ( result ) => {
			expect( result ).toStrictEqual( [
				{
					from: 'Wikimedia_Foundation',
					title: 'Wikimedia Foundation',
					description: 'wikimedia description',
					project: 'https://en.wikipedia.org',
					url: 'https://en.wikipedia.org/wiki/Wikimedia Foundation',
					pageid: 222,
					thumbnail: null
				},
				{
					from: 'Accessing_Wikipedia',
					title: 'Wikipedia',
					description: 'wikipedia description',
					project: 'https://en.wikipedia.org',
					url: 'https://en.wikipedia.org/wiki/Wikipedia',
					pageid: 111,
					thumbnail: {
						width: 200,
						height: 200,
						url: 'https://upload.wikimedia.org/200px-wikipedia'
					}
				},
				{
					from: 'Five pillars of Wikipedia',
					title: 'Wikipedia',
					description: 'wikipedia description',
					project: 'https://en.wikipedia.org',
					url: 'https://en.wikipedia.org/wiki/Wikipedia',
					pageid: 111,
					thumbnail: {
						width: 200,
						height: 200,
						url: 'https://upload.wikimedia.org/200px-wikipedia'
					}
				},
				{
					from: 'This is missing',
					title: 'This is missing',
					missing: true,
					project: 'https://en.wikipedia.org',
					url: 'https://en.wikipedia.org/wiki/This is missing',
					pageid: undefined,
					thumbnail: null
				}
			] );
		} );
	} );
} );

describe( 'getReadingListPages', () => {
	test( 'Preserves thumbnails across projects where the title is the same', () => {
		api.test.setApi( {
			get: jest.fn( ( _options, host ) => {
				if ( !host ) {
					return Promise.resolve( COLLECTION_DOGS );
				}
				switch ( host.url ) {
					case 'https://es.wikipedia.org/w/api.php':
						return Promise.resolve( SPANISH_DOG_PAGES );
					case 'https://en.wikipedia.org/w/api.php':
						return Promise.resolve( ENGLISH_DOG_PAGES );
					default:
						throw new Error( `Unknown host ${ host.url }` );
				}
			} )
		} );

		const ENGLISH_THUMB_URL = 'https://upload.wikimedia.org/wikipedia/commons/thumb/7/7a/Huskiesatrest.jpg/200px-Huskiesatrest.jpg';
		const SPANISH_THUMB_URL = 'https://upload.wikimedia.org/wikipedia/commons/thumb/c/c2/Chien_D%27Eau_Espagnol.jpg/200px-Chien_D%27Eau_Espagnol.jpg';
		api.getPages( 5 ).then( ( result ) => {
			expect( result.length ).toBe( 3 );
			expect( result[ 0 ].project ).toBe( 'https://en.wikipedia.org' );
			expect( result[ 0 ].thumbnail.url ).toBe( ENGLISH_THUMB_URL );
			expect( result[ 1 ].project ).toBe( 'https://es.wikipedia.org' );
			expect( result[ 1 ].thumbnail.url ).toBe( SPANISH_THUMB_URL );
			expect( result[ 2 ].project ).toBe( 'https://es.wikipedia.org' );
			expect( result[ 2 ].thumbnail.url ).toBe( SPANISH_THUMB_URL );
		} );
	} );

	test( 'Preserves list item ID across projects and redirects', () => {
		api.test.setApi( {
			get: jest.fn( ( _options, host ) => {
				if ( !host ) {
					return Promise.resolve( COLLECTION_ONE );
				}
				switch ( host.url ) {
					case 'https://fr.wikipedia.org/w/api.php':
						return Promise.resolve( CITY_PAGES );
					case 'https://en.wikipedia.org/w/api.php':
						return Promise.resolve( ENGLISH_WIKIPEDIA_PAGES );
					default:
						throw new Error( `Unknown host ${ host.url }` );
				}
			} )
		} );

		api.getPages( 5 ).then( ( result ) => {
			expect( result.length ).toBe( 4 );
			expect( result[ 0 ].title ).toBe( 'Wikipedia' );
			expect( result[ 0 ].id ).toBe( 9 );
			expect( result[ 1 ].title ).toBe( 'Madrid' );
			expect( result[ 1 ].id ).toBe( 6 );
			expect( result[ 2 ].title ).toBe( 'Paris' );
			expect( result[ 2 ].id ).toBe( 1 );
			expect( result[ 3 ].title ).toBe( 'This is missing' );
			expect( result[ 3 ].id ).toBe( 11 );
		} );
	} );
} );

function randomId() {
	return Math.floor( Math.random() * 1000 ) + 1;
}

describe( 'createEntry', () => {
	test( 'entry is added to list', () => {
		const random = [ randomId(), randomId(), randomId(), randomId() ];
		const listEntries = [
			{
				id: random[ 1 ],
				project: 'https://en.wikipedia.org',
				title: 'One'
			},
			{
				id: random[ 2 ],
				project: 'https://fr.wikipedia.org',
				title: 'Two'
			}
		];

		api.test.setApi( {
			postWithToken: jest.fn( ( csrf, { action, command, list, project, title } ) => {
				if ( action === 'readinglists' && command === 'createentry' && list === random[ 0 ] ) {
					listEntries.push( { id: random[ 3 ], project, title } );
				}
			} )
		} );

		api.createEntry( random[ 0 ], 'Three' );

		expect( listEntries[ 0 ] ).toStrictEqual( {
			id: random[ 1 ],
			project: 'https://en.wikipedia.org',
			title: 'One'
		} );

		expect( listEntries[ 1 ] ).toStrictEqual( {
			id: random[ 2 ],
			project: 'https://fr.wikipedia.org',
			title: 'Two'
		} );

		expect( listEntries[ 2 ] ).toStrictEqual( {
			id: random[ 3 ],
			project: '@local',
			title: 'Three'
		} );
	} );
} );

describe( 'deleteEntry', () => {
	test( 'entry is removed from list', () => {
		const random = [ randomId(), randomId(), randomId() ];
		const listEntries = [
			{
				id: random[ 0 ],
				project: 'https://en.wikipedia.org',
				title: 'One'
			},
			{
				id: random[ 1 ],
				project: 'https://fr.wikipedia.org',
				title: 'Two'
			},
			{
				id: random[ 2 ],
				project: '@local',
				title: 'Three'
			}
		];

		api.test.setApi( {
			postWithToken: jest.fn( ( csrf, { action, command, entry } ) => {
				if ( action === 'readinglists' && command === 'deleteentry' ) {
					listEntries.splice( listEntries.findIndex( ( e ) => e.id === entry ), 1 );
				}
			} )
		} );

		api.deleteEntry( random[ 1 ] );

		expect( listEntries.length ).toBe( 2 );

		expect( listEntries[ 0 ] ).toStrictEqual( {
			id: random[ 0 ],
			project: 'https://en.wikipedia.org',
			title: 'One'
		} );

		expect( listEntries[ 1 ] ).toStrictEqual( {
			id: random[ 2 ],
			project: '@local',
			title: 'Three'
		} );
	} );
} );
