'use strict';
const { REST, assert, action, utils } = require( 'api-testing' );

describe( 'ReadingLists Entries', function () {
	let alice;
	let restfulAlice;
	let token;
	const localProject = '@local';

	before( async function () {
		alice = await action.alice();
		restfulAlice = new REST( 'rest.php/readinglists/v0', alice );
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

			// TODO: check expected list entries
		} );

		it( 'should not create a new list entry without title parameter', async function () {
			const reqNewListEntry = {
				project: localProject
			};
			const response = await restfulAlice.post( entriesUrl )
				.send( { ...reqNewListEntry, token } );
			assert.deepEqual( response.status, 400, response.text );
			assert.deepEqual( response.body.failureCode, 'missingparam', response.text );
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
		} );

		// TODO: check that we can actually create a batch of entries

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

	// FIXME: it seems it does not check for invalid title?
	describe( 'GET /list/pages/project/title', function () {
		const validProject = 'foo';
		const validTitle = 'Dog';
		const invalidProject = '%Foo';
		const invalidTitle = '_Dog_';

		it.skip( 'should get lists with specified parameters', async function () {
			// /lists/pages/{project}/{title}'
			const response = await restfulAlice.get( `/lists/pages/${ validProject }/${ validTitle }` );
			assert.deepEqual( response.status, 200, response.text );
			// TODO: We should check that we actually get lists,
			//  that the lists we get are the correct ones.
		} );

		it.skip( 'should handle a case with invalid title', async function () {
			// Assumes handler returns an error response for invalid parameters
			const response = await restfulAlice.get( `/lists/pages/${ validProject }/${ invalidTitle }` );
			assert.deepEqual( response.status, 400, response.text );
		} );

		it( 'should handle a case with invalid project', async function () {
			// Assumes handler returns an error response for invalid parameters
			const response = await restfulAlice.get( `/lists/pages/${ invalidProject }/${ validTitle }` );
			assert.deepEqual( response.status, 400, response.text );
		} );

		// you can have a missing project or missing title
	} );

} );
