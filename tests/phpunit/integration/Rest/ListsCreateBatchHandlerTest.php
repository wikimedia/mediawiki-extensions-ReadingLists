<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Integration\Rest;

use MediaWiki\Extension\ReadingLists\Rest\ListsCreateBatchHandler;
use MediaWiki\Extension\ReadingLists\Tests\RestTestHelperTrait;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;

/**
 * @covers \MediaWiki\Extension\ReadingLists\Rest\ListsCreateHandler
 * @group Database
 */
class ListsCreateBatchHandlerTest extends \MediaWikiIntegrationTestCase {
	use RestTestHelperTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->readingListsSetup();
	}

	/**
	 * @dataProvider createBatchSuccessProvider
	 */
	public function testListsBatchCreateSuccess( $batch, $expectedNames, $expectedDescriptions ) {
		$services = $this->getServiceContainer();
		$handler = new ListsCreateBatchHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup() );

		$request = new RequestData(	[
			'method' => 'POST',
			'headers' => [
				'Content-Type' => 'application/json',
			],
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

		$this->assertArrayHasKey( 'lists', $data );
		$this->assertIsArray( $data['lists'] );
		$this->assertCount( 2, $data['lists'] );
		for ( $i = 0; $i < 2; $i++ ) {
			$this->assertArrayHasKey( 'id', $data['lists'][$i] );
			$this->assertSame( $data['batch'][$i]['id'], $data['lists'][$i]['id'] );
			$this->assertArrayHasKey( 'name', $data['lists'][$i] );
			$this->assertSame( $expectedNames[$i], $data['lists'][$i]['name'] );
			$this->assertArrayHasKey( 'description', $data['lists'][$i] );
			$this->assertSame( $expectedDescriptions[$i], $data['lists'][$i]['description'] );
			$this->assertArrayHasKey( 'created', $data['lists'][$i] );
			$this->assertIsReadingListTimestamp( $data['lists'][$i]['created'] );
			$this->assertArrayHasKey( 'updated', $data['lists'][$i] );
			$this->assertIsReadingListTimestamp( $data['lists'][$i]['updated'] );
		}
	}

	public static function createBatchSuccessProvider() {
		return [
			[
				'{"batch":[{"name":"dogs","description":"Woof!"},{"name":"cats","description":"meow"}]}',
				[ 'dogs', 'cats' ],
				[ 'Woof!', 'meow' ]
			]
		];
	}

	/**
	 * @dataProvider createFailureProvider
	 */
	public function testListsCreateFailure( $bodyParams ) {
		$services = $this->getServiceContainer();
		$handler = new ListsCreateBatchHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup() );

		$this->expectException( LocalizedHttpException::class );

		$request = new RequestData( [
			'method' => 'POST',
			'headers' => [
				'Content-Type' => 'application/json',
			],
			'bodyContents' => json_encode( $bodyParams ),
		] );
		$this->executeReadingListsHandler( $handler, $request );
	}

	public static function createFailureProvider() {
		return [
			'no params' => [ [] ],
			'empty batch' => [ [ 'batch' => [] ] ],
			'invalid batch' => [ [ 'batch' => 0 ] ],
		];
	}

	public function testListsCreateAccess() {
		$services = $this->getServiceContainer();
		$handler = new ListsCreateBatchHandler(
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
			'bodyContents' => '{"batch":[{"name":"dogs","description":"Woof!"},{"name":"cats","description":"meow"}]}',
		] );
		$this->executeReadingListsHandler( $handler, $request, false );
	}

	public function testListsCreateToken() {
		$services = $this->getServiceContainer();
		$handler = new ListsCreateBatchHandler(
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
			'bodyContents' => '{"batch":[{"name":"dogs","description":"Woof!"},{"name":"cats","description":"meow"}]}',
		] );
		$this->executeReadingListsHandler( $handler, $request, true, false );
	}

	protected function tearDown(): void {
		parent::tearDown();
		$this->readingListsTeardown();
	}
}
