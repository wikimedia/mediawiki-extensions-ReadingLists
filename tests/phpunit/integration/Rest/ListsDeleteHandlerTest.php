<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Integration\Rest;

use MediaWiki\Extension\ReadingLists\Rest\ListsDeleteHandler;
use MediaWiki\Extension\ReadingLists\Tests\RestTestHelperTrait;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;

/**
 * @covers \MediaWiki\Extension\ReadingLists\Rest\ListsDeleteHandler
 * @group Database
 */
class ListsDeleteHandlerTest extends \MediaWikiIntegrationTestCase {
	use RestTestHelperTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->readingListsSetup();
	}

	public function testListsDeleteSuccess() {
		$services = $this->getServiceContainer();
		$handler = new ListsDeleteHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup() );

		// Add a list, so that we can delete it.
		$repository = $this->getReadingListRepository( $handler );
		$list = $repository->addList( 'dogs', 'Woof!' );
		$listId = (int)$list->rl_id;

		$request = new RequestData(	[
			'method' => 'DELETE',
			'pathParams' => [
				'id' => $listId,
			],
		] );

		$data = $this->executeReadingListsHandler( $handler, $request );
		$this->assertSame( 200, $data->getStatusCode() );
		$this->assertSame( '{}', $data->getBody()->getContents() );
	}

	public function testListsDeleteFailure() {
		$services = $this->getServiceContainer();
		$handler = new ListsDeleteHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup() );

		$this->expectException( LocalizedHttpException::class );

		$request = new RequestData(	[
			'method' => 'DELETE',
			'pathParams' => [
				'id' => 0,
			],
		] );
		$this->executeReadingListsHandler( $handler, $request );
	}

	public function testListsDeleteAccess() {
		$services = $this->getServiceContainer();

		// Add a list, so that we can (try to) delete it.  We must use a privileged handler here.
		$handler = new ListsDeleteHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup() );
		$repository = $this->getReadingListRepository( $handler );
		$list = $repository->addList( 'dogs', 'Woof!' );
		$listId = (int)$list->rl_id;

		$handler = new ListsDeleteHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup( false ) );

		$this->expectException( LocalizedHttpException::class );
		$this->expectExceptionMessage( 'rest-permission-denied-anon' );

		$request = new RequestData( [ 'pathParams' => [ 'id' => $listId ] ] );
		$this->executeReadingListsHandler( $handler, $request, false );
	}

	protected function tearDown(): void {
		$this->readingListsTeardown();
		parent::tearDown();
	}
}
