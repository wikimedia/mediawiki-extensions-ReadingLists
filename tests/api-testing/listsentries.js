'use strict';
const { REST, assert, action } = require( 'api-testing' );

describe( 'ReadingLists', function () {
	let alice;
	let restfulAlice;
	let token;
	before( async function () {
		alice = await action.alice();
		restfulAlice = new REST( 'rest.php/readinglists/v0', alice );
		token = await alice.token();
	} );

	describe( 'PUT and DELETE /lists/{id}', function () {
		let listId;
		let listsIdUrl;
		const reqBody = {
			name: 'newListName'
		};
		before( async function () {
			await restfulAlice.post( '/setup' ).send( { token } );
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

		it( 'should delete list by id', async function () {
			const listsResponse = await restfulAlice.get( '/lists' ).send( { token } );
			assert.deepEqual( listsResponse.body.lists.length, 2, listsResponse.text );

			listId = listsResponse.body.lists[ 1 ].id;
			listsIdUrl = '/lists/' + listId;
			const response = await restfulAlice.del( listsIdUrl ).send( { token } );
			assert.deepEqual( response.status, 200, response.text );

			const newlistsResponse = await restfulAlice.get( '/lists' ).send( { token } );
			assert.deepEqual( newlistsResponse.body.lists.length, 1, newlistsResponse.text );
			assert.deepEqual( newlistsResponse.body.lists[ 0 ].name, 'default', newlistsResponse.text );

			const editListResponse = await restfulAlice.put(
				listsIdUrl, reqBody
			).send( { token } );
			assert.deepEqual(
				editListResponse.body.errorKey,
				'readinglists-db-error-list-deleted',
				editListResponse.text
			);

		} );

		after( async function () {
			await restfulAlice.post( '/teardown' ).send( { token } );
		} );
	} );

	describe( 'GET and POST /lists/{id}/entries', function () {
		let entriesUrl;
		before( async function () {
			await restfulAlice.post( '/setup' ).send( { token } );
			const reqNewList = {
				name: 'newName',
				description: 'newDescription'
			};
			const listsResponse = await restfulAlice.post( '/lists', reqNewList ).send( { token } );
			const listId = listsResponse.body.id;
			entriesUrl = '/lists/' + listId + '/entries';
		} );

		it( 'should get list entries', async function () {
			const response = await restfulAlice.get( entriesUrl );
			assert.deepEqual( response.status, 200, response.text );
		} );

		it( 'should not create a new list entry without title parameter', async function () {
			const reqNewListEntry = {
				project: 'invalidProject'
			};
			const response = await restfulAlice.post(
				entriesUrl, reqNewListEntry
			).send( { token } );
			assert.deepEqual( response.status, 400, response.text );
			assert.deepEqual( response.body.errorKey, 'rest-missing-body-field', response.text );
		} );

		it( 'should not create a new list entry without valid project', async function () {
			const reqNewListEntry = {
				project: 'invalidProject',
				title: 'newTitle'
			};
			const response = await restfulAlice.post(
				entriesUrl, reqNewListEntry
			).send( { token } );
			assert.deepEqual( response.status, 400, response.text );
			assert.deepEqual( response.body.errorKey, 'readinglists-db-error-no-such-project', response.text );
		} );

		it( 'should not create a new list entry without valid batch', async function () {
			const reqNewListEntry = {
				batch: 'invalidBatch'
			};
			const response = await restfulAlice.post(
				entriesUrl, reqNewListEntry
			).send( { token } );
			assert.deepEqual( response.status, 400, response.text );
			assert.deepEqual(
				response.body.errorKey,
				'rest-missing-body-field',
				response.text
			);
		} );

		after( async function () {
			await restfulAlice.post( '/teardown' ).send( { token } );
		} );
	} );
} );
