<?php

namespace MediaWiki\Extension\ReadingLists\Tests;

use MediaWiki\Config\Config;
use MediaWiki\Config\HashConfig;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\ReadingLists\HookHandler;
use MediaWiki\Extension\ReadingLists\Service\BookmarkEntryLookupService;
use MediaWiki\Message\Message;
use MediaWiki\Output\OutputPage;
use MediaWiki\Skin\SkinTemplate;
use MediaWiki\Title\Title;
use MediaWiki\User\CentralId\CentralIdLookupFactory;
use MediaWiki\User\Options\UserOptionsManager;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityUtils;

/**
 * @covers \MediaWiki\Extension\ReadingLists\HookHandler
 */
class HookHandlerTest extends \MediaWikiUnitTestCase {

	protected function tearDown(): void {
		RequestContext::resetMain();
		parent::tearDown();
	}

	private function createHookHandler(
		?Config $config = null,
		?UserOptionsManager $userOptionsManager = null,
		?UserIdentityUtils $userIdentityUtils = null
	): HookHandler {
		return new HookHandler(
			$config ?? $this->createMock( Config::class ),
			$this->createMock( BookmarkEntryLookupService::class ),
			$userOptionsManager ?? $this->createMock( UserOptionsManager::class ),
			$this->createMock( CentralIdLookupFactory::class ),
			$userIdentityUtils ?? $this->createMock( UserIdentityUtils::class )
		);
	}

	private function createSkinTemplate(
		string $skinName,
		UserIdentity $user,
		?OutputPage $output = null
	): SkinTemplate {
		$message = $this->createMock( Message::class );
		$message->method( 'text' )->willReturn( 'Save' );

		$skin = $this->createMock( SkinTemplate::class );
		$skin->method( 'getSkinName' )->willReturn( $skinName );
		$skin->method( 'getUser' )->willReturn( $user );
		$skin->method( 'msg' )->willReturn( $message );
		if ( $output !== null ) {
			$skin->method( 'getOutput' )->willReturn( $output );
		}
		return $skin;
	}

	private function getLinks(): array {
		return [
			'user-menu' => [],
			'views' => [],
			'actions' => [],
		];
	}

	/**
	 * @dataProvider provideIsSkinSupported
	 */
	public function testIsSkinSupported( string $skinName, bool $expected ) {
		$this->assertSame( $expected, HookHandler::isSkinSupported( $skinName ) );
	}

	public static function provideIsSkinSupported(): array {
		return [
			'vector-2022 is supported' => [ 'vector-2022', true ],
			'minerva is supported' => [ 'minerva', true ],
			'cologneblue is not supported' => [ 'cologneblue', false ],
			'modern is not supported' => [ 'modern', false ],
			'monobook is not supported' => [ 'monobook', false ],
			'timeless is not supported' => [ 'timeless', false ],
			'vector (legacy) is not supported' => [ 'vector', false ],
			'empty string is not supported' => [ '', false ],
		];
	}

	public function testBookmarkIconButtonAddedForMainNamespacePageWithCTA(): void {
		$config = new HashConfig( [
			'ReadingListsEnabled' => false,
			'ReadingListBetaFeature' => false,
			'ReadingListsMinervaCTA' => true,
		] );
		$user = $this->createMock( UserIdentity::class );
		$user->method( 'isRegistered' )->willReturn( false );
		$userIdentityUtils = $this->createMock( UserIdentityUtils::class );
		$userIdentityUtils->method( 'isTemp' )->willReturn( false );

		$title = $this->createMock( Title::class );
		$title->method( 'getNamespace' )->willReturn( NS_MAIN );
		$output = $this->createMock( OutputPage::class );
		$output->method( 'isArticle' )->willReturn( true );
		$output->method( 'getTitle' )->willReturn( $title );
		$output->expects( $this->once() )
			->method( 'addModules' )
			->with( 'ext.readingLists.bookmark.anonymous' );

		$skin = $this->createSkinTemplate( 'minerva', $user, $output );
		$links = $this->getLinks();
		$this->createHookHandler( $config, null, $userIdentityUtils )
			->onSkinTemplateNavigation__Universal( $skin, $links );

		$this->assertArrayNotHasKey( 'readinglists', $links['user-menu'] );
		$this->assertArrayHasKey( 'bookmark', $links['views'] );
	}

	public function testBookmarkIconButtonNotAddedForMainNamespacePageWithCTAInVector(): void {
		$config = new HashConfig( [
			'ReadingListsEnabled' => false,
			'ReadingListBetaFeature' => false,
			'ReadingListsMinervaCTA' => true,
		] );
		$user = $this->createMock( UserIdentity::class );
		$user->method( 'isRegistered' )->willReturn( false );
		$userIdentityUtils = $this->createMock( UserIdentityUtils::class );
		$userIdentityUtils->method( 'isTemp' )->willReturn( false );

		$skin = $this->createSkinTemplate( 'vector-2022', $user );
		$links = $this->getLinks();
		$this->createHookHandler( $config, null, $userIdentityUtils )
			->onSkinTemplateNavigation__Universal( $skin, $links );

		$this->assertArrayNotHasKey( 'readinglists', $links['user-menu'] );
		$this->assertArrayNotHasKey( 'bookmark', $links['views'] );
	}

	public function testBookmarkNotAddedForUnsupportedSkin(): void {
		$skin = $this->createMock( SkinTemplate::class );
		$skin->method( 'getSkinName' )->willReturn( 'monobook' );
		$skin->expects( $this->never() )->method( 'getUser' );

		$links = $this->getLinks();
		$this->createHookHandler()->onSkinTemplateNavigation__Universal( $skin, $links );

		$this->assertArrayNotHasKey( 'readinglists', $links['user-menu'] );
		$this->assertArrayNotHasKey( 'bookmark', $links['views'] );
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
		$hookHandler = $this->createHookHandler( null, $userOptionsManager );

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
