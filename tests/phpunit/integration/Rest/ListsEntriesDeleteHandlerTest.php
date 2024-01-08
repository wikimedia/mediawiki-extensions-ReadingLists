<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Integration\Rest;

use MediaWiki\Extension\ReadingLists\Rest\ListsEntriesDeleteHandler;
use MediaWiki\Extension\ReadingLists\Tests\RestTestHelperTrait;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;

/**
 * @covers \MediaWiki\Extension\ReadingLists\Rest\ListsEntriesDeleteHandler
 * @group Database
 */
class ListsEntriesDeleteHandlerTest extends \MediaWikiIntegrationTestCase {
	use RestTestHelperTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->readingListsSetup();
		$this->addProjects( [ 'foo' ] );
	}

	public function testListsDeleteSuccess() {
		$services = $this->getServiceContainer();
		$handler = new ListsEntriesDeleteHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup() );

		$repository = $this->getReadingListRepository( $handler );
		$list = $repository->addList( 'dogs', 'Woof!' );
		$entry = $repository->addListEntry( $list->rl_id, 'foo', 'Lassie' );

		$request = new RequestData( [
			'method' => 'DELETE',
			'pathParams' => [ 'id' => (int)$list->rl_id, 'entry_id' => (int)$entry->rle_id ],
		] );
		$data = $this->executeReadingListsHandler( $handler, $request );
		$this->assertSame( 200, $data->getStatusCode() );
		$this->assertSame( '{}', $data->getBody()->getContents() );
	}

	/**
	 * @dataProvider deleteFailureProvider
	 */
	public function testListsEntriesDeleteFailure( $id, $entryId ) {
		$services = $this->getServiceContainer();
		$handler = new ListsEntriesDeleteHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup() );

		$repository = $this->getReadingListRepository( $handler );
		$list = $repository->addList( 'dogs', 'Woof!' );
		$entry = $repository->addListEntry( $list->rl_id, 'foo', 'Lassie' );

		// If either $id or $entryId is a positive integer, they will be replaced by the
		// actual id from the test data.
		if ( is_int( $id ) && $id > 0 ) {
			$id = (int)$list->rl_id;
		}
		if ( is_int( $entryId ) && $entryId > 0 ) {
			$entryId = (int)$entry->rle_id;
		}

		$this->expectException( LocalizedHttpException::class );

		$request = new RequestData( [
			'method' => 'DELETE',
			'pathParams' => [ 'id' => $id, 'entry_id' => $entryId ],
		] );
		$this->executeReadingListsHandler( $handler, $request );
	}

	public static function deleteFailureProvider() {
		return [
			'no params' => [ null, null ],
			'missing id' => [ null, 1 ],
			'invalid id value' => [ 0, 1 ],
			'invalid id format' => [ 'blah', 1 ],
			'missing entry id' => [ 1, null ],
			'invalid entry id value' => [ 1, 0 ],
			'invalid entry id format' => [ 1, 'blah' ],
		];
	}

	public function testListsDeleteAccess() {
		$services = $this->getServiceContainer();

		// Add data, so that we can (try to) delete it.  We must use a privileged handler here.
		$handler = new ListsEntriesDeleteHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup() );
		$repository = $this->getReadingListRepository( $handler );
		$list = $repository->addList( 'dogs', 'Woof!' );
		$entry = $repository->addListEntry( $list->rl_id, 'foo', 'Lassie' );

		$handler = new ListsEntriesDeleteHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup( false ) );

		$this->expectException( LocalizedHttpException::class );
		$this->expectExceptionMessage( 'rest-permission-denied-anon' );

		$request = new RequestData( [
			'method' => 'DELETE',
			'pathParams' => [ 'id' => (int)$list->rl_id, 'entry_id' => (int)$entry->rle_id ],
		] );
		$this->executeReadingListsHandler( $handler, $request, false );
	}

	protected function tearDown(): void {
		parent::tearDown();
		$this->readingListsTeardown();
	}
}
