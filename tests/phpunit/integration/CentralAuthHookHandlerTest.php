<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Integration;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\ReadingLists\CentralAuthHookHandler;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Skin\SkinTemplate;
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
		bool $growthExperimentsLoaded = true,
	): CentralAuthHookHandler {
		$extensionRegistry = $this->createMock( ExtensionRegistry::class );
		$extensionRegistry->method( 'isLoaded' )
			->with( 'GrowthExperiments' )
			->willReturn( $growthExperimentsLoaded );

		return new CentralAuthHookHandler(
			$userOptionsManager ?? $this->createMock( UserOptionsManager::class ),
			$extensionRegistry,
		);
	}

	private function setMainContextUserAndSkin( User $user, string $skinName ): void {
		$skin = $this->createMock( SkinTemplate::class );
		$skin->method( 'getSkinName' )->willReturn( $skinName );
		RequestContext::getMain()->setUser( $user );
		RequestContext::getMain()->setSkin( $skin );
	}

	public static function provideSkinPreference(): array {
		return [
			'minerva' => [ 'minerva', 'homepage_mobile_discovery_notice_seen' ],
			'vector-2022' => [ 'vector-2022', 'growthexperiments-tour-homepage-discovery' ],
		];
	}

	/**
	 * @dataProvider provideSkinPreference
	 */
	public function testCentralAuthPostLoginRedirectMarksDiscoveryPopoverSeenForSignup(
		string $skinName,
		string $expectedPreference
	): void {
		$user = $this->createMock( User::class );
		$user->method( 'isRegistered' )->willReturn( true );
		$this->setMainContextUserAndSkin( $user, $skinName );

		$userOptionsManager = $this->createMock( UserOptionsManager::class );
		$userOptionsManager->expects( $this->once() )
			->method( 'setOption' )
			->with( $user, $expectedPreference, 1 );
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

	public function testCentralAuthPostLoginRedirectSkipsPreferencesWithoutGrowthExperiments(): void {
		$user = $this->createMock( User::class );
		$user->method( 'isRegistered' )->willReturn( true );
		$this->setMainContextUserAndSkin( $user, 'vector-2022' );

		$userOptionsManager = $this->createMock( UserOptionsManager::class );
		$userOptionsManager->expects( $this->never() )->method( 'setOption' );
		$userOptionsManager->expects( $this->never() )->method( 'saveOptions' );
		$hookHandler = $this->createHookHandler( $userOptionsManager, false );

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

		$this->assertSame(
			[
				'foo' => 'bar',
				'readingListsAccountJustCreated' => '1',
			],
			wfCgiToArray( $returnToQuery )
		);
	}
}
