<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Integration\Rest;

use MediaWiki\Extension\ReadingLists\Rest\ListsPagesHandler;
use MediaWiki\Extension\ReadingLists\Tests\RestTestHelperTrait;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;

/**
 * @covers \MediaWiki\Extension\ReadingLists\Rest\ListsPagesHandler
 * @group Database
 */
class ListsPagesHandlerTest extends \MediaWikiIntegrationTestCase {
	use RestTestHelperTrait;

	private array $listIds;

	protected function setUp(): void {
		parent::setUp();

		// Set up data to query.
		$this->addProjects( [ 'foo' ] );
		$lastUpdateYesterday = wfTimestamp( TS_MW, strtotime( "-1 days" ) );
		$lastUpdateToday = wfTimestamp( TS_MW );
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
				'rl_date_created' => '20180914205936',
				'rl_date_updated' => $lastUpdateYesterday,
				'rl_deleted' => 0,
			],
			[
				'rl_is_default' => 0,
				'rl_name' => 'pets',
				'rl_description' => '',
				'rl_date_created' => '20170913205936',
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
	 * @dataProvider listsPagesSuccessProvider
	 */
	public function testListsPagesSuccess( $pathParams, $queryParams, $expected, $paginating ) {
		$services = $this->getServiceContainer();
		$handler = new ListsPagesHandler(
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

	public static function listsPagesSuccessProvider() {
		return [
			[
				[ 'project' => 'foo', 'title' => 'Dog' ],
				[],
				[
					[ 'name' => 'dogs', 'description' => 'Woof!', 'isDefault' => false ],
					[ 'name' => 'pets', 'description' => '', 'isDefault' => false ],
				],
				false
			],
			[
				[ 'project' => 'foo', 'title' => 'Dog' ],
				[ 'limit' => 1 ],
				[
					[ 'name' => 'dogs', 'description' => 'Woof!', 'isDefault' => false ],
				],
				true
			],
		];
	}

	public function testListsPagesPagination() {
		$services = $this->getServiceContainer();

		$handler = new ListsPagesHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup() );
		$request = new RequestData( [
			'pathParams' => [ 'project' => 'foo', 'title' => 'Dog' ],
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
			$this->listIds['dogs'],
			'dogs',
			'Woof!',
			false
		);

		// Use a separate handler instance. This avoids double-initialization errors, and also
		// ensures there are no side effects between invocations.
		$handler = new ListsPagesHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup() );
		$request = new RequestData( [
			'pathParams' => [ 'project' => 'foo', 'title' => 'Dog' ],
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
			$this->listIds['pets'],
			'pets',
			'',
			false
		);
	}

	/**
	 * @dataProvider listsPagesFailureProvider
	 */
	public function testListsPagesFailure( $pathParams, $queryParams ) {
		$services = $this->getServiceContainer();
		$handler = new ListsPagesHandler(
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

	public static function listsPagesFailureProvider() {
		return [
			'missing project param' => [
				[ 'title' => 'Dog' ], []
			],
			'missing title param' => [
				[ 'project' => 'foo' ], []
			],
			// ListsPagesHandler requires "next", if supplied, to be an int represented as a string
			'invalid next param' => [
				[ 'project' => 'foo', 'title' => 'Dog' ], [ 'next' => "bar" ]
			],
			'invalid limit param' => [
				[ 'project' => 'foo', 'title' => 'Dog' ], [ 'limit' => 'foo' ]
			],
		];
	}

	public function testListsPagesAccess() {
		$services = $this->getServiceContainer();
		$handler = new ListsPagesHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup( false ) );

		$this->expectException( LocalizedHttpException::class );
		$this->expectExceptionMessage( 'rest-permission-denied-anon' );

		$request = new RequestData( [ 'pathParams' => [ 'project' => 'foo', 'title' => 'Dog' ] ] );
		$this->executeReadingListsHandler( $handler, $request, false );
	}

	protected function tearDown(): void {
		parent::tearDown();
		$this->readingListsTeardown();
	}
}
