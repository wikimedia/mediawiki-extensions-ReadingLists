<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Integration;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\ReadingLists\HookHandler;
use MediaWiki\Extension\ReadingLists\ReadingListRepository;
use MediaWiki\Extension\ReadingLists\ReadingListRepositoryFactory;
use MediaWiki\Output\OutputPage;
use MediaWiki\Skin\SkinTemplate;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;
use Wikimedia\Rdbms\FakeResultWrapper;

/**
 * @group Database
 * @covers \MediaWiki\Extension\ReadingLists\HookHandler
 */
class HookHandlerIntegrationTest extends MediaWikiIntegrationTestCase {

	private User $user;
	private HookHandler $hookHandler;

	protected function setUp(): void {
		parent::setUp();

		$this->user = $this->getTestUser()->getUser();

		$services = $this->getServiceContainer();

		$userOptionsManager = $services->getUserOptionsManager();
		$userOptionsManager->setOption( $this->user, 'readinglists-web-ui-enabled', '1' );
		$userOptionsManager->saveOptions( $this->user );

		$mockRepository = $this->createMockRepository();
		$mockFactory = $this->createMock( ReadingListRepositoryFactory::class );
		$mockFactory->method( 'getInstanceForUser' )->willReturn( $mockRepository );

		/** @var ReadingListRepositoryFactory $mockFactory */
		$this->hookHandler = new HookHandler(
			$services->getMainConfig(),
			$mockFactory,
			$services->getUserOptionsLookup()
		);
	}

	private function createMockRepository() {
		$mockList = (object)[
			'rl_id' => 1,
			'rl_is_default' => 1,
			'rl_user_id' => $this->user->getId(),
			'rl_name' => 'default',
			'rl_description' => '',
			'rl_date_created' => '20251001000000',
			'rl_date_updated' => '20251001000000',
			'rl_deleted' => 0,
			'rl_size' => 0,
		];

		$mockRepository = $this->createMock( ReadingListRepository::class );
		$mockRepository->method( 'setupForUser' )->willReturn( $mockList );
		$mockRepository->method( 'getDefaultListIdForUser' )->willReturn( 1 );
		$mockRepository->method( 'getListsByPage' )->willReturn( new FakeResultWrapper( [] ) );

		return $mockRepository;
	}

	private function createSkinTemplate( Title $title, bool $isArticle = true, string $skinName = 'vector-2022' ) {
		$context = new RequestContext();
		$context->setTitle( $title );
		$context->setUser( $this->user );

		$output = new OutputPage( $context );
		$output->setTitle( $title );
		if ( $isArticle ) {
			$output->setArticleFlag( true );
		}

		$skin = $this->createMock( SkinTemplate::class );
		$skin->method( 'getSkinName' )->willReturn( $skinName );
		$skin->method( 'getUser' )->willReturn( $this->user );
		$skin->method( 'getOutput' )->willReturn( $output );
		$skin->method( 'getTitle' )->willReturn( $title );
		$skin->method( 'msg' )->willReturnCallback( static function ( $key ) use ( $context ) {
			return $context->msg( $key );
		} );

		return $skin;
	}

	public function testBookmarkIconButtonAddedForMainNamespacePage() {
		$title = Title::makeTitle( NS_MAIN, 'TestPage' );
		$skin = $this->createSkinTemplate( $title );

		$links = $this->getLinks();

		$this->hookHandler->onSkinTemplateNavigation__Universal( $skin, $links );

		$this->assertArrayHasKey( 'readinglists', $links['user-menu'] );
		$this->assertArrayHasKey( 'bookmark', $links['views'] );
	}

	public function testBookmarkIconButtonNotAddedForTalkPage() {
		$title = Title::makeTitle( NS_TALK, 'TestPage' );
		$skin = $this->createSkinTemplate( $title );

		$links = $this->getLinks();

		$this->hookHandler->onSkinTemplateNavigation__Universal( $skin, $links );

		$this->assertArrayHasKey( 'readinglists', $links['user-menu'] );
		$this->assertArrayNotHasKey( 'bookmark', $links['views'] );
	}

	public function testBookmarkNotAddedForCategoryPage() {
		$title = Title::makeTitle( NS_CATEGORY, 'TestCategory' );
		$skin = $this->createSkinTemplate( $title );

		$links = $this->getLinks();

		$this->hookHandler->onSkinTemplateNavigation__Universal( $skin, $links );

		$this->assertArrayHasKey( 'readinglists', $links['user-menu'] );
		$this->assertArrayNotHasKey( 'bookmark', $links['views'] );
	}

	public function testBookmarkNotAddedForUnsupportedSkin() {
		$title = Title::makeTitle( NS_MAIN, 'TestPage' );
		$skin = $this->createSkinTemplate( $title, true, 'monobook' );

		$links = $this->getLinks();

		$this->hookHandler->onSkinTemplateNavigation__Universal( $skin, $links );

		$this->assertArrayNotHasKey( 'readinglists', $links['user-menu'] );
		$this->assertArrayNotHasKey( 'bookmark', $links['views'] );
	}

	private function getLinks() {
		return [
			'user-menu' => [
				'userpage' => [ 'text' => 'TestUser', 'href' => '/wiki/User:TestUser' ],
				'mytalk' => [ 'text' => 'Talk', 'href' => '/wiki/User_talk:TestUser' ],
				'preferences' => [ 'text' => 'Preferences', 'href' => '/wiki/Special:Preferences' ],
				'watchlist' => [ 'text' => 'Watchlist', 'href' => '/wiki/Special:Watchlist' ],
			],
			'views' => [
				'view' => [ 'text' => 'Read', 'href' => '/wiki/TestPage' ],
				'edit' => [ 'text' => 'Edit', 'href' => '/w/index.php?title=TestPage&action=edit' ],
				'history' => [ 'text' => 'View history', 'href' => '/w/index.php?title=TestPage&action=history' ],
			],
			'actions' => [],
		];
	}
}
