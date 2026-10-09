const { getCollectionUrl } = require( '../../../resources/ext.readingLists.common/index.js' );

describe( 'getCollectionUrl', () => {
	global.mw = {
		user: {
			getName: jest.fn().mockReturnValue( 'Test' )
		}
	};

	it( 'should return the URL for all collections when listId and listName are not provided', () => {
		const url = getCollectionUrl( null, null );
		expect( url ).toBe( 'Special:ReadingLists/Test' );
	} );

	it( 'should return the URL for a specific collection when listId and listName are provided', () => {
		const url = getCollectionUrl( '123', 'MyCollection' );
		expect( url ).toBe( 'Special:ReadingLists/Test/123/MyCollection' );
	} );

	it( 'should return a relative URL when isRelative is true and listId and listName are not provided', () => {
		const url = getCollectionUrl( null, null, true );
		expect( url ).toBe( '/Test' );
	} );

	it( 'should return a relative URL when isRelative is true and listId and listName are provided', () => {
		const url = getCollectionUrl( '123', 'MyCollection', true );
		expect( url ).toBe( '/Test/123/MyCollection' );
	} );
} );
