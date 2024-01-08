<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Integration\Rest;

use MediaWiki\Extension\ReadingLists\Rest\ListsEntriesCreateBatchHandler;
use MediaWiki\Extension\ReadingLists\Tests\RestTestHelperTrait;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;

/**
 * @covers \MediaWiki\Extension\ReadingLists\Rest\ListsEntriesCreateHandler
 * @group Database
 */
class ListsEntriesCreateBatchHandlerTest extends \MediaWikiIntegrationTestCase {
	use RestTestHelperTrait;

	private array $listIds;

	protected function setUp(): void {
		parent::setUp();

		// Set up test data.
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
						'rle_title' => 'Lassie',
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
						'rle_title' => 'Fish',
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
	 * @dataProvider createBatchSuccessProvider
	 */
	public function testListsBatchCreateSuccess( $listName, $batch, $expectedProjects, $expectedTitles ) {
		$services = $this->getServiceContainer();
		$handler = new ListsEntriesCreateBatchHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup() );

		$request = new RequestData(	[
			'method' => 'POST',
			'headers' => [
				'Content-Type' => 'application/json',
			],
			'pathParams' => [ 'id' => $this->listIds[$listName] ],
			'bodyContents' => $batch,
		] );
		$data = $this->executeReadingListsHandlerAndGetBodyData( $handler, $request );

		// We can't assert types of nested objects because they'll already be decoded to arrays by
		// the helper function. If it ever mattered, we could decode directly within this test.
		$this->assertArrayHasKey( 'batch', $data );
		$this->assertIsArray( $data['batch'] );
		$this->assertCount( 2, $data['batch'] );
		for ( $i = 0; $i < 2; $i++ ) {
			$this->assertArrayHasKey( 'id', $data['batch'][$i] );
			$this->assertIsInt( $data['batch'][$i]['id'] );

		}

		$this->assertArrayHasKey( 'entries', $data );
		$this->assertIsArray( $data['entries'] );
		$this->assertCount( 2, $data['entries'] );
		for ( $i = 0; $i < 2; $i++ ) {
			$this->assertArrayHasKey( 'id', $data['entries'][$i] );
			$this->assertSame( $data['batch'][$i]['id'], $data['entries'][$i]['id'] );
			$this->assertArrayHasKey( 'project', $data['entries'][$i] );
			$this->assertSame( $expectedProjects[$i], $data['entries'][$i]['project'] );
			$this->assertArrayHasKey( 'title', $data['entries'][$i] );
			$this->assertSame( $expectedTitles[$i], $data['entries'][$i]['title'] );
			$this->assertArrayHasKey( 'created', $data['entries'][$i] );
			$this->assertIsReadingListTimestamp( $data['entries'][$i]['created'] );
			$this->assertArrayHasKey( 'updated', $data['entries'][$i] );
			$this->assertIsReadingListTimestamp( $data['entries'][$i]['updated'] );
		}
	}

	public static function createBatchSuccessProvider() {
		return [
			[
				'dogs',
				'{"batch":[{"project":"foo","title":"Rover"},{"project":"foo","title":"Scooby-Doo"}]}',
				[ 'foo', 'foo' ],
				[ 'Rover', 'Scooby-Doo' ]
			]
		];
	}

	/**
	 * @dataProvider createFailureProvider
	 */
	public function testListsEntriesCreateFailure( $id, $bodyParams ) {
		$services = $this->getServiceContainer();
		$handler = new ListsEntriesCreateBatchHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup() );

		$this->expectException( LocalizedHttpException::class );

		// If the id parameter is the name of a list, it is reset to that list's id value.
		if ( isset( $this->listIds[$id] ) ) {
			$id = $this->listIds[$id];
		}

		$request = new RequestData( [
			'method' => 'POST',
			'headers' => [
				'Content-Type' => 'application/json',
			],
			'pathParams' => [ 'id' => $id ],
			'bodyContents' => json_encode( $bodyParams ),
		] );
		$this->executeReadingListsHandler( $handler, $request );
	}

	public static function createFailureProvider() {
		return [
			'no params' => [ null, [] ],
			'missing id' => [ null, [ 'project' => 'foo', 'title' => 'Lassie' ] ],
			'invalid id value' => [ 0, [ 'project' => 'foo', 'title' => 'Lassie' ] ],
			'invalid id format' => [ 'blah', [ 'project' => 'foo', 'title' => 'Lassie' ] ],
			'empty batch' => [ 'dogs', [ 'batch' => [] ] ],
			'invalid batch' => [ 'dogs', [ 'batch' => 0 ] ],
		];
	}

	public function testListsCreateBatchAccess() {
		$services = $this->getServiceContainer();
		$handler = new ListsEntriesCreateBatchHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup( false ) );

		$this->expectException( LocalizedHttpException::class );
		$this->expectExceptionMessage( 'rest-permission-denied-anon' );

		$request = new RequestData(	[
			'method' => 'POST',
			'headers' => [
				'Content-Type' => 'application/json',
			],
			'pathParams' => [ 'id' => $this->listIds['dogs'] ],
			'bodyContents' => '{"batch":[{"project":"foo","title":"Rover"},{"project":"foo","title":"Scooby-Doo"}]}',
		] );

		$this->executeReadingListsHandler( $handler, $request, false );
	}

	public function testListsCreateBatchToken() {
		$services = $this->getServiceContainer();
		$handler = new ListsEntriesCreateBatchHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup() );

		$this->expectException( LocalizedHttpException::class );
		$this->expectExceptionMessage( 'rest-badtoken-missing' );

		$request = new RequestData(	[
			'method' => 'POST',
			'headers' => [
				'Content-Type' => 'application/json',
			],
			'pathParams' => [ 'id' => $this->listIds['dogs'] ],
			'bodyContents' => '{"batch":[{"project":"foo","title":"Rover"},{"project":"foo","title":"Scooby-Doo"}]}',
		] );
		$this->executeReadingListsHandler( $handler, $request, true, false );
	}

	protected function tearDown(): void {
		parent::tearDown();
		$this->readingListsTeardown();
	}
}
