<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Integration\Rest;

use MediaWiki\Extension\ReadingLists\Rest\ListsUpdateHandler;
use MediaWiki\Extension\ReadingLists\Tests\RestTestHelperTrait;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;

/**
 * @covers \MediaWiki\Extension\ReadingLists\Rest\ListsUpdateHandler
 * @group Database
 */
class ListsUpdateHandlerTest extends \MediaWikiIntegrationTestCase {
	use RestTestHelperTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->readingListsSetup();
	}

	public function testListsUpdateSuccess() {
		$services = $this->getServiceContainer();
		$handler = new ListsUpdateHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup() );

		// Add a list, so that we can update it.
		$repository = $this->getReadingListRepository( $handler );
		$list = $repository->addList( 'dogs', 'Woof!' );
		$listId = (int)$list->rl_id;

		$newName = "cow";
		$newDescription = "Moo!";
		$request = new RequestData(	[
			'method' => 'POST',
			'headers' => [
				'Content-Type' => 'application/json',
			],
			'pathParams' => [
				'id' => $listId,
			],
			'bodyContents' => json_encode( [
				'name' => $newName,
				'description' => $newDescription,
			] ),
		] );

		$data = $this->executeReadingListsHandlerAndGetBodyData( $handler, $request );
		$this->assertArrayHasKey( 'list', $data );
		$this->assertArrayHasKey( 'id', $data['list'] );
		$this->assertArrayHasKey( 'name', $data['list'] );
		$this->assertSame( $data['list']['name'], $newName );
		$this->assertArrayHasKey( 'description', $data['list'] );
		$this->assertSame( $data['list']['description'], $newDescription );
		$this->assertArrayHasKey( 'created', $data['list'] );
		$this->assertIsReadingListTimestamp( $data['list']['created'] );
		$this->assertArrayHasKey( 'updated', $data['list'] );
		$this->assertIsReadingListTimestamp( $data['list']['updated'] );
	}

	/**
	 * @dataProvider updateFailureProvider
	 */
	public function testListsUpdateFailure( $id, $bodyParams ) {
		$services = $this->getServiceContainer();
		$handler = new ListsUpdateHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup() );

		// Add a list, so that we can update it.
		$repository = $this->getReadingListRepository( $handler );
		$list = $repository->addList( 'dogs', 'Woof!' );
		$listId = (int)$list->rl_id;

		$this->expectException( LocalizedHttpException::class );

		$request = new RequestData(	[
			'method' => 'POST',
			'headers' => [
				'Content-Type' => 'application/json',
			],
			'pathParams' => [
				'id' => $id ?? $listId,
			],
			'bodyContents' => json_encode( $bodyParams ),
		] );
		$this->executeReadingListsHandler( $handler, $request );
	}

	public static function updateFailureProvider() {
		return [
			'invalid id' => [ 0, [ 'name' => 'dogs', 'description' => 'Woof!' ] ],
			'no body params' => [ null, [] ]
		];
	}

	public function testListsUpdateAccess() {
		$services = $this->getServiceContainer();

		// Add a list, so that we can (try to) update it. We must use a privileged handler here.
		$handler = new ListsUpdateHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup() );
		$repository = $this->getReadingListRepository( $handler );
		$list = $repository->addList( 'dogs', 'Woof!' );
		$listId = (int)$list->rl_id;

		// Now create the unprivileged handler to test.
		$handler = new ListsUpdateHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup( false ) );

		$request = new RequestData(	[
			'method' => 'POST',
			'headers' => [
				'Content-Type' => 'application/json',
			],
			'pathParams' => [
				'id' => $listId,
			],
			'bodyContents' => json_encode( [
				'name' => 'cow',
				'description' => 'Moo!',
			] ),
		] );

		$this->expectException( LocalizedHttpException::class );
		$this->expectExceptionMessage( 'rest-permission-denied-anon' );

		$this->executeReadingListsHandler( $handler, $request, false );
	}

	public function testListsUpdateToken() {
		$services = $this->getServiceContainer();
		$handler = new ListsUpdateHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup() );

		// Add a list, so that we can (try to) update it.
		$repository = $this->getReadingListRepository( $handler );
		$list = $repository->addList( 'dogs', 'Woof!' );
		$listId = (int)$list->rl_id;

		$request = new RequestData(	[
			'method' => 'POST',
			'headers' => [
				'Content-Type' => 'application/json',
			],
			'pathParams' => [
				'id' => $listId,
			],
			'bodyContents' => json_encode( [
				'name' => 'cow',
				'description' => 'Moo!',
			] ),
		] );

		$this->expectException( LocalizedHttpException::class );
		$this->expectExceptionMessage( 'rest-badtoken-missing' );

		$this->executeReadingListsHandler( $handler, $request, true, false );
	}

	protected function tearDown(): void {
		$this->readingListsTeardown();
		parent::tearDown();
	}
}
