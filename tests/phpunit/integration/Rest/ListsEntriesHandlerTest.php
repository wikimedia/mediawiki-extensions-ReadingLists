<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Integration\Rest;

use MediaWiki\Extension\ReadingLists\Rest\ListsEntriesHandler;
use MediaWiki\Extension\ReadingLists\Tests\RestTestHelperTrait;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;

/**
 * @covers \MediaWiki\Extension\ReadingLists\Rest\ListsEntriesHandler
 * @group Database
 */
class ListsEntriesHandlerTest extends \MediaWikiIntegrationTestCase {
	use RestTestHelperTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->readingListsSetup();
		$this->addProjects( [ 'foo' ] );
	}

	/**
	 * @dataProvider listsSuccessProvider
	 */
	public function testListsEntries( $newEntries, $queryParams, $expected ) {
		$services = $this->getServiceContainer();
		$handler = new ListsEntriesHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup(),
			$this->getMockReverseInterwikiLookup( '' )
		);

		$repository = $this->getReadingListRepository( $handler );
		$list = $repository->addList( 'dogs', 'Woof!' );
		$newEntryIds = [];
		foreach ( $newEntries as $newEntry ) {
			$entry = $repository->addListEntry( $list->rl_id, $newEntry['project'], $newEntry['title'] );
			$newEntryIds[$newEntry['title']] = (int)$entry->rle_id;
		}

		$request = new RequestData( [
			'pathParams' => [ 'id' => $list->rl_id ],
			'queryParams' => $queryParams
		] );
		$data = $this->executeReadingListsHandlerAndGetBodyData( $handler, $request );

		$this->assertArrayHasKey( 'entries', $data );
		$this->assertIsArray( $data['entries'] );
		$this->assertSameSize( $expected, $data['entries'] );

		// Pagination parameter "next" should only appear if pagination is possible
		if ( count( $expected ) < count( $newEntries ) ) {
			$this->assertArrayHasKey( 'next', $data );
		} else {
			$this->assertArrayNotHasKey( 'next', $data );
		}

		for ( $i = 0; $i < count( $expected ); $i++ ) {
			$this->assertIsArray( $data['entries'][$i] );
			$this->checkReadingListEntry(
				$data['entries'][$i],
				$newEntryIds[$expected[$i]['title']],
				$expected[$i]['project'],
				$expected[$i]['title']
			);
		}
	}

	public static function listsSuccessProvider() {
		return [
			[
				[ [ 'project' => 'foo', 'title' => 'Lassie' ] ],
				[],
				[ [ 'project' => 'foo', 'title' => 'Lassie' ] ],
			],
			[
				[
					[ 'project' => 'foo', 'title' => 'Lassie' ],
					[ 'project' => 'foo', 'title' => 'Scooby-Doo' ]
				],
				[ 'limit' => 1 ],
				[ [ 'project' => 'foo', 'title' => 'Lassie' ] ],
			],
			[
				[
					[ 'project' => 'foo', 'title' => 'Scooby-Doo' ],
					[ 'project' => 'foo', 'title' => 'Lassie' ],
				],
				[ 'limit' => 1, 'sort' => 'name' ],
				[ [ 'project' => 'foo', 'title' => 'Lassie' ] ],
			],
			[
				[
					[ 'project' => 'foo', 'title' => 'Scooby-Doo' ],
					[ 'project' => 'foo', 'title' => 'Lassie' ],
				],
				[ 'limit' => 1, 'sort' => 'name', 'dir' => 'ascending' ],
				[ [ 'project' => 'foo', 'title' => 'Lassie' ] ],
			],

			[
				[
					[ 'project' => 'foo', 'title' => 'Scooby-Doo' ],
					[ 'project' => 'foo', 'title' => 'Lassie' ],
				],
				[ 'limit' => 1, 'sort' => 'name', 'dir' => 'descending' ],
				[ [ 'project' => 'foo', 'title' => 'Scooby-Doo' ] ],
			],

			[
				[
					[ 'project' => 'foo', 'title' => 'Lassie' ],
					[ 'project' => 'foo', 'title' => 'Scooby-Doo' ]
				],
				[ 'limit' => 1, 'sort' => 'updated' ],
				[ [ 'project' => 'foo', 'title' => 'Lassie' ] ],
			],
			[
				[
					[ 'project' => 'foo', 'title' => 'Lassie' ],
					[ 'project' => 'foo', 'title' => 'Scooby-Doo' ]
				],
				[ 'limit' => 1, 'sort' => 'updated', 'dir' => 'ascending' ],
				[ [ 'project' => 'foo', 'title' => 'Lassie' ] ],
			],
			[
				[
					[ 'project' => 'foo', 'title' => 'Lassie' ],
					[ 'project' => 'foo', 'title' => 'Scooby-Doo' ]
				],
				[ 'limit' => 1, 'sort' => 'updated', 'dir' => 'descending' ],
				[ [ 'project' => 'foo', 'title' => 'Scooby-Doo' ] ],
			],
		];
	}

	public function testListsPagination() {
		$services = $this->getServiceContainer();

		$handler = new ListsEntriesHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup(),
			$this->getMockReverseInterwikiLookup( '' )
		);
		$repository = $this->getReadingListRepository( $handler );
		$list = $repository->addList( 'dogs', 'Woof!' );
		$lassie = $repository->addListEntry( $list->rl_id, 'foo', 'Lassie' );
		$scooby = $repository->addListEntry( $list->rl_id, 'foo', 'Scooby-Doo' );

		$request = new RequestData( [
			'pathParams' => [ 'id' => $list->rl_id ],
			'queryParams' => [ 'limit' => 1 ]
		] );
		$data = $this->executeReadingListsHandlerAndGetBodyData( $handler, $request );
		$this->assertArrayHasKey( 'entries', $data );
		$this->assertCount( 1, $data['entries'] );
		$this->assertArrayHasKey( 'next', $data );
		$this->assertIsArray( $data['entries'] );
		$this->checkReadingListEntry(
			$data['entries'][0],
			(int)$lassie->rle_id,
			'foo',
			'Lassie'
		);

		// Use a separate handler instance. This avoids double-initialization errors, and also
		// ensures there are no side effects between invocations.
		$handler = new ListsEntriesHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup(),
			$this->getMockReverseInterwikiLookup( '' )
		);
		$request = new RequestData( [
			'pathParams' => [ 'id' => $list->rl_id ],
			'queryParams' => [ 'limit' => 1, 'next' => $data['next'] ]
		] );
		$data = $this->executeReadingListsHandlerAndGetBodyData( $handler, $request );
		$this->assertArrayHasKey( 'entries', $data );
		$this->assertCount( 1, $data['entries'] );
		$this->assertArrayNotHasKey( 'next', $data );
		$this->assertIsArray( $data['entries'] );
		$this->checkReadingListEntry(
			$data['entries'][0],
			(int)$scooby->rle_id,
			'foo',
			'Scooby-Doo'
		);
	}

	/**
	 * @dataProvider listsFailureProvider
	 */
	public function testListsFailure( $queryParams ) {
		$services = $this->getServiceContainer();
		$handler = new ListsEntriesHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup(),
			$this->getMockReverseInterwikiLookup( '' )
		);

		$repository = $this->getReadingListRepository( $handler );
		$list = $repository->addList( 'dogs', 'Woof!' );
		$repository->addListEntry( $list->rl_id, 'foo', 'Lassie' );

		$this->expectException( LocalizedHttpException::class );

		$request = new RequestData( [
			'pathParams' => [ 'id' => $list->rl_id ],
			'queryParams' => $queryParams
		] );
		$this->executeReadingListsHandler( $handler, $request );
	}

	public static function listsFailureProvider() {
		return [
			'invalid sort param' => [ [ 'sort' => 'foo' ] ],
			'invalid dir param' => [ [ 'dir' => 'foo' ] ],
			'invalid next param' => [ [ 'next' => 'foo' ] ],
			'invalid limit param' => [ [ 'dir' => 'foo' ] ],
		];
	}

	public function testListsEntriesAccess() {
		$services = $this->getServiceContainer();

		// Add data, so that we can (try to) delete it.  We must use a privileged handler here.
		$handler = new ListsEntriesHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup(),
			$this->getMockReverseInterwikiLookup( '' )
		);
		$repository = $this->getReadingListRepository( $handler );
		$listId = $repository->addList( 'dogs', 'Woof!' )->rl_id;
		$entry = $repository->addListEntry( $listId, 'foo', 'Lassie' );
		$entryId = (int)$entry->rle_id;

		$request = new RequestData( [
			'pathParams' => [ 'id' => $listId ],
		] );

		$handler = new ListsEntriesHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup( false ),
			$this->getMockReverseInterwikiLookup( '' )
		);

		$this->expectException( LocalizedHttpException::class );
		$this->expectExceptionMessage( 'rest-permission-denied-anon' );

		$this->executeReadingListsHandler( $handler, $request, false );
	}

	protected function tearDown(): void {
		$this->readingListsTeardown();
		parent::tearDown();
	}
}
