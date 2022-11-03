const api = require( '../../../resources/readinglist.scripts/api.js' );

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
