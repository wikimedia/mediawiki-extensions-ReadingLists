<?php

namespace MediaWiki\Extension\ReadingLists;

use MediaWiki\Api\ApiQuerySiteinfo;
use MediaWiki\Api\Hook\APIQuerySiteInfoGeneralInfoHook;
use MediaWiki\Config\Config;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\BetaFeatures\BetaFeatures;
use MediaWiki\Extension\TestKitchen\Sdk\ExperimentManager;
use MediaWiki\Hook\SkinTemplateNavigation__UniversalHook;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Skin\SkinTemplate;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\CentralId\CentralIdLookupFactory;
use MediaWiki\User\Options\UserOptionsLookup;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;
use MediaWiki\WikiMap\WikiMap;

/**
 * Static entry points for hooks.
 */
class HookHandler implements APIQuerySiteInfoGeneralInfoHook, SkinTemplateNavigation__UniversalHook {

	public function __construct(
		private readonly Config $config,
		private readonly ReadingListRepositoryFactory $readingListRepositoryFactory,
		private readonly UserOptionsLookup $userOptionsLookup,
		private readonly CentralIdLookupFactory $centralIdLookupFactory,
		private ?ExperimentManager $experimentManager = null
	) {
	}

	public function setExperimentManager( ExperimentManager $experimentManager ): void {
		$this->experimentManager = $experimentManager;
	}

	/**
	 * Adds a hidden preference, accessed via api. The preference indicates user eligibility
	 * for showing the ReadingLists bookmark icon button in supported skins.
	 *
	 * @param User $user User whose preferences are being modified.
	 * @param array[] &$preferences Preferences description array, to be fed to a HTMLForm object.
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public function onGetPreferences( $user, &$preferences ) {
		$preferences += [
			'readinglists-web-ui-enabled' => [
				'type' => 'api',
			],
		];
	}

	/**
	 * Handler for SkinTemplateNavigation::Universal hook.
	 * Adds "Notifications" items to the notifications content navigation.
	 * SkinTemplate automatically merges these into the personal tools for older skins.
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/SkinTemplateNavigation::Universal
	 * @param SkinTemplate $sktemplate
	 * @param array &$links Array of URLs to append to.
	 * @throws ReadingListRepositoryException
	 */
	public function onSkinTemplateNavigation__Universal( $sktemplate, &$links ): void {
		if ( !self::isSkinSupported( $sktemplate->getSkinName() ) ) {
			return;
		}

		$user = $sktemplate->getUser();

		if ( !$this->isReadingListsEnabledForUser( $user ) ) {
			return;
		}

		$centralId = $this->centralIdLookupFactory->getLookup()
			->centralIdFromLocalUser( $user );

		if ( !$centralId ) {
			return;
		}

		$this->addSpecialPageLinkToUserMenu( $user, $sktemplate, $links );

		$repository = $this->readingListRepositoryFactory->create( $centralId );
		$defaultListId = $repository->getDefaultListIdForUser() ?: null;

		if ( $defaultListId === null ) {
			DeferredUpdates::addCallableUpdate(
				static function () use ( $repository ) {
					$repository->setupForUser( true );
				},
				DeferredUpdates::POSTSEND
			);
		}

		$output = $sktemplate->getOutput();
		$output->addModuleStyles( 'ext.readingLists.bookmark.icons' );

		if ( !$output->isArticle() ) {
			return;
		}

		// NOTE: Non-existent pages still have a Title object.
		// It should be rare that the Title is null here, but we should still check.
		$title = $output->getTitle();
		if ( !$title || $title->getNamespace() !== NS_MAIN ) {
			return;
		}

		$list = null;
		$entry = false;

		if ( $defaultListId !== null ) {
			$list = $repository->selectValidList( $defaultListId );
			$entry = $repository->getListsByPage(
				'@local',
				$title->getPrefixedDBkey(),
				1
			)->fetchObject();
		}

		// If the list id is null, then list setup occurs async in bookmark.js.
		// When a user saves their first page, these attributes are updated accordingly
		// after list setup.
		$links['views']['bookmark'] = [
			'text' => $sktemplate->msg(
				'readinglists-' . ( $entry === false ? 'add' : 'remove' ) . '-bookmark'
			)->text(),
			'icon' => $entry === false ? 'bookmarkOutline' : 'bookmark',
			'href' => '#',
			'data-mw-list-id' => $list ? $list->rl_id : null,
			'data-mw-entry-id' => $entry === false ? null : $entry->rle_id,
			'data-mw-list-page-count' => $list ? $list->rl_size : 0,
			'link-class' => 'reading-lists-bookmark'
		];

		$output->addModules( 'ext.readingLists.bookmark' );
	}

	private function addSpecialPageLinkToUserMenu(
		UserIdentity $user,
		SkinTemplate $sktemplate,
		array &$links
	): void {
		$userMenu = $links['user-menu'] ?? [];

		// Insert readinglists after 'mytalk', or after 'sandbox' if present.
		// Reference: T413413.
		$insertAfter = 'mytalk';
		if ( isset( $userMenu['sandbox'] ) ) {
			$insertAfter = 'sandbox';
		}

		$userName = $user->getName();
		$specialPageUrl = SpecialPage::getTitleFor( 'ReadingLists', $userName )->getLinkURL();

		$links['user-menu'] = wfArrayInsertAfter( $userMenu, [
			'readinglists' => [
				'text' => $sktemplate->msg( 'readinglists-menu-item' )->text(),
				'href' => $specialPageUrl,
				'icon' => 'bookmarkList',
			],
		], $insertAfter );
	}

	private function isReadingListsEnabledForUser( UserIdentity $user ): bool {
		$betaFeatureEnabled = $this->config->get( 'ReadingListBetaFeature' ) &&
			ExtensionRegistry::getInstance()->isLoaded( 'BetaFeatures' ) &&
			BetaFeatures::isFeatureEnabled( $user, Constants::PREF_KEY_BETA_FEATURES );

		$hiddenPreferenceEnabled = $this->userOptionsLookup->getOption(
			$user,
			Constants::PREF_KEY_WEB_UI_ENABLED
		) === '1';

		$inExperimentTreatment = false;
		if ( $this->experimentManager ) {
			$wikiId = WikiMap::getCurrentWikiId();
			// NOTE: These need to be the same as the experiment names
			// defined in WikimediaEvents, in readingListAB.js.
			$experimentName = $wikiId === 'enwiki'
				? 'we-3-3-4-reading-list-test1-en'
				: 'we-3-3-4-reading-list-test1';
			$experiment = $this->experimentManager->getExperiment( $experimentName );
			$inExperimentTreatment = $experiment->isAssignedGroup( 'treatment' );
		}

		return $betaFeatureEnabled || ( $hiddenPreferenceEnabled && $inExperimentTreatment );
	}

	/**
	 * Show the reading list and bookmark if the skin is Vector 2022 or Minerva.
	 * @see https://phabricator.wikimedia.org/T395332
	 * @param string $skinName
	 * @return bool
	 */
	public static function isSkinSupported( $skinName ) {
		return $skinName === 'vector-2022' || $skinName === 'minerva';
	}

	/**
	 * Add configuration data to the siteinfo API output.
	 * Used by the RESTBase proxy for help messages in the Swagger doc.
	 * @param ApiQuerySiteinfo $module
	 * @param array &$result
	 */
	public function onAPIQuerySiteInfoGeneralInfo( $module, &$result ) {
		$result['readinglists-config'] = [
			'maxListsPerUser' => $this->config->get( 'ReadingListsMaxListsPerUser' ),
			'maxEntriesPerList' => $this->config->get( 'ReadingListsMaxEntriesPerList' ),
			'deletedRetentionDays' => $this->config->get( 'ReadingListsDeletedRetentionDays' ),
		];
	}
}
