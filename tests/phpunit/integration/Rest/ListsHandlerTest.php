<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Integration\Rest;

use MediaWiki\Extension\ReadingLists\Rest\ListsHandler;
use MediaWiki\Extension\ReadingLists\Tests\RestTestHelperTrait;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;

/**
 * @covers \MediaWiki\Extension\ReadingLists\Rest\ListsHandler
 * @group Database
 */
class ListsHandlerTest extends \MediaWikiIntegrationTestCase {
	use RestTestHelperTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->readingListsSetup();
	}

	/**
	 * @dataProvider listsSuccessProvider
	 */
	public function testListsSuccess( $newLists, $queryParams, $expected ) {
		$services = $this->getServiceContainer();
		$handler = new ListsHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup() );
		$repository = $this->getReadingListRepository( $handler );

		// Set up data to query as needed. Id of zero is to skip id assert for the default list.
		$newListsIds = [ 'default' => 0 ];
		foreach ( $newLists as $newList ) {
			$list = $repository->addList( $newList['name'], $newList['description'] );
			$newListsIds[$list->rl_name] = (int)$list->rl_id;
		}

		$request = new RequestData( [ 'queryParams' => $queryParams ] );
		$data = $this->executeReadingListsHandlerAndGetBodyData( $handler, $request );
		$this->assertArrayHasKey( 'lists', $data );
		$this->assertIsArray( $data['lists'] );
		$this->assertSameSize( $expected, $data['lists'] );

		// Pagination parameter "next" should only appear if pagination is possible
		if ( count( $expected ) < count( $newLists ) + 1 ) {
			$this->assertArrayHasKey( 'next', $data );
		} else {
			$this->assertArrayNotHasKey( 'next', $data );
		}

		// Sync timestamp should only appear if this is the first or only page of results.
		// These tests all match that criteria, so continue-from should always be present.
		$this->assertArrayHasKey( 'continue-from', $data );

		for ( $i = 0; $i < count( $expected ); $i++ ) {
			$this->assertIsArray( $data['lists'][$i] );
			$this->checkReadingList(
				$data['lists'][$i],
				$newListsIds[$expected[$i]['name']],
				$expected[$i]['name'],
				$expected[$i]['description'],
				$expected[$i]['isDefault']
			);
		}
	}

	public static function listsSuccessProvider() {
		return [
			[
				[ [ 'name' => 'dogs', 'description' => 'Woof!' ] ],
				[],
				[
					[ 'name' => 'default', 'description' => '', 'isDefault' => true ],
					[ 'name' => 'dogs', 'description' => 'Woof!', 'isDefault' => false ]
				],
			],
			[
				[ [ 'name' => 'dogs', 'description' => 'Woof!' ] ],
				[ 'limit' => 1 ],
				[
					[ 'name' => 'default', 'description' => '', 'isDefault' => true ],
				],
			],
			[
				[ [ 'name' => 'dogs', 'description' => 'Woof!' ] ],
				[ 'limit' => 1, 'sort' => 'name' ],
				[
					[ 'name' => 'default', 'description' => '', 'isDefault' => true ],
				],
			],
			[
				[ [ 'name' => 'dogs', 'description' => 'Woof!' ] ],
				[ 'limit' => 1, 'sort' => 'name', 'dir' => 'ascending' ],
				[
					[ 'name' => 'default', 'description' => '', 'isDefault' => true ],
				],
			],
			[
				[ [ 'name' => 'dogs', 'description' => 'Woof!' ] ],
				[ 'limit' => 1, 'sort' => 'name', 'dir' => 'descending' ],
				[
					[ 'name' => 'dogs', 'description' => 'Woof!', 'isDefault' => false ]
				],
			],
			[
				[ [ 'name' => 'dogs', 'description' => 'Woof!' ] ],
				[ 'limit' => 1, 'sort' => 'updated' ],
				[
					[ 'name' => 'default', 'description' => '', 'isDefault' => true ],
				],
			],
			[
				[ [ 'name' => 'dogs', 'description' => 'Woof!' ] ],
				[ 'limit' => 1, 'sort' => 'updated', 'dir' => 'ascending' ],
				[
					[ 'name' => 'default', 'description' => '', 'isDefault' => true ],
				],
			],
			[
				[ [ 'name' => 'dogs', 'description' => 'Woof!' ] ],
				[ 'limit' => 1, 'sort' => 'updated', 'dir' => 'descending' ],
				[
					[ 'name' => 'dogs', 'description' => 'Woof!', 'isDefault' => false ]
				],
			],
		];
	}

	public function testListsPagination() {
		$services = $this->getServiceContainer();

		$handler = new ListsHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup() );
		$repository = $this->getReadingListRepository( $handler );
		$list = $repository->addList( 'dogs', 'Woof!' );
		$newListId = (int)$list->rl_id;

		$request = new RequestData( [ 'queryParams' => [ 'limit' => 1 ] ] );
		$data = $this->executeReadingListsHandlerAndGetBodyData( $handler, $request );
		$this->assertArrayHasKey( 'continue-from', $data );
		$this->assertArrayHasKey( 'lists', $data );
		$this->assertCount( 1, $data['lists'] );
		$this->assertArrayHasKey( 'next', $data );
		$this->assertIsArray( $data['lists'] );
		$this->checkReadingList(
			$data['lists'][0],
			0,
			'default',
			'',
			true
		);

		// Use a separate handler instance. This avoids double-initialization errors, and also
		// ensures there are no side effects between invocations.
		$handler = new ListsHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup() );
		$request = new RequestData( [ 'queryParams' => [ 'limit' => 1, 'next' => $data['next'] ] ] );
		$data = $this->executeReadingListsHandlerAndGetBodyData( $handler, $request );
		$this->assertArrayNotHasKey( 'continue-from', $data );
		$this->assertArrayHasKey( 'lists', $data );
		$this->assertCount( 1, $data['lists'] );
		$this->assertArrayNotHasKey( 'next', $data );
		$this->assertIsArray( $data['lists'] );
		$this->checkReadingList(
			$data['lists'][0],
			$newListId,
			'dogs',
			'Woof!',
			false
		);
	}

	/**
	 * @dataProvider listsFailureProvider
	 */
	public function testListsFailure( $queryParams ) {
		$services = $this->getServiceContainer();
		$handler = new ListsHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup() );

		$this->expectException( LocalizedHttpException::class );

		$request = new RequestData( [ 'queryParams' => $queryParams ] );
		$this->executeReadingListsHandler( $handler, $request );
	}

	public static function listsFailureProvider() {
		return [
			'invalid sort param' => [ [ 'sort' => 'foo' ] ],
			'invalid dir param' => [ [ 'dir' => 'foo' ] ],
			// ListsHandler requires "next" param ton contain separators
			'invalid next param' => [ [ 'next' => 'foo' ] ],
			'invalid limit param' => [ [ 'dir' => 'foo' ] ],
		];
	}

	public function testListsAccess() {
		$services = $this->getServiceContainer();
		$handler = new ListsHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup( false ) );

		$this->expectException( LocalizedHttpException::class );
		$this->expectExceptionMessage( 'rest-permission-denied-anon' );

		$request = new RequestData();
		$this->executeReadingListsHandler( $handler, $request, false );
	}

	protected function tearDown(): void {
		parent::tearDown();
		$this->readingListsTeardown();
	}
}
