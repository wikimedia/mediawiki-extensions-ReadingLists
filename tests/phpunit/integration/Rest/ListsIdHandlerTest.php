<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Integration\Rest;

use MediaWiki\Extension\ReadingLists\Rest\ListsIdHandler;
use MediaWiki\Extension\ReadingLists\Tests\RestTestHelperTrait;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;

/**
 * @covers \MediaWiki\Extension\ReadingLists\Rest\ListsIdHandler
 * @group Database
 */
class ListsIdHandlerTest extends \MediaWikiIntegrationTestCase {
	use RestTestHelperTrait;

	private array $listIds;

	protected function setUp(): void {
		parent::setUp();
		$this->readingListsSetup();
	}

	public function testListsIdSuccess() {
		$services = $this->getServiceContainer();
		$handler = new ListsIdHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup() );
		$repository = $this->getReadingListRepository( $handler );

		// Set up data to query as needed. Id of zero is to skip id assert for the default list.
		$list = $repository->addList( 'dogs', 'Woof!' );
		$listId = (int)$list->rl_id;

		$request = new RequestData( [ 'pathParams' => [ 'id' => $listId ] ] );
		$data = $this->executeReadingListsHandlerAndGetBodyData( $handler, $request );
		$this->assertIsArray( $data );
		$this->checkReadingList(
			$data,
			$listId,
			'dogs',
			'Woof!',
			false
		);
	}

	/**
	 * @dataProvider listsIdFailureProvider
	 */
	public function testListsIdFailure( $pathParams ) {
		$services = $this->getServiceContainer();
		$handler = new ListsIdHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup() );

		$this->expectException( LocalizedHttpException::class );

		$request = new RequestData( [
			'pathParams' => $pathParams,
		] );

		$this->executeReadingListsHandler( $handler, $request );
	}

	public static function listsIdFailureProvider() {
		return [
			'missing list param' => [ [] ],
			'invalid list param' => [ [ 'id' => 0 ] ],
			'list not found' => [ [ 'id' => 999 ] ],
		];
	}

	public function testListsIdAccess() {
		$services = $this->getServiceContainer();
		$handler = new ListsIdHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup( false ) );

		$this->expectException( LocalizedHttpException::class );
		$this->expectExceptionMessage( 'rest-permission-denied-anon' );

		$defaultListId = 1;
		$request = new RequestData( [ 'pathParams' => [ 'id' => $defaultListId ] ] );
		$this->executeReadingListsHandler( $handler, $request, false );
	}

	protected function tearDown(): void {
		parent::tearDown();
		$this->readingListsTeardown();
	}
}
