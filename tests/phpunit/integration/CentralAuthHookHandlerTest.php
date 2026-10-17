<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Integration;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\ReadingLists\CentralAuthHookHandler;
use MediaWiki\User\Options\UserOptionsManager;
use MediaWiki\User\User;

/**
 * @covers \MediaWiki\Extension\ReadingLists\CentralAuthHookHandler
 */
class CentralAuthHookHandlerTest extends \MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->markTestSkippedIfExtensionNotLoaded( 'CentralAuth' );
	}

	protected function tearDown(): void {
		RequestContext::resetMain();
		parent::tearDown();
	}

	private function createHookHandler(
		?UserOptionsManager $userOptionsManager = null,
	): CentralAuthHookHandler {
		return new CentralAuthHookHandler(
			$userOptionsManager ?? $this->createMock( UserOptionsManager::class ),
		);
	}

	public function testCentralAuthPostLoginRedirectAddsReadingListsAccountJustCreatedForSignup(): void {
		$user = $this->createMock( User::class );
		$user->method( 'isRegistered' )->willReturn( true );
		RequestContext::getMain()->setUser( $user );

		$userOptionsManager = $this->createMock( UserOptionsManager::class );
		$userOptionsManager->expects( $this->once() )
			->method( 'setOption' )
			->with( $user, 'homepage_mobile_discovery_notice_seen', 1 );
		$userOptionsManager->expects( $this->once() )
			->method( 'saveOptions' )
			->with( $user );
		$hookHandler = $this->createHookHandler( $userOptionsManager );

		$returnTo = 'Taco';
		$returnToQuery = 'readingListsAccountCreationCta=1&foo=bar';
		$unused = '';

		$this->assertTrue(
			$hookHandler->onCentralAuthPostLoginRedirect(
				$returnTo,
				$returnToQuery,
				false,
				'signup',
				$unused
			)
		);

		$this->assertSame( 'Taco', $returnTo );
		$this->assertSame( '', $unused );
		$this->assertSame(
			[
				'foo' => 'bar',
				'readingListsAccountJustCreated' => '1',
			],
			wfCgiToArray( $returnToQuery )
		);
	}
}
