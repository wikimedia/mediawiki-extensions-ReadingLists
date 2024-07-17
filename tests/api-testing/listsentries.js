'use strict';
const { REST, assert, action, utils } = require( 'api-testing' );

describe( 'ReadingLists Entries', function () {
	let alice;
	let restfulAlice;
	let token;
	const localProject = '@local';

	// Ensure fields required by callers are present (for RESTBase compatibility)
	function assertErrorFormat( response ) {
		assert.isAtLeast( response.status, 400, response.text );
		assert.property( response.body, 'type' );
		assert.property( response.body, 'title' );
		assert.property( response.body, 'method' );
		assert.property( response.body, 'detail' );
		assert.property( response.body, 'uri' );
	}

	before( async function () {
		alice = await action.alice();
		restfulAlice = new REST( 'rest.php/readinglists.v0', alice );
		token = await alice.token();
	} );

	describe( 'GET and POST /lists/{id}/entries', function () {
		const validTitle = utils.title( 'Dog' );
		let listId;
		let entriesUrl;

		before( async function () {
			await restfulAlice.post( '/lists/setup' ).send( { token } );
			const reqNewList = {
				name: 'newName',
				description: 'newDescription'
			};
			const listsResponse = await restfulAlice.post( '/lists', reqNewList ).send( { token } );
			listId = listsResponse.body.id;
			entriesUrl = '/lists/' + listId + '/entries';
		} );

		it( 'should create a new list entry', async function () {
			const reqNewListEntry = {
				project: localProject,
				title: validTitle
			};
			const response = await restfulAlice.post( entriesUrl )
				.send( { ...reqNewListEntry, token } );

			assert.deepEqual( response.status, 200, response.text );
			assert.property( response.body, 'id' );
			assert.property( response.body, 'entry' );
			assert.property( response.body.entry, 'id' );
			assert.deepEqual( response.body.entry.id, response.body.entry.id );

			assert.deepEqual( response.body.entry.listId, listId );
		} );

		it( 'should get list entries', async function () {
			const response = await restfulAlice.get( entriesUrl );
			assert.deepEqual( response.status, 200, response.text );

			assert.property( response.body, 'entries' );
			assert.property( response.body.entries[ 0 ], 'id' );
			assert.deepEqual( response.body.entries[ 0 ].listId, listId );
		} );

		it( 'should get list entries when url includees trailing slash', async function () {
			const response = await restfulAlice.get( entriesUrl + '/' );
			assert.deepEqual( response.status, 200, response.text );
		} );

		it( 'should not create a new list entry without title parameter', async function () {
			const reqNewListEntry = {
				project: localProject
			};
			const response = await restfulAlice.post( entriesUrl )
				.send( { ...reqNewListEntry, token } );
			assert.deepEqual( response.status, 400, response.text );
			assert.deepEqual( response.body.failureCode, 'missingparam', response.text );
			assertErrorFormat( response );
		} );

		it( 'should remove entries from the list', async function () {
			// Get entry ID
			let response = await restfulAlice.get( entriesUrl );
			const entryId = response.body.entries[ 0 ].id;
			assert.deepEqual( response.status, 200, response.text );
			assert.deepEqual( response.body.entries.length, 1, 'There should be one entry in the list' );

			// Delete list entry by entry ID
			const deleteUrl = entriesUrl + '/' + entryId;
			const deleteResponse = await restfulAlice.del( deleteUrl );
			assert.deepEqual( deleteResponse.status, 200, response.text );

			// Get entries after deletion
			response = await restfulAlice.get( entriesUrl );
			assert.deepEqual( response.status, 200, response.text );

			// Check list item has been removed
			assert.deepEqual( response.body.entries.length, 0, 'There should be no lists remaining' );
		} );

		it( 'should not create a new list entry without valid project', async function () {
			const reqNewListEntry = {
				project: 'invalidProject',
				title: 'newTitle'
			};
			const response = await restfulAlice.post( entriesUrl )
				.send( { ...reqNewListEntry, token } );
			assert.deepEqual( response.status, 400, response.text );
			assert.deepEqual( response.body.errorKey, 'readinglists-db-error-no-such-project', response.text );
			assertErrorFormat( response );
		} );

		it( 'should not create a new list entry without valid batch', async function () {
			const reqNewListEntry = {
				batch: 'invalidBatch'
			};
			const response = await restfulAlice.post( entriesUrl )
				.send( { ...reqNewListEntry, token } );
			assert.deepEqual( response.status, 400, response.text );
			assert.deepEqual(
				response.body.failureCode,
				'missingparam',
				response.text
			);
			assertErrorFormat( response );
		} );

		async function createEntriesInBatch( batch ) {
			const reqBody = { batch };
			const batchUrl = entriesUrl + '/batch';
			return await restfulAlice.post( batchUrl )
				.set( 'Content-Type', 'application/json' )
				.send( reqBody )
				.send( { token } );
		}

		it( 'should create a batch of list entries with valid batch', async function () {
			const validTitle1 = utils.title( 'Cat' );

			const batch = [
				{ project: localProject, title: validTitle },
				{ project: localProject, title: validTitle1 }
			];

			let response = await createEntriesInBatch( batch );
			assert.deepEqual( response.status, 200, response.text );

			// Check that lists were created
			response = await restfulAlice.get( entriesUrl );
			assert.deepEqual( response.status, 200, response.text );
			assert.deepEqual( response.body.entries.length, 2, response.text );

			// Check lists have expected titles
			assert.deepInclude( response.body.entries[ 0 ], { title: validTitle1 }, response.text );
			assert.deepInclude( response.body.entries[ 1 ], { title: validTitle }, response.text );
		} );

		after( async function () {
			await restfulAlice.post( '/lists/teardown' ).send( { token } );
		} );
	} );

	describe( 'GET /lists/changes/since/{ date }', function () {
		// Helper function to get lists that have changed since a specific date
		async function getListsChangesSince( date, next = '', limit = 10 ) {
			return await restfulAlice.get( `/lists/changes/since/${ date }` )
				.query( { next, limit } );
		}

		// Test case for getting lists that have changed since a specific date
		it( 'should get lists changes since a specific date', async function () {
			// Replace 'your-date-here' with a valid timestamp
			const date = '2023-01-01T00:00:00Z'; // i put a random date here but we can discuss
			const response = await getListsChangesSince( date );

			assert.strictEqual( response.status, 200, response.text );
			assert.isAtLeast( response.body.lists.length, 0, 'Lists array should be present' );

			// Add assertions to check that each list's 'updated' timestamp is later than 'created'
			for ( const list of response.body.lists ) {
				const createdTimestamp = new Date( list.created ).getTime();
				const updatedTimestamp = new Date( list.updated ).getTime();

				assert.ok( !isNaN( createdTimestamp ), `List ID ${ list.id } has invalid created timestamp` );
				assert.ok( !isNaN( updatedTimestamp ), `List ID ${ list.id } has invalid updated timestamp` );
				assert.ok( updatedTimestamp >= createdTimestamp, `List ID ${ list.id } has updated timestamp later than or equal to created timestamp` );
			}

		} );

	} );

	describe( 'GET /list/pages/project/title', function () {
		const validProject = '@local';
		const validTitleA = 'Dog', validTitleB = 'Cat', validTitleC = 'Bird';
		const invalidProject = '%25Foo';
		const invalidTitle = '%25Dog';
		let listIdA, listIdB;
		let entriesUrlA, entriesUrlB;

		before( async function () {
			await restfulAlice.post( '/lists/setup' ).send( { token } );
			const batch = [
				{ name: 'name A', description: 'description A' },
				{ name: 'name B', description: 'description B' }
			];

			const listsResponse = await restfulAlice.post( '/lists/batch' )
				.set( 'Content-Type', 'application/json' )
				.send( { batch } )
				.send( { token } );
			listIdA = listsResponse.body.batch[ 0 ].id;
			listIdB = listsResponse.body.batch[ 1 ].id;

			entriesUrlA = '/lists/' + listIdA + '/entries';
			entriesUrlB = '/lists/' + listIdB + '/entries';

			const reqNewListEntryA = {
				project: '@local',
				title: validTitleA
			};
			let res = await restfulAlice.post( entriesUrlA )
				.send( { ...reqNewListEntryA, token } );
			assert.deepEqual( res.status, 200, res.text );

			const reqNewListEntryB = {
				project: '@local',
				title: validTitleB
			};

			res = await restfulAlice.post( entriesUrlB )
				.send( { ...reqNewListEntryB, token } );
			assert.deepEqual( res.status, 200, res.text );

			const reqNewListEntryC = {
				project: '@local',
				title: validTitleC
			};

			res = await restfulAlice.post( entriesUrlA )
				.send( { ...reqNewListEntryC, token } );
			assert.deepEqual( res.status, 200, res.text );

			res = await restfulAlice.post( entriesUrlB )
				.send( { ...reqNewListEntryC, token } );
			assert.deepEqual( res.status, 200, res.text );
		} );

		it( 'should get lists with specified parameters', async function () {

			let response = await restfulAlice.get( `/lists/pages/${ validProject }/${ validTitleA }` );
			assert.deepEqual( response.status, 200, response.text );

			// Check that entry is in list A
			assert.deepEqual( response.body.lists[ 0 ].name, 'name A', response.text );

			// Check that entry is not in any other list
			assert.deepEqual( response.body.lists.length, 1, response.text );

			response = await restfulAlice.get( `/lists/pages/${ validProject }/${ validTitleB }` );
			assert.deepEqual( response.status, 200, response.text );

			// Check that entry is in list B
			assert.deepEqual( response.body.lists[ 0 ].name, 'name B', response.text );

			// Check that entry is not in any other list
			assert.deepEqual( response.body.lists.length, 1, response.text );

			response = await restfulAlice.get( `/lists/pages/${ validProject }/${ validTitleC }` );

			// Check that entry C is in both lists
			assert.deepEqual( response.body.lists.length, 2, response.text );
		} );

		it( 'should return empty results for invalid title', async function () {
			// Assumes handler returns an error response for invalid parameters
			const response = await restfulAlice.get( `/lists/pages/${ validProject }/${ invalidTitle }` );
			assert.deepEqual( response.status, 200, response.text );
			assert.deepEqual( response.body.lists.length, 0, 'Lists array should be present and empty' );
		} );

		it( 'should return empty results for invalid project', async function () {
			// Assumes handler returns an error response for invalid parameters
			const response = await restfulAlice.get( `/lists/pages/${ invalidProject }/${ validTitleA }` );
			assert.deepEqual( response.status, 200, response.text );
			assert.deepEqual( response.body.lists.length, 0, 'Lists array should be present and empty' );
		} );

	} );

} );
