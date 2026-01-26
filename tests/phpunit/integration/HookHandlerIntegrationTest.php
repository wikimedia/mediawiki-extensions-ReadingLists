<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Integration;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\ReadingLists\Constants;
use MediaWiki\Extension\ReadingLists\HookHandler;
use MediaWiki\Extension\ReadingLists\ReadingListRepository;
use MediaWiki\Extension\ReadingLists\ReadingListRepositoryFactory;
use MediaWiki\Extension\TestKitchen\Sdk\Experiment;
use MediaWiki\Extension\TestKitchen\Sdk\ExperimentManager;
use MediaWiki\Output\OutputPage;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Skin\SkinTemplate;
use MediaWiki\Title\Title;
use MediaWiki\User\CentralId\CentralIdLookup;
use MediaWiki\User\CentralId\CentralIdLookupFactory;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;
use PHPUnit\Framework\MockObject\MockObject;
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

		/** @var ReadingListRepositoryFactory&MockObject $mockFactory */
		$mockFactory = $this->createMock( ReadingListRepositoryFactory::class );
		$mockFactory->method( 'create' )->willReturn( $this->createMockRepository() );

		$mockCentralIdLookup = $this->createMock( CentralIdLookup::class );
		$mockCentralIdLookup->method( 'centralIdFromLocalUser' )->willReturn( 1 );

		/** @var CentralIdLookupFactory&MockObject $mockCentralIdLookupFactory */
		$mockCentralIdLookupFactory = $this->createMock( CentralIdLookupFactory::class );
		$mockCentralIdLookupFactory->method( 'getLookup' )->willReturn( $mockCentralIdLookup );

		$this->hookHandler = new HookHandler(
			$services->getMainConfig(),
			$mockFactory,
			$services->getUserOptionsLookup(),
			$mockCentralIdLookupFactory
		);
	}

	private function setupExperiment( $inAssignedGroup = true ) {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'TestKitchen' ) ) {
			$this->markTestSkipped( 'Test requires the TestKitchen extension' );
		}

		$services = $this->getServiceContainer();

		$userOptionsManager = $services->getUserOptionsManager();
		$userOptionsManager->setOption( $this->user, 'readinglists-web-ui-enabled', '1' );
		$userOptionsManager->saveOptions( $this->user );

		$mockExperiment = $this->createMock( Experiment::class );
		$mockExperiment->method( 'isAssignedGroup' )->with( 'treatment' )->willReturn( true );

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

		$this->assertArrayHasKey( 'readinglists', $links['user-menu'] );
		$this->assertArrayHasKey( 'bookmark', $links['views'] );
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

	public function testBookmarkNotAddedForUnsupportedSkin() {
		$this->setupExperiment();

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
