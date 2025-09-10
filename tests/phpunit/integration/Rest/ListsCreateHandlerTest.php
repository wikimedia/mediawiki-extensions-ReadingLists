<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Integration\Rest;

use MediaWiki\Extension\ReadingLists\Rest\ListsCreateHandler;
use MediaWiki\Extension\ReadingLists\Tests\RestTestHelperTrait;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;

/**
 * @covers \MediaWiki\Extension\ReadingLists\Rest\ListsCreateHandler
 * @group Database
 */
class ListsCreateHandlerTest extends \MediaWikiIntegrationTestCase {
	use RestTestHelperTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->readingListsSetup();
	}

	/**
	 * @dataProvider createSuccessProvider
	 */
	public function testListsCreateSuccess( $name, $description, $extra ) {
		$services = $this->getServiceContainer();
		$handler = new ListsCreateHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup() );

		$request = new RequestData(	[
			'method' => 'POST',
			'headers' => [
				'Content-Type' => 'application/json',
			],
			'bodyContents' => json_encode( [
				'name' => $name,
				'description' => $description,
				'extra_field' => $extra
			] ),
		] );
		$data = $this->executeReadingListsHandlerAndGetBodyData( $handler, $request );

		$this->assertArrayHasKey( 'id', $data );
		$this->assertArrayHasKey( 'list', $data );
		$this->assertArrayHasKey( 'id', $data['list'] );
		$this->assertSame( $data['id'], $data['list']['id'] );
		$this->assertArrayHasKey( 'name', $data['list'] );
		$this->assertSame( $data['list']['name'], $name );
		$this->assertArrayHasKey( 'description', $data['list'] );
		$this->assertSame( $data['list']['description'], $description );
		$this->assertArrayHasKey( 'created', $data['list'] );
		$this->assertIsReadingListTimestamp( $data['list']['created'] );
		$this->assertArrayHasKey( 'updated', $data['list'] );
		$this->assertIsReadingListTimestamp( $data['list']['updated'] );
	}

	public static function createSuccessProvider() {
		return [
			[ 'dogs', 'Woof!', 'extra' ]
		];
	}

	/**
	 * @dataProvider createFailureProvider
	 */
	public function testListsCreateFailure( $bodyParams ) {
		$services = $this->getServiceContainer();
		$handler = new ListsCreateHandler(
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
			'missing name' => [ [ 'description' => 'Woof!' ] ],
		];
	}

	public function testListsCreateAccess() {
		$services = $this->getServiceContainer();
		$handler = new ListsCreateHandler(
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
			'bodyContents' => json_encode( [
				'name' => 'dogs',
				'description' => 'Woof!'
			] ),
		] );
		$this->executeReadingListsHandler( $handler, $request, false );
	}

	public function testListsCreateToken() {
		$services = $this->getServiceContainer();
		$handler = new ListsCreateHandler(
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
			'bodyContents' => json_encode( [
				'name' => 'dogs',
				'description' => 'Woof!'
			] ),
		] );
		$this->executeReadingListsHandler( $handler, $request, true, false );
	}

	protected function tearDown(): void {
		$this->readingListsTeardown();
		parent::tearDown();
	}
}
