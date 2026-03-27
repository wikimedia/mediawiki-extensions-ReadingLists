<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Integration;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\ReadingLists\Constants;
use MediaWiki\Extension\ReadingLists\HookHandler;
use MediaWiki\Extension\ReadingLists\ReadingListRepository;
use MediaWiki\Extension\ReadingLists\ReadingListRepositoryFactory;
use MediaWiki\Extension\ReadingLists\Service\BookmarkBloomFilterCache;
use MediaWiki\Extension\ReadingLists\Service\BookmarkEntryLookupService;
use MediaWiki\Extension\TestKitchen\Sdk\Experiment;
use MediaWiki\Extension\TestKitchen\Sdk\ExperimentManager;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\Output\OutputPage;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Skin\SkinTemplate;
use MediaWiki\Tests\User\TempUser\TempUserTestTrait;
use MediaWiki\Title\Title;
use MediaWiki\User\CentralId\CentralIdLookup;
use MediaWiki\User\CentralId\CentralIdLookupFactory;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\FakeResultWrapper;

/**
 * @group Database
 * @covers \MediaWiki\Extension\ReadingLists\HookHandler
 */
class HookHandlerIntegrationTest extends MediaWikiIntegrationTestCase {
	use TempUserTestTrait;

	private User $user;
	private HookHandler $hookHandler;

	protected function setUp(): void {
		parent::setUp();

		$this->setUserLang( 'en' );

		$this->user = $this->getTestUser()->getUser();

		$services = $this->getServiceContainer();

		$userOptionsManager = $services->getUserOptionsManager();
		$userOptionsManager->setOption( $this->user, 'readinglists-web-ui-enabled', '1' );
		$userOptionsManager->saveOptions( $this->user );
		$this->hookHandler = $this->createHookHandler();
	}

	private function setupExperiment( $inAssignedGroup = true ) {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'TestKitchen' ) ) {
			$this->markTestSkipped( 'Test requires the TestKitchen extension' );
		}

		$this->overrideConfigValue( 'ReadingListBetaFeature', false );

		$services = $this->getServiceContainer();

		$userOptionsManager = $services->getUserOptionsManager();
		$userOptionsManager->setOption( $this->user, 'readinglists-web-ui-enabled', '1' );
		$userOptionsManager->saveOptions( $this->user );

		$mockExperiment = $this->createMock( Experiment::class );
		$mockExperiment->method( 'isAssignedGroup' )->with( 'treatment' )->willReturn( $inAssignedGroup );

		/** @var MockObject|ExperimentManager $mockExperimentManager */
		$mockExperimentManager = $this->createMock( ExperimentManager::class );
		$mockExperimentManager->method( 'getExperiment' )->willReturn( $mockExperiment );

		$this->hookHandler->setExperimentManager( $mockExperimentManager );
	}

	private function setupBetaFeature() {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'BetaFeatures' ) ) {
			$this->markTestSkipped( 'Test requires the BetaFeatures extension' );
		}

		$this->overrideConfigValue( 'ReadingListBetaFeature', true );

		$services = $this->getServiceContainer();
		$userOptionsManager = $services->getUserOptionsManager();
		$userOptionsManager->setOption( $this->user, Constants::PREF_KEY_BETA_FEATURES, '1' );
		$userOptionsManager->saveOptions( $this->user );
	}

	private function createHookHandler( ?ReadingListRepository $mockRepository = null ): HookHandler {
		$services = $this->getServiceContainer();

		/** @var ReadingListRepositoryFactory&MockObject $mockFactory */
		$mockFactory = $this->createMock( ReadingListRepositoryFactory::class );
		$mockFactory->method( 'create' )->willReturn( $mockRepository ?? $this->createMockRepository() );

		$mockCentralIdLookup = $this->createMock( CentralIdLookup::class );
		$mockCentralIdLookup->method( 'centralIdFromLocalUser' )->willReturn( 1 );

		/** @var CentralIdLookupFactory&MockObject $mockCentralIdLookupFactory */
		$mockCentralIdLookupFactory = $this->createMock( CentralIdLookupFactory::class );
		$mockCentralIdLookupFactory->method( 'getLookup' )->willReturn( $mockCentralIdLookup );

		return new HookHandler(
			$services->getMainConfig(),
			$mockFactory,
			$this->createBookmarkEntryLookupService( $mockFactory, $mockCentralIdLookupFactory ),
			$services->getUserOptionsLookup(),
			$mockCentralIdLookupFactory,
			$services->getUserIdentityUtils()
		);
	}

	private function createBookmarkEntryLookupService(
		ReadingListRepositoryFactory $mockFactory,
		CentralIdLookupFactory $mockCentralIdLookupFactory
	): BookmarkEntryLookupService {
		return new BookmarkEntryLookupService(
			$mockFactory,
			$mockCentralIdLookupFactory,
			$this->createMock( JobQueueGroup::class ),
			new BookmarkBloomFilterCache(
				$mockFactory,
				new WANObjectCache( [ 'cache' => new HashBagOStuff() ] ),
				new NullLogger(),
				10000
			),
			new NullLogger()
		);
	}

	private function createMockRepository( array $pageLists = [] ) {
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
		$mockRepository->method( 'selectValidList' )->willReturn( $mockList );
		$mockRepository->method( 'getListsByPage' )->willReturn( new FakeResultWrapper( $pageLists ) );
		$mockRepository->method( 'getSavedPageTitlesForProject' )->willReturn( [] );

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

	public function testBookmarkIconButtonAddedForMainNamespacePageWithExperiment() {
		$this->setupExperiment();

		$title = Title::makeTitle( NS_MAIN, 'TestPage' );
		$skin = $this->createSkinTemplate( $title );

		$links = $this->getLinks();

		$this->hookHandler->onSkinTemplateNavigation__Universal( $skin, $links );

		$this->assertArrayHasKey( 'readinglists', $links['user-menu'] );
		$this->assertArrayHasKey( 'bookmark', $links['views'] );
	}

	public function testBookmarkIconButtonNotAddedForMainNamespacePageWithUserNotInExperiment() {
		$this->setupExperiment( false );

		$title = Title::makeTitle( NS_MAIN, 'TestPage' );
		$skin = $this->createSkinTemplate( $title );

		$links = $this->getLinks();

		$this->hookHandler->onSkinTemplateNavigation__Universal( $skin, $links );

		$this->assertArrayNotHasKey( 'readinglists', $links['user-menu'] );
		$this->assertArrayNotHasKey( 'bookmark', $links['views'] );
	}

	public function testBookmarkIconButtonAddedForMainNamespacePageWithBetaFeature() {
		$this->setupBetaFeature();

		$title = Title::makeTitle( NS_MAIN, 'TestPage' );
		$skin = $this->createSkinTemplate( $title );

		$links = $this->getLinks();

		$this->hookHandler->onSkinTemplateNavigation__Universal( $skin, $links );

		$this->assertArrayHasKey( 'readinglists', $links['user-menu'] );
		$this->assertArrayHasKey( 'bookmark', $links['views'] );
	}

	public function testBookmarkIncludesInCustomListDataAttribute() {
		$this->hookHandler = $this->createHookHandler(
			$this->createMockRepository( [
				(object)[
					'rl_id' => 1,
					'rl_is_default' => 1,
					'rle_id' => 10,
				],
				(object)[
					'rl_id' => 2,
					'rl_is_default' => 0,
					'rle_id' => 11,
				],
			] )
		);
		$this->setupExperiment();

		$title = Title::makeTitle( NS_MAIN, 'TestPage' );
		$skin = $this->createSkinTemplate( $title );

		$links = $this->getLinks();

		$this->hookHandler->onSkinTemplateNavigation__Universal( $skin, $links );

		$this->assertSame( 10, $links['views']['bookmark']['data-mw-entry-id'] );
		$this->assertSame( 1, $links['views']['bookmark']['data-mw-in-custom-list'] );
	}

	public function testBookmarkIconButtonNotAddedForTalkPageWithExperiment() {
		$this->setupExperiment();

		$title = Title::makeTitle( NS_TALK, 'TestPage' );
		$skin = $this->createSkinTemplate( $title );

		$links = $this->getLinks();

		$this->hookHandler->onSkinTemplateNavigation__Universal( $skin, $links );

		$this->assertArrayHasKey( 'readinglists', $links['user-menu'] );
		$this->assertArrayNotHasKey( 'bookmark', $links['views'] );
	}

	public function testBookmarkIconButtonNotAddedForTalkPageWithBetaFeatureEnabled() {
		$this->setupBetaFeature();

		$title = Title::makeTitle( NS_TALK, 'TestPage' );
		$skin = $this->createSkinTemplate( $title );

		$links = $this->getLinks();

		$this->hookHandler->onSkinTemplateNavigation__Universal( $skin, $links );

		$this->assertArrayHasKey( 'readinglists', $links['user-menu'] );
		$this->assertArrayNotHasKey( 'bookmark', $links['views'] );
	}

	public function testBookmarkNotAddedForCategoryPageWithExperiment() {
		$this->setupExperiment();

		$title = Title::makeTitle( NS_CATEGORY, 'TestCategory' );
		$skin = $this->createSkinTemplate( $title );

		$links = $this->getLinks();

		$this->hookHandler->onSkinTemplateNavigation__Universal( $skin, $links );

		$this->assertArrayHasKey( 'readinglists', $links['user-menu'] );
		$this->assertArrayNotHasKey( 'bookmark', $links['views'] );
	}

	public function testBookmarkNotAddedForCategoryPageWithBetaFeatureEnabled() {
		$this->setupBetaFeature();

		$title = Title::makeTitle( NS_CATEGORY, 'TestCategory' );
		$skin = $this->createSkinTemplate( $title );

		$links = $this->getLinks();

		$this->hookHandler->onSkinTemplateNavigation__Universal( $skin, $links );

		$this->assertArrayHasKey( 'readinglists', $links['user-menu'] );
		$this->assertArrayNotHasKey( 'bookmark', $links['views'] );
	}

	public function testDisabledBetaFeatureRespectedEvenWithHiddenPreference() {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'BetaFeatures' ) ) {
			$this->markTestSkipped( 'Test requires the BetaFeatures extension' );
		}

		$this->overrideConfigValue( 'ReadingListBetaFeature', true );

		$services = $this->getServiceContainer();
		$userOptionsManager = $services->getUserOptionsManager();
		$userOptionsManager->setOption( $this->user, Constants::PREF_KEY_WEB_UI_ENABLED, '1' );
		$userOptionsManager->setOption( $this->user, Constants::PREF_KEY_BETA_FEATURES, '0' );
		$userOptionsManager->saveOptions( $this->user );

		$title = Title::makeTitle( NS_MAIN, 'TestPage' );
		$skin = $this->createSkinTemplate( $title );

		$links = $this->getLinks();

		$this->hookHandler->onSkinTemplateNavigation__Universal( $skin, $links );

		$this->assertArrayNotHasKey( 'readinglists', $links['user-menu'] );
		$this->assertArrayNotHasKey( 'bookmark', $links['views'] );
	}

	public function testBookmarkNotAddedForUnsupportedSkin() {
		$this->setupExperiment();

		$title = Title::makeTitle( NS_MAIN, 'TestPage' );
		$skin = $this->createSkinTemplate( $title, true, 'monobook' );

		$links = $this->getLinks();

		$this->hookHandler->onSkinTemplateNavigation__Universal( $skin, $links );

		$this->assertArrayNotHasKey( 'readinglists', $links['user-menu'] );
		$this->assertArrayNotHasKey( 'bookmark', $links['views'] );
	}

	public function testBookmarkNotAddedForTempUser() {
		$this->enableAutoCreateTempUser();
		$tempUser = $this->getServiceContainer()->getTempUserCreator()
			->create( null, new FauxRequest() )
			->getUser();

		$skin = $this->createMock( SkinTemplate::class );
		$skin->method( 'getSkinName' )->willReturn( 'vector-2022' );
		$skin->method( 'getUser' )->willReturn( $tempUser );

		$links = $this->getLinks();

		$this->hookHandler->onSkinTemplateNavigation__Universal( $skin, $links );

		$this->assertArrayNotHasKey( 'readinglists', $links['user-menu'] );
		$this->assertArrayNotHasKey( 'bookmark', $links['views'] );
	}

	public function testReadingListsSpecialPageLinkAddedToUserMenuAfterSandboxLink() {
		$this->setupBetaFeature();

		$title = Title::makeTitle( NS_MAIN, 'TestPage' );
		$skin = $this->createSkinTemplate( $title );

		$links = $this->getLinks();

		$this->hookHandler->onSkinTemplateNavigation__Universal( $skin, $links );

		$userMenu = $links['user-menu'];
		$this->assertArrayHasKey( 'readinglists', $userMenu, 'Reading Lists link not found in user menu' );

		// assert that the reading lists link is after the sandbox link
		$sandboxIndex = array_search( 'sandbox', array_keys( $userMenu ) );
		$readingListsIndex = array_search( 'readinglists', array_keys( $userMenu ) );

		$expectedReadingListsIndex = $sandboxIndex + 1;
		$this->assertSame(
			$expectedReadingListsIndex,
			$readingListsIndex,
			'Reading Lists link should be immediately after Sandbox link in user menu'
		);

		$readingListsLink = $userMenu['readinglists'];

		$this->assertSame( 'Saved pages', $readingListsLink['text'] );
		$this->assertSame( 'bookmarkList', $readingListsLink['icon'] );
		$this->assertStringEndsWith(
			'Special:ReadingLists/' . strtr( $this->user->getName(), ' ', '_' ),
			$readingListsLink['href'],
			'URL should be like Special:ReadingLists/{user_name}'
		);
	}

	private function getLinks() {
		return [
			'user-menu' => [
				'userpage' => [ 'text' => 'TestUser', 'href' => '/wiki/User:TestUser' ],
				'mytalk' => [ 'text' => 'Talk', 'href' => '/wiki/User_talk:TestUser' ],
				'sandbox' => [ 'text' => 'Sandbox', 'href' => '/wiki/User:TestUser/sandbox' ],
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
