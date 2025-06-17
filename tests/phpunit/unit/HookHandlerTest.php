<?php

namespace MediaWiki\Extension\ReadingLists\Tests;

use MediaWiki\Config\Config;
use MediaWiki\Extension\ReadingLists\HookHandler;
use MediaWiki\Skin\SkinTemplate;
use MediaWiki\User\CentralId\CentralIdLookupFactory;
use MediaWiki\User\UserEditTracker;
use MediaWiki\User\UserIdentity;
use Wikimedia\Rdbms\LBFactory;

/**
 * @covers \MediaWiki\Extension\ReadingLists\HookHandler
 */
class HookHandlerTest extends \MediaWikiUnitTestCase {
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
}
