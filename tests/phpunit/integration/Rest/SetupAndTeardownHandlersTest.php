<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Integration\Rest;

use MediaWiki\Extension\ReadingLists\Rest\TeardownHandler;
use MediaWiki\Extension\ReadingLists\Tests\RestSetupTestTrait;
use MediaWiki\Rest\RequestData;

/**
 * @covers \MediaWiki\Extension\ReadingLists\Rest\SetupHandler
 * @group Database
 */
class SetupAndTeardownHandlersTest extends \MediaWikiIntegrationTestCase {
	use RestSetupTestTrait;

	public function testSetup() {
		$data = $this->readingListsSetup();
		$this->assertEquals( 200, $data->getStatusCode() );
		$this->assertSame( "{}", $data->getBody()->getContents() );
	}

	public function testTeardown() {
		$this->readingListsSetup();
		$request = new RequestData();
		$services = $this->getServiceContainer();
		$handler = new TeardownHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup() );
		$data = $this->executeReadingListsHandler( $handler, $request );
		$this->assertEquals( 200, $data->getStatusCode() );
		$this->assertSame( "{}", $data->getBody()->getContents() );
	}

}
