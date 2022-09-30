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
