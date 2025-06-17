<?php

namespace MediaWiki\Extension\ReadingLists;

use MediaWiki\Api\ApiQuerySiteinfo;
use MediaWiki\Api\Hook\APIQuerySiteInfoGeneralInfoHook;
use MediaWiki\Config\Config;
use MediaWiki\Extension\BetaFeatures\BetaFeatures;
use MediaWiki\Hook\SkinTemplateNavigation__UniversalHook;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Skin\SkinTemplate;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\CentralId\CentralIdLookupFactory;
use MediaWiki\User\UserEditTracker;
use MediaWiki\User\UserIdentity;
use Wikimedia\Rdbms\LBFactory;

/**
 * Static entry points for hooks.
 */
class HookHandler implements APIQuerySiteInfoGeneralInfoHook, SkinTemplateNavigation__UniversalHook {
	private CentralIdLookupFactory $centralIdLookupFactory;
	private Config $config;
	private LBFactory $dbProvider;
	private UserEditTracker $userEditTracker;

	public function __construct(
		CentralIdLookupFactory $centralIdLookupFactory,
		Config $config,
		LBFactory $dbProvider,
		UserEditTracker $userEditTracker
	) {
		$this->centralIdLookupFactory = $centralIdLookupFactory;
		$this->config = $config;
		$this->dbProvider = $dbProvider;
		$this->userEditTracker = $userEditTracker;
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

		if (
			!$this->config->get( 'ReadingListBetaFeature' ) ||
			!ExtensionRegistry::getInstance()->isLoaded( 'BetaFeatures' ) ||
			!BetaFeatures::isFeatureEnabled( $user, Constants::PREF_KEY_BETA_FEATURES )
		) {
			return;
		}

		$links['user-menu'] = wfArrayInsertAfter( $links['user-menu'], [
			'readinglists' => [
				'text' => $sktemplate->msg( 'readinglists-menu-item' )->text(),
				'href' => SpecialPage::getTitleFor( 'ReadingLists' )->getLinkURL(),
				'icon' => 'bookmark'
			],
		], 'watchlist' );

		$this->hideWatchlistIcon( $sktemplate, $user, $links );

		$output = $sktemplate->getOutput();
		$output->addModules( 'ext.readingLists.bookmark.icons' );

		if ( !$output->isArticle() ) {
			return;
		}

		$repository = new ReadingListRepository(
			$this->centralIdLookupFactory->getLookup()
				->centralIdFromLocalUser( $user ),
			$this->dbProvider
		);

		$list = $repository->setupForUser( true );
		$entry = $repository->getListsByPage(
			'@local',
			$output->getTitle()->getPrefixedDBkey(),
			1
		)->fetchObject();

		$links['views']['bookmark'] = [
			'text' => $sktemplate->msg(
				'readinglists-' . ( $entry === false ? 'add' : 'remove' ) . '-bookmark'
			)->text(),
			'icon' => $entry === false ? 'bookmarkOutline' : 'bookmark',
			'href' => '#',
			'data-mw-list-id' => $list->rl_id,
			'data-mw-entry-id' => $entry === false ? null : $entry->rle_id
		];

		$output->addModules( 'ext.readingLists.bookmark' );

		self::hideWatchIcon( $sktemplate, $links );
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
	 * Hide the watchlist link on mobile if the user has no edits and their watchlist is empty.
	 * @see https://phabricator.wikimedia.org/T394562
	 * @param SkinTemplate $sktemplate
	 * @param UserIdentity $user
	 * @param array &$links
	 */
	public function hideWatchlistIcon( $sktemplate, $user, &$links ) {
		if (
			$sktemplate->getSkinName() === 'minerva' &&
			$this->userEditTracker->getUserEditCount( $user ) === 0
		) {
			unset( $links['user-menu']['watchlist'] );
		}
	}

	/**
	 * Hide the watch star if the current skin is Vector 2022 or Minerva.
	 * @see https://phabricator.wikimedia.org/T394562
	 * @param SkinTemplate $sktemplate
	 * @param array &$links
	 */
	public static function hideWatchIcon( $sktemplate, &$links ) {
		$skinName = $sktemplate->getSkinName();

		if ( $skinName === 'vector-2022' || $skinName === 'minerva' ) {
			unset( $links['actions']['watch'] );
			unset( $links['actions']['unwatch'] );
		}
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
