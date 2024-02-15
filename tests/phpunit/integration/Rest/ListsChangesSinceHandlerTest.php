<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Integration\Rest;

use MediaWiki\Extension\ReadingLists\Rest\ListsChangesSinceHandler;
use MediaWiki\Extension\ReadingLists\Tests\RestTestHelperTrait;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;

/**
 * @covers \MediaWiki\Extension\ReadingLists\Rest\ListsChangesSinceHandler
 * @group Database
 */
class ListsChangesSinceHandlerTest extends \MediaWikiIntegrationTestCase {
	use RestTestHelperTrait;

	private array $listIds;

	protected function setUp(): void {
		parent::setUp();

		// Set up data to query.
		$lastUpdateYesterday = wfTimestamp( TS_MW, strtotime( "-1 days" ) );
		$lastUpdateToday = wfTimestamp( TS_MW );
		$this->addProjects( [ 'foo' ] );
		$newLists = [
			[
				'rl_is_default' => 1,
				'rl_name' => 'default',
				'rl_description' => 'default list',
				'rl_date_created' => '20170913000000',
				'rl_date_updated' => '20170913000000',
				'rl_deleted' => 0,
			],
			[
				'rl_is_default' => 0,
				'rl_name' => 'dogs',
				'rl_description' => 'Woof!',
				'rl_date_created' => '20170913000000',
				'rl_date_updated' => $lastUpdateToday,
				'rl_deleted' => 0,
				'entries' => [
					[
						'rlp_project' => 'foo',
						'rle_title' => 'Dog',
						'rle_date_created' => '20100101000000',
						'rle_date_updated' => '20150101000000',
						'rle_deleted' => 0,
					],
				],
			],
			[
				'rl_is_default' => 0,
				'rl_name' => 'cats',
				'rl_description' => "Meow!",
				'rl_date_created' => '20180914000000',
				'rl_date_updated' => $lastUpdateYesterday,
				'rl_deleted' => 0,
			],
			[
				'rl_is_default' => 0,
				'rl_name' => 'pets',
				'rl_description' => '',
				'rl_date_created' => '20170913000000',
				'rl_date_updated' => '20170913000000',
				'rl_deleted' => 0,
				'entries' => [
					[
						'rlp_project' => 'foo',
						'rle_title' => 'Dog',
						'rle_date_created' => '20100101000000',
						'rle_date_updated' => '20150101000000',
						'rle_deleted' => 0,
					],
				],
			],
		];

		$this->listIds = $this->addLists( $this->getAuthority()->getUser()->getId(), $newLists );
	}

	/**
	 * @dataProvider listsChangesSinceSuccessProvider
	 */
	public function testListsChangesSinceSuccess( $pathParams, $queryParams, $expected, $paginating ) {
		$services = $this->getServiceContainer();
		$handler = new ListsChangesSinceHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup() );

		$request = new RequestData(	[
			'pathParams' => $pathParams,
			'queryParams' => $queryParams,
		] );

		$data = $this->executeReadingListsHandlerAndGetBodyData( $handler, $request );

		$this->assertArrayHasKey( 'lists', $data );
		$this->assertIsArray( $data['lists'] );
		$this->assertSameSize( $expected, $data['lists'] );

		// Pagination parameter "next" should only appear if pagination is possible
		if ( $paginating ) {
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
				$this->listIds[$expected[$i]['name']],
				$expected[$i]['name'],
				$expected[$i]['description'],
				$expected[$i]['isDefault']
			);
		}
	}

	public static function listsChangesSinceSuccessProvider() {
		$changedSince = wfTimestamp( TS_ISO_8601, strtotime( "-7 days" ) );

		// Default sort for /lists/changes/since is by updated date, not by name
		return [
			[
				[ 'date' => $changedSince ],
				[],
				[
					[ 'name' => 'cats', 'description' => 'Meow!', 'isDefault' => false ],
					[ 'name' => 'dogs', 'description' => 'Woof!', 'isDefault' => false ],
				],
				false
			],
			[
				[ 'date' => $changedSince ],
				[ 'limit' => 1 ],
				[
					[ 'name' => 'cats', 'description' => 'Meow!', 'isDefault' => false ],
				],
				true
			],
			[
				[ 'date' => $changedSince ],
				[ 'limit' => 1, 'sort' => 'updated' ],
				[
					[ 'name' => 'cats', 'description' => 'Meow!', 'isDefault' => false ],
				],
				true
			],
			[
				[ 'date' => $changedSince ],
				[ 'limit' => 1, 'sort' => 'updated', 'dir' => 'ascending' ],
				[
					[ 'name' => 'cats', 'description' => 'Meow!', 'isDefault' => false ],
				],
				true
			],
			[
				[ 'date' => $changedSince ],
				[ 'limit' => 1, 'sort' => 'updated', 'dir' => 'descending' ],
				[
					[ 'name' => 'dogs', 'description' => 'Woof!', 'isDefault' => false ],
				],
				true
			],
			[
				[ 'date' => $changedSince ],
				[ 'limit' => 1, 'sort' => 'name' ],
				[
					[ 'name' => 'cats', 'description' => 'Meow!', 'isDefault' => false ],
				],
				true
			],
			[
				[ 'date' => $changedSince ],
				[ 'limit' => 1, 'sort' => 'name', 'dir' => 'ascending' ],
				[
					[ 'name' => 'cats', 'description' => 'Meow!', 'isDefault' => false ],
				],
				true
			],
			[
				[ 'date' => $changedSince ],
				[ 'limit' => 1, 'sort' => 'name', 'dir' => 'descending' ],
				[
					[ 'name' => 'dogs', 'description' => 'Woof!', 'isDefault' => false ],
				],
				true
			],
		];
	}

	public function testListsChangesSincePagination() {
		$services = $this->getServiceContainer();
		$handler = new ListsChangesSinceHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup() );

		$changedSince = wfTimestamp( TS_ISO_8601, strtotime( "-7 days" ) );

		$request = new RequestData( [
			'pathParams' => [ 'date' => $changedSince ],
			'queryParams' => [ 'limit' => 1 ]
		] );
		$data = $this->executeReadingListsHandlerAndGetBodyData( $handler, $request );
		$this->assertArrayHasKey( 'continue-from', $data );
		$this->assertArrayHasKey( 'lists', $data );
		$this->assertCount( 1, $data['lists'] );
		$this->assertArrayHasKey( 'next', $data );
		$this->assertIsArray( $data['lists'] );
		$this->checkReadingList(
			$data['lists'][0],
			$this->listIds['cats'],
			'cats',
			'Meow!',
			false
		);

		$request = new RequestData( [
			'pathParams' => [ 'date' => $changedSince ],
			'queryParams' => [ 'limit' => 1, 'next' => $data['next'] ]
		] );
		$data = $this->executeReadingListsHandlerAndGetBodyData( $handler, $request );
		$this->assertArrayNotHasKey( 'continue-from', $data );
		$this->assertArrayHasKey( 'lists', $data );
		$this->assertCount( 1, $data['lists'] );
		$this->assertArrayNotHasKey( 'next', $data );
		$this->assertIsArray( $data['lists'] );
		$this->checkReadingList(
			$data['lists'][0],
			$this->listIds['dogs'],
			'dogs',
			'Woof!',
			false
		);
	}

	/**
	 * @dataProvider listsChangesSinceFailureProvider
	 */
	public function testListsChangesSinceFailure( $pathParams, $queryParams ) {
		$services = $this->getServiceContainer();
		$handler = new ListsChangesSinceHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup() );

		$this->expectException( LocalizedHttpException::class );

		$request = new RequestData( [
			'pathParams' => $pathParams,
			'queryParams' => $queryParams
		] );

		$this->executeReadingListsHandler( $handler, $request );
	}

	public static function listsChangesSinceFailureProvider() {
		$changedSince = wfTimestamp( TS_ISO_8601, strtotime( "-7 days" ) );

		return [
			'missing date param' => [
				[],
				[]
			],
			'invalid sort param' => [
				[ 'date' => $changedSince ],
				[ 'sort' => 'foo' ]
			],
			'invalid dir param' => [
				[ 'date' => $changedSince ],
				[ 'dir' => 'foo' ]
			],
			// ChangesSinceHandler requires "next" param to contain separators
			'invalid next param' => [
				[ 'date' => $changedSince ],
				[ 'next' => "foo" ]
			],
			'invalid limit param' => [
				[ 'date' => $changedSince ],
				[ 'limit' => 'foo' ]
			],
		];
	}

	public function testListsAccess() {
		$services = $this->getServiceContainer();
		$handler = new ListsChangesSinceHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup( false ) );

		$this->expectException( LocalizedHttpException::class );
		$this->expectExceptionMessage( 'rest-permission-denied-anon' );

		$changedSince = wfTimestamp( TS_ISO_8601, strtotime( "-7 days" ) );
		$request = new RequestData( [ 'pathParams' => [ 'date' => $changedSince ] ] );
		$this->executeReadingListsHandler( $handler, $request, false );
	}

	protected function tearDown(): void {
		parent::tearDown();
		$this->readingListsTeardown();
	}
}
