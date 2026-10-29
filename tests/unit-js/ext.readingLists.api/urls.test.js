import { getCollectionUrl, getCollectionsBaseUrl } from '../../../resources/ext.readingLists.api/urls.js';

describe( 'getCollectionsBaseUrl', () => {
	it( 'should return the correct base URL', () => {
		[
			[ 'Special:ReadingLists/Test', 'Special:ReadingLists' ],
			[ 'Spécial:ReadingLists/NewUser123/61/Cute_animals', 'Spécial:ReadingLists' ],
			[ 'Spécial:Saved/A', 'Spécial:Saved' ],
			[ 'Special:ReadingLists', 'Special:ReadingLists' ],
			[ 'Ειδικό:ReadingLists/Jon_(WMF)', 'Ειδικό:ReadingLists' ]
		].forEach( ( [ pageTitle, expected ] ) => {
			expect( getCollectionsBaseUrl( pageTitle ) ).toBe( expected );
		} );
	} );

} );
describe( 'getCollectionUrl', () => {
	beforeEach( () => {
		mw.user.getName.mockReturnValue( 'Test' );
		mw.util.wikiUrlencode = vi.fn( ( path ) => path );
	} );

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

	it( 'uses MediaWiki encoding for spaces in the all-items route', () => {
		mw.user.getName.mockReturnValue( 'Test User' );
		mw.util.wikiUrlencode.mockReturnValue( '/Test_User' );
		expect( getCollectionUrl( null, null, true ) ).toBe( '/Test_User' );
		expect( mw.util.wikiUrlencode ).toHaveBeenCalledWith( '/Test User' );
	} );

	it( 'uses MediaWiki encoding for spaces in the username and collection name', () => {
		mw.user.getName.mockReturnValue( 'Test User' );
		mw.util.wikiUrlencode.mockReturnValue( '/Test_User/123/My_Collection' );
		expect( getCollectionUrl( '123', 'My Collection', true ) )
			.toBe( '/Test_User/123/My_Collection' );
		expect( mw.util.wikiUrlencode ).toHaveBeenCalledWith( '/Test User/123/My Collection' );
	} );

	it( 'uses MediaWiki encoding for non-ASCII and reserved characters', () => {
		mw.user.getName.mockReturnValue( 'Élodie Test' );
		mw.util.wikiUrlencode.mockReturnValue( '/%C3%89lodie_Test/123/Caf%C3%A9%3F_%231' );
		expect( getCollectionUrl( '123', 'Café? #1', true ) )
			.toBe( '/%C3%89lodie_Test/123/Caf%C3%A9%3F_%231' );
		expect( mw.util.wikiUrlencode ).toHaveBeenCalledWith( '/Élodie Test/123/Café? #1' );
		expect( mw.util.wikiUrlencode ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'leaves page titles unencoded for callers of mw.util.getUrl', () => {
		mw.user.getName.mockReturnValue( 'Test User' );
		expect( getCollectionUrl( '123', 'My Collection' ) )
			.toBe( 'Special:ReadingLists/Test User/123/My Collection' );
		expect( mw.util.wikiUrlencode ).not.toHaveBeenCalled();
	} );
} );
