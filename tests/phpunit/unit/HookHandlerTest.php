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
		?UserIdentityUtils $userIdentityUtils = null
	): HookHandler {
		return new HookHandler(
			$config ?? $this->createMock( Config::class ),
			$this->createMock( BookmarkEntryLookupService::class ),
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
		$title->method( 'isContentPage' )->willReturn( true );
		$output = $this->createMock( OutputPage::class );
		$output->method( 'isArticle' )->willReturn( true );
		$output->method( 'getTitle' )->willReturn( $title );
		$output->expects( $this->once() )
			->method( 'addModules' )
			->with( 'ext.readingLists.bookmark.anonymous' );

		$skin = $this->createSkinTemplate( 'minerva', $user, $output );
		$links = $this->getLinks();
		$this->createHookHandler( $config, $userIdentityUtils )
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
		$this->createHookHandler( $config, $userIdentityUtils )
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
}
