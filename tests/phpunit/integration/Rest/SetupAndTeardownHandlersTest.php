<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Integration\Rest;

use MediaWiki\Extension\ReadingLists\Rest\SetupHandler;
use MediaWiki\Extension\ReadingLists\Rest\TeardownHandler;
use MediaWiki\Extension\ReadingLists\Tests\RestTestHelperTrait;
use MediaWiki\MediaWikiServices;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;

/**
 * @covers \MediaWiki\Extension\ReadingLists\Rest\SetupHandler
 * @covers \MediaWiki\Extension\ReadingLists\Rest\TeardownHandler
 * @group Database
 */
class SetupAndTeardownHandlersTest extends \MediaWikiIntegrationTestCase {
	use RestTestHelperTrait;

	public function testSetupAccess() {
		$services = $this->getServiceContainer();
		$handler = new SetupHandler(
			MediaWikiServices::getInstance()->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup( false )
		);

		$this->expectException( LocalizedHttpException::class );
		$this->expectExceptionMessage( 'rest-permission-denied-anon' );

		$request = new RequestData();
		$this->executeReadingListsHandler( $handler, $request, false );
	}

	public function testSetupToken() {
		$services = $this->getServiceContainer();
		$handler = new SetupHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup() );

		$this->expectException( LocalizedHttpException::class );
		$this->expectExceptionMessage( 'rest-badtoken-missing' );

		$request = new RequestData();
		$this->executeReadingListsHandler( $handler, $request, true, false );
	}

	public function testSetup() {
		$data = $this->readingListsSetup();
		$this->assertEquals( 200, $data->getStatusCode() );
		$this->assertSame( "{}", $data->getBody()->getContents() );
	}

	public function testSetupFailure() {
		// Duplicate setups should fail
		$this->expectException( LocalizedHttpException::class );
		$this->readingListsSetup();
		$this->readingListsSetup();
	}

	public function testTeardownAccess() {
		$services = $this->getServiceContainer();
		$handler = new TeardownHandler(
			MediaWikiServices::getInstance()->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup( false )
		);

		$this->expectException( LocalizedHttpException::class );
		$this->expectExceptionMessage( 'rest-permission-denied-anon' );

		$request = new RequestData();
		$this->executeReadingListsHandler( $handler, $request, false );
	}

	public function testTeardownToken() {
		$services = $this->getServiceContainer();
		$handler = new TeardownHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup() );

		$this->expectException( LocalizedHttpException::class );
		$this->expectExceptionMessage( 'rest-badtoken-missing' );

		$request = new RequestData();
		$this->executeReadingListsHandler( $handler, $request, true, false );
	}

	public function testTeardown() {
		$this->readingListsSetup();
		$data = $this->readingListsTeardown();
		$this->assertEquals( 200, $data->getStatusCode() );
		$this->assertSame( "{}", $data->getBody()->getContents() );
	}

	public function testTeardownFailure() {
		// Teardown before setup should fail.
		$this->expectException( LocalizedHttpException::class );
		$this->readingListsTeardown();
	}
}
