'use strict';
const { REST, assert, action } = require( 'api-testing' );

describe( 'ReadingLists', function () {
	let alice, restfulAlice, token;
	let bob, bob_token, restfulBob;

	before( async function () {
		alice = await action.alice();
		bob = await action.bob();
		restfulAlice = new REST( 'rest.php/readinglists/v0', alice );
		restfulBob = new REST( 'rest.php/readinglists/v0', bob );
		token = await alice.token();
		bob_token = await bob.token();
	} );

	describe( 'POST /lists/setup and /lists/teardown', function () {
		it( 'should fail teardown if user has not set up a reading list', async function () {
			// Assuming the TeardownHandler checks for a valid setup before tearing down
			const response = await restfulAlice.post( '/lists/teardown' ).send( { token } );
			assert.isAtLeast( response.status, 400, response.text );
		} );

		it( 'setup should fail without valid token', async function () {
			const response = await restfulAlice.post( '/lists/setup' );
			assert.deepEqual( response.status, 403, response.text );
		} );

		it( 'should setup list for the user', async function () {
			const response = await restfulAlice.post( '/lists/setup' ).send( { token } );
			assert.deepEqual( response.status, 200, response.text );

		} );

		it( 'teardown should fail without valid token', async function () {
			const response = await restfulAlice.post( '/lists/teardown' );
			assert.deepEqual( response.status, 403, response.text );
		} );

		it( 'should teardown lists for the user', async function () {
			const response = await restfulAlice.post( '/lists/teardown' ).send( { token } );
			assert.deepEqual( response.status, 200, response.text );
		} );
	} );

	describe( 'POST, GET & DEL /lists', function () {
		let listId;
		before( async function () {
			const response = await restfulAlice.post( '/lists/setup' ).send( { token } );
			assert.deepEqual( response.status, 200, response.text );
			// setting up for the user Bob
			const response_bob = await restfulBob.post( '/lists/setup' ).send( { token: bob_token } );
			assert.deepEqual( response_bob.status, 200, response_bob.text );
		} );

		// Helper function to create a list
		async function createList( name, description ) {
			const reqBody = {
				name: name,
				description: description
			};
			return await restfulAlice.post( '/lists', reqBody ).send( { token } );
		}

		// Test case for getting a list with valid parameters
		it( 'should create a list', async function () {
			const createResponse = await createList( 'cats', 'Meow!' );
			assert.deepEqual( createResponse.status, 200, createResponse.text );

			// verify that the created list was indeed "created'
			const response = await restfulAlice.get( '/lists' ).query( { limit: 1 } );
			assert.deepEqual( response.status, 200, response.text );
			assert.isArray( response.body.lists );
			assert.lengthOf( response.body.lists, 1 );
			assert.deepInclude( response.body.lists[ 0 ], { name: 'cats', description: 'Meow!', default: false } );
			listId = response.body.lists[ 0 ].id;
		} );

		it( 'should get lists for the user', async function () {
			const response = await restfulAlice.get( '/lists' );
			assert.deepEqual( response.status, 200, response.text );
			assert.property( response.body, 'lists' );
			assert.property( response.body, 'continue-from' );
			assert.isArray( response.body.lists );
			assert.lengthOf( response.body.lists, 2 );
			assert.deepEqual( response.body.lists[ 0 ].name, 'cats' );
			assert.deepEqual( response.body.lists[ 1 ].name, 'default' );
		} );

		it( ' should get list by id', async function () {
			// getting the list by ID
			const response = await restfulAlice.get( `/lists/${listId}` );
			assert.deepEqual( response.status, 200, response.text );

			// Assert that the returned list matches the expected properties
			const expectedList = {
				id: listId,
				name: 'cats',
				description: 'Meow!',
				default: false
			};
			assert.deepInclude( response.body, expectedList, 'Returned list should match expected properties' );

		} );

		it( 'should throw an error when accessing list with different user ', async function () {
			// getting the list by ID
			const response = await restfulBob.get( `/lists/${listId}` );
			// Assert that the response status is 403 Forbidden or 400 bad request
			assert.oneOf( response.status, [ 400, 403 ], response.text );
		} );

		// Test case for getting a list by ID with an unknown ID (404)
		it( 'should return 404 for unknown list id', async function () {
			// Assuming an unknown ID that doesn't refer to any existing list
			const unknownIdResponse = await restfulAlice.get( '/lists/999999999' );
			// Assert that the response status is 400 Bad Request or 404 Not Found
			assert.oneOf( unknownIdResponse.status, [ 400, 404 ], unknownIdResponse.text );
		} );

		it( 'should return an error for a non-numeric id', async function () {
			// Request with a non-numeric ID
			const invalidIdResponse = await restfulAlice.get( '/lists/invalid_id' );
			// Assert that the response status is 400 Bad Request
			assert.strictEqual( invalidIdResponse.status, 400, invalidIdResponse.text );
		} );

		it( 'should delete list by id', async function () {
			// Delete the list by ID
			const deleteUrl = '/lists/' + listId;
			const deleteResponse = await restfulAlice.del( deleteUrl ).send( { token } );
			assert.deepEqual( deleteResponse.status, 200, deleteResponse.text );

			// Fetch the lists after deletion
			const getListsResponse = await restfulAlice.get( '/lists' );
			assert.deepEqual( getListsResponse.status, 200, getListsResponse.text );

			// Check that there is only one list remaining
			assert.deepEqual( getListsResponse.body.lists.length, 1, 'Number of lists should be 1' );

			// Check that the remaining list has a different ID from listId
			const remainingList = getListsResponse.body.lists[ 0 ];
			assert.notDeepEqual( remainingList.id, listId, 'Remaining list ID should be different from listId' );
		} );

		// Test case for getting a list by an invalid ID (after deleting the list)
		it( 'should return an error when trying to get a deleted list', async function () {

			// Attempting to get a list using a deleted ID
			const invalidIdResponse = await restfulAlice.get( `/lists/${listId}` );

			// Assert that the response status is 404
			assert.oneOf( invalidIdResponse.status, [ 400, 404 ], invalidIdResponse.text );
		} );

		// Helper function to create lists in batch
		async function createListsInBatch( batch ) {
			const reqBody = { batch };
			return await restfulAlice.post( '/lists/batch' )
				.set( 'Content-Type', 'application/json' )
				.send( reqBody )
				.send( { token } );
		}

		// Test case for creating lists in batch
		it( 'should create lists in batch', async function () {
			const batch = [
				{ name: 'List1', description: 'Description1' },
				{ name: 'List2', description: 'Description2' }
			];

			const createResponse = await createListsInBatch( batch );
			assert.strictEqual( createResponse.status, 200, createResponse.text );

			// Fetch the lists after creation
			const response = await restfulAlice.get( '/lists' );
			assert.strictEqual( response.status, 200, response.text );
			assert.isArray( response.body.lists );
			assert.strictEqual( response.body.lists.length, 3, 'Number of lists should be equal to the batch size + default' );

			// Check that the created lists match the expected properties
			assert.deepInclude( response.body.lists[ 0 ], { name: 'List1', description: 'Description1', default: false } );
			assert.deepInclude( response.body.lists[ 1 ], { name: 'List2', description: 'Description2', default: false } );

		} );

		// Test case for missing required parameters (400)
		it( 'should return 400 Bad Request for missing required parameters', async function () {
			const invalidBatch = [
				{ name: 'List1', invalid_description: 'Description1' }, // Missing  'description'
				{ invalid_name: 'List1', description: 'Description2' }, // Missing 'name' '
				{ name: 'List3', description: 'Description3' }
			];

			const createResponse = await createListsInBatch( invalidBatch );
			assert.deepEqual( createResponse.status, 400, createResponse.text ); // the endpoint returns a 403 here
		} );

		// Test case for pagination
		it( 'pagination', async function () {
			// First request with limit 2
			const response1 = await restfulAlice.get( '/lists' ).query( { limit: 2 } );
			assert.strictEqual( response1.status, 200 );
			assert.isArray( response1.body.lists );
			assert.lengthOf( response1.body.lists, 2 );
			assert.property( response1.body, 'continue-from' );
			assert.property( response1.body, 'next' );

			// Second request with limit 1 and next token from the first response
			const response2 = await restfulAlice.get( '/lists' ).query( { limit: 1, next: response1.body.next } );
			assert.strictEqual( response2.status, 200 );
			assert.isArray( response2.body.lists );
			assert.notProperty( response2.body, 'continue-from' ); // No continue-from as there are no more lists
			assert.notProperty( response2.body, 'next' ); // No next token as there are no more lists

		} );

		after( async function () {
			await restfulAlice.post( '/lists/teardown' ).send( { token } );
		} );

	} );

	describe( 'PUT  /lists/{id}', function () {
		let listId;
		let listsIdUrl;
		const reqBody = {
			name: 'newListName'
		};
		before( async function () {
			await restfulAlice.post( '/lists/setup' ).send( { token } );
		} );

		it( 'cannot not edit default list', async function () {
			const listsResponse = await restfulAlice.get( '/lists' ).send( { token } );
			listId = listsResponse.body.lists[ 0 ].id;
			listsIdUrl = '/lists/' + listId;
			const response = await restfulAlice.put( listsIdUrl, reqBody ).send( { token } );
			assert.deepEqual( response.status, 400, response.text );
			assert.deepEqual( response.body.errorKey, 'readinglists-db-error-cannot-update-default-list', response.text );
		} );

		it( 'should create a new list', async function () {
			const reqNewList = {
				name: 'oldListName',
				description: 'newDescription'
			};
			const response = await restfulAlice.post( '/lists', reqNewList ).send( { token } );
			assert.deepEqual( response.status, 200, response.text );
		} );

		it( 'should edit list by id', async function () {
			const listsResponse = await restfulAlice.get( '/lists' ).send( { token } );
			listId = listsResponse.body.lists[ 1 ].id;
			listsIdUrl = '/lists/' + listId;
			const response = await restfulAlice.put( listsIdUrl, reqBody ).send( { token } );
			const oldListName = listsResponse.body.lists[ 1 ].name;
			const newListName = response.body.list.name;
			assert.notEqual( oldListName, newListName );
			assert.deepEqual( response.status, 200, response.text );
		} );

		after( async function () {
			await restfulAlice.post( '/lists/teardown' ).send( { token } );
		} );
	} );

} );
