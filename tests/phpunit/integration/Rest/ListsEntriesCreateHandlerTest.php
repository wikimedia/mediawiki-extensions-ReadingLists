<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Integration\Rest;

use MediaWiki\Extension\ReadingLists\Rest\ListsEntriesCreateHandler;
use MediaWiki\Extension\ReadingLists\Tests\RestTestHelperTrait;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;

/**
 * @covers \MediaWiki\Extension\ReadingLists\Rest\ListsEntriesCreateHandler
 * @group Database
 */
class ListsEntriesCreateHandlerTest extends \MediaWikiIntegrationTestCase {
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
	 * @dataProvider createSuccessProvider
	 */
	public function testListsCreateSuccess( $listName, $project, $title ) {
		$services = $this->getServiceContainer();
		$handler = new ListsEntriesCreateHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup() );

		$request = new RequestData(	[
			'method' => 'POST',
			'headers' => [
				'Content-Type' => 'application/json',
			],
			'pathParams' => [ 'id' => $this->listIds[$listName] ],
			'bodyContents' => json_encode( [
				'project' => $project,
				'title' => $title
			] ),
		] );
		$data = $this->executeReadingListsHandlerAndGetBodyData( $handler, $request );

		$this->assertArrayHasKey( 'id', $data );
		$this->assertArrayHasKey( 'entry', $data );
		$this->assertArrayHasKey( 'id', $data['entry'] );
		$this->assertSame( $data['id'], $data['entry']['id'] );
		$this->assertArrayHasKey( 'project', $data['entry'] );
		$this->assertSame( $data['entry']['project'], $project );
		$this->assertArrayHasKey( 'title', $data['entry'] );
		$this->assertSame( $data['entry']['title'], $title );
		$this->assertArrayHasKey( 'created', $data['entry'] );
		$this->assertIsReadingListTimestamp( $data['entry']['created'] );
		$this->assertArrayHasKey( 'updated', $data['entry'] );
		$this->assertIsReadingListTimestamp( $data['entry']['updated'] );
	}

	public static function createSuccessProvider() {
		return [
			[ 'cats', 'foo', 'Garfield' ]
		];
	}

	/**
	 * @dataProvider createFailureProvider
	 */
	public function testListsEntriesCreateFailure( $id, $bodyParams ) {
		$services = $this->getServiceContainer();
		$handler = new ListsEntriesCreateHandler(
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
			'missing project' => [ 'dogs', [ 'title' => 'Lassie' ] ],
			'invalid project value' => [ 'dogs', [ 'project' => 'nonexistent', 'title' => 'Lassie' ] ],
			'missing title' => [ 'dogs', [ 'project' => 'foo' ] ],
		];
	}

	public function testListsCreateAccess() {
		$services = $this->getServiceContainer();
		$handler = new ListsEntriesCreateHandler(
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
			'pathParams' => [ 'id' => $this->listIds['cats'] ],
			'bodyContents' => json_encode( [
				'project' => 'foo',
				'title' => 'Garfield'
			] ),
		] );

		$this->executeReadingListsHandler( $handler, $request, false );
	}

	public function testListsCreateToken() {
		$services = $this->getServiceContainer();
		$handler = new ListsEntriesCreateHandler(
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
			'pathParams' => [ 'id' => $this->listIds['cats'] ],
			'bodyContents' => json_encode( [
				'project' => 'foo',
				'title' => 'Garfield'
			] ),
		] );
		$this->executeReadingListsHandler( $handler, $request, true, false );
	}

	protected function tearDown(): void {
		parent::tearDown();
		$this->readingListsTeardown();
	}
}
