import { getCollectionUrl, getCollectionsBaseUrl } from '../../../resources/ext.readingLists.api/urls.js';

describe( 'getCollectionsBaseUrl', () => {
	it( 'should return the correct base URL', () => {
		[
			[ 'Special:ReadingLists/Test', 'Special:ReadingLists' ],
			[ 'Spécial:ReadingLists/NewUser123/61/Cute_animals', 'Spécial:ReadingLists' ],
			[ 'Spécial:Saved/A', 'Spécial:Saved' ],
			[ 'Special:ReadingLists', 'Special:ReadingLists' ],
			[ 'Ειδικό:ReadingLists/Jon_(WMF)', 'Ειδικό:ReadingLists' ],
			[ 'Special:Saved/42', 'Special:Saved' ]
		].forEach( ( [ pageTitle, expected ] ) => {
			expect( getCollectionsBaseUrl( pageTitle ) ).toBe( expected );
		} );
	} );

} );
describe( 'getCollectionUrl', () => {
	beforeEach( () => {
		mw.util.wikiUrlencode = vi.fn( ( path ) => path );
	} );

	it( 'returns the base URL when listId or listName are not provided', () => {
		expect( getCollectionUrl() ).toBe( 'Special:Saved' );
		expect( getCollectionUrl( null, null ) ).toBe( 'Special:Saved' );
		expect( getCollectionUrl( '123', null ) ).toBe( 'Special:Saved' );
		expect( getCollectionUrl( null, 'MyCollection' ) ).toBe( 'Special:Saved' );
	} );

	it( 'returns the URL for a specific collection when listId and listName are provided', () => {
		expect( getCollectionUrl( '123', 'MyCollection' ) ).toBe( 'Special:Saved/123/MyCollection' );
	} );

	it( 'returns a relative URL for the base when isRelative is true and no listId or listName', () => {
		expect( getCollectionUrl( null, null, true ) ).toBe( '/' );
	} );

	it( 'returns a relative URL for a specific collection when isRelative is true', () => {
		expect( getCollectionUrl( '123', 'MyCollection', true ) ).toBe( '/123/MyCollection' );
		expect( mw.util.wikiUrlencode ).toHaveBeenCalledWith( '/123/MyCollection' );
	} );

	it( 'uses MediaWiki encoding for spaces in the collection name', () => {
		mw.util.wikiUrlencode.mockReturnValue( '/123/My_Collection' );
		expect( getCollectionUrl( '123', 'My Collection', true ) ).toBe( '/123/My_Collection' );
		expect( mw.util.wikiUrlencode ).toHaveBeenCalledWith( '/123/My Collection' );
	} );

	it( 'leaves page titles unencoded for callers of mw.util.getUrl', () => {
		expect( getCollectionUrl( '123', 'My Collection' ) ).toBe( 'Special:Saved/123/My Collection' );
		expect( mw.util.wikiUrlencode ).not.toHaveBeenCalled();
	} );
} );
