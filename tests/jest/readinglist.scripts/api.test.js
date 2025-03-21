const api = require( '../../../resources/readinglist.scripts/api.js' );
const ENGLISH_WIKIPEDIA_PAGES = require( './fixtures/en_wikipedia.json' );
const CITY_PAGES = require( './fixtures/cities.json' );
const COLLECTION_ONE = require( './fixtures/collection_1.json' );

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

		api.getPages( 1 ).then( ( result ) => {
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
