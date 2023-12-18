<?php

namespace MediaWiki\Extension\ReadingLists\Tests;

use MediaWiki\Extension\ReadingLists\Rest\SetupHandler;
use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\Authority;
use MediaWiki\Permissions\UltimateAuthority;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\RequestData;
use MediaWiki\Rest\RequestInterface;
use MediaWiki\Rest\ResponseInterface;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWiki\User\CentralId\CentralIdLookup;
use MediaWiki\User\UserIdentityValue;
use PHPUnit\Framework\MockObject\MockObject;

trait RestSetupTestTrait {
	use HandlerTestTrait;

	private ?Authority $authority = null;

	private function getAuthority(): Authority {
		if ( !$this->authority ) {
			$this->authority = new UltimateAuthority( new UserIdentityValue( 1, '127.0.0.1' ) );
		}
		return $this->authority;
	}

	/**
	 * @return MockObject|CentralIdLookup
	 */
	private function getMockCentralIdLookup() {
		$centralIdLookup = $this->createNoOpMock( CentralIdLookup::class, [ 'centralIdFromLocalUser' ] );
		$centralIdLookup->method( 'centralIdFromLocalUser' )
			->willReturn( $this->getAuthority()->getUser()->getId() );
		return $centralIdLookup;
	}

	/**
	 * Executes the given Handler on the given request.
	 *
	 * @param Handler $handler
	 * @param RequestInterface $request
	 * @return ResponseInterface
	 */
	private function executeReadingListsHandler( Handler $handler, RequestInterface $request ) {
		return $this->executeHandler(
			$handler,
			$request,
			[],
			$this->createHookContainer(),
			[],
			[],
			$this->getAuthority(),
			$this->getSession( true )
		);
	}

	private function readingListsSetup(): object {
		$request = new RequestData();
		$services = $this->getServiceContainer();
		$handler = new SetupHandler(
			MediaWikiServices::getInstance()->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup()
		);
		return $this->executeReadingListsHandler( $handler, $request );
	}
}
