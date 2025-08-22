<?php

namespace MediaWiki\Extension\ReadingLists\Tests;

use MediaWiki\Config\Config;
use MediaWiki\Extension\ReadingLists\HookHandler;
use MediaWiki\Extension\ReadingLists\ReadingListRepository;
use MediaWiki\Skin\SkinTemplate;
use MediaWiki\User\CentralId\CentralIdLookupFactory;
use MediaWiki\User\UserEditTracker;
use MediaWiki\User\UserIdentity;
use Wikimedia\Rdbms\LBFactory;

/**
 * @covers \MediaWiki\Extension\ReadingLists\HookHandler
 */
class HookHandlerTest extends \MediaWikiUnitTestCase {
	/**
	 * HookHandler subclass that overrides the private method to avoid SpecialPage dependency
	 * @return HookHandler
	 */
	private function createTestHookHandler() {
		return new class(
			$this->createMock( CentralIdLookupFactory::class ),
			$this->createMock( Config::class ),
			$this->createMock( LBFactory::class ),
			$this->createMock( UserEditTracker::class )
		) extends HookHandler {
			public function testGetDefaultReadingListUrl( $user, $repository ) {
				$defaultListId = $repository->getDefaultListIdForUser();

				if ( $defaultListId === false ) {
					return 'Special:ReadingLists';
				}

				$userName = $user->getName();
				return 'Special:ReadingLists/' . $userName . '/' . $defaultListId;
			}
		};
	}

	public function testIsSkinSupported() {
		$skins = [
			'vector-2022' => true,
			'minerva' => true,
			'cologneblue' => false,
			'modern' => false,
			'monobook' => false,
			'timeless' => false,
			'vector' => false
		];

		foreach ( $skins as $skinName => $expectedValue ) {
			$this->assertSame( $expectedValue, HookHandler::isSkinSupported( $skinName ) );
		}
	}

	public function testHideWatchlistIcon() {
		$sktemplate = $this->createMock( SkinTemplate::class );
		$sktemplate->method( 'getSkinName' )->willReturn( 'minerva' );

		$userEditTracker = $this->createMock( UserEditTracker::class );
		$userEditTracker->method( 'getUserEditCount' )->willReturn( 0 );

		$user = $this->createMock( UserIdentity::class );
		$links = [ 'user-menu' => [ 'watchlist' => [], 'recentchanges' => [] ] ];

		$hookHandler = new HookHandler(
			$this->createMock( CentralIdLookupFactory::class ),
			$this->createMock( Config::class ),
			$this->createMock( LBFactory::class ),
			$userEditTracker
		);
		$hookHandler->hideWatchlistIcon( $sktemplate, $user, $links );

		// The watchlist key should be removed
		$this->assertArrayNotHasKey( 'watchlist', $links['user-menu'] );

		// Any other keys should be unaffected
		$this->assertArrayHasKey( 'recentchanges', $links['user-menu'] );
	}

	public function testHideWatchIcon() {
		$skins = [ 'vector-2022', 'minerva', 'monobook', 'timeless', 'vector' ];

		foreach ( $skins as $skin ) {
			$sktemplate = $this->createMock( SkinTemplate::class );
			$sktemplate->method( 'getSkinName' )->willReturn( $skin );

			$links = [ 'actions' => [ 'watch' => [], 'unwatch' => [], 'protect' => [] ] ];

			HookHandler::hideWatchIcon( $sktemplate, $links );

			if ( $skin === 'vector-2022' || $skin === 'minerva' ) {
				$this->assertArrayNotHasKey( 'watch', $links['actions'] );
				$this->assertArrayNotHasKey( 'unwatch', $links['actions'] );
			} else {
				$this->assertArrayHasKey( 'watch', $links['actions'] );
				$this->assertArrayHasKey( 'unwatch', $links['actions'] );
			}

			$this->assertArrayHasKey( 'protect', $links['actions'] );
		}
	}

	public function testGetDefaultReadingListUrl_WithDefaultList() {
		$user = $this->createMock( UserIdentity::class );
		$user->method( 'getName' )->willReturn( 'TestUser' );

		$repository = $this->createMock( ReadingListRepository::class );
		$repository->method( 'getDefaultListIdForUser' )->willReturn( 123 );

		$testHookHandler = $this->createTestHookHandler();
		$result = $testHookHandler->testGetDefaultReadingListUrl( $user, $repository );

		// The result should be a URL containing the username and list ID
		$this->assertStringContainsString( 'TestUser/123', $result );
		$this->assertStringContainsString( 'Special:ReadingLists', $result );
	}

	public function testGetDefaultReadingListUrl_WithoutDefaultList() {
		$user = $this->createMock( UserIdentity::class );

		$repository = $this->createMock( ReadingListRepository::class );
		$repository->method( 'getDefaultListIdForUser' )->willReturn( false );

		$testHookHandler = $this->createTestHookHandler();
		$result = $testHookHandler->testGetDefaultReadingListUrl( $user, $repository );

		// The result should be the generic ReadingLists special page URL
		$this->assertStringContainsString( 'Special:ReadingLists', $result );
		// Should not contain a username/list ID path
		$this->assertStringNotContainsString( '/', $result );
	}
}
