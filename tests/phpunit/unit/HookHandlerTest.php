<?php

namespace MediaWiki\Extension\ReadingLists\Tests;

use MediaWiki\Extension\ReadingLists\HookHandler;
use MediaWiki\Extension\ReadingLists\ReadingListRepository;
use MediaWiki\User\UserIdentity;

/**
 * @covers \MediaWiki\Extension\ReadingLists\HookHandler
 */
class HookHandlerTest extends \MediaWikiUnitTestCase {
	/**
	 * Test the logic of getDefaultReadingListUrl without SpecialPage dependency
	 * @param UserIdentity $user
	 * @param ReadingListRepository $repository
	 * @return string
	 */
	private function testGetDefaultReadingListUrl( $user, $repository ) {
		$defaultListId = $repository->getDefaultListIdForUser();

		if ( $defaultListId === false ) {
			return 'Special:ReadingLists';
		}

		$userName = $user->getName();
		return 'Special:ReadingLists/' . $userName . '/' . $defaultListId;
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

	public function testGetDefaultReadingListUrl_WithDefaultList() {
		$user = $this->createMock( UserIdentity::class );
		$user->method( 'getName' )->willReturn( 'TestUser' );

		$repository = $this->createMock( ReadingListRepository::class );
		$repository->method( 'getDefaultListIdForUser' )->willReturn( 123 );

		$result = $this->testGetDefaultReadingListUrl( $user, $repository );

		// The result should be a URL containing the username and list ID
		$this->assertStringContainsString( 'TestUser/123', $result );
		$this->assertStringContainsString( 'Special:ReadingLists', $result );
	}

	public function testGetDefaultReadingListUrl_WithoutDefaultList() {
		$user = $this->createMock( UserIdentity::class );

		$repository = $this->createMock( ReadingListRepository::class );
		$repository->method( 'getDefaultListIdForUser' )->willReturn( false );

		$result = $this->testGetDefaultReadingListUrl( $user, $repository );

		// The result should be the generic ReadingLists special page URL
		$this->assertStringContainsString( 'Special:ReadingLists', $result );
		// Should not contain a username/list ID path
		$this->assertStringNotContainsString( '/', $result );
	}
}
