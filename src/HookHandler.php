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
use MediaWiki\User\UserIdentity;

/**
 * Static entry points for hooks.
 */
class HookHandler implements APIQuerySiteInfoGeneralInfoHook, SkinTemplateNavigation__UniversalHook {
	public function __construct(
		private readonly Config $config,
		private readonly ReadingListRepositoryFactory $readingListRepositoryFactory,
	) {
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

		$repository = $this->readingListRepositoryFactory->getInstanceForUser( $user );

		$links['user-menu'] = wfArrayInsertAfter( $links['user-menu'], [
			'readinglists' => [
				'text' => $sktemplate->msg( 'readinglists-menu-item' )->text(),
				'href' => self::getDefaultReadingListUrl( $user, $repository ),
				'icon' => 'bookmarkList',
			],
		], 'mytalk' );

		$output = $sktemplate->getOutput();
		$output->addModules( 'ext.readingLists.bookmark.icons' );

		if ( !$output->isArticle() ) {
			return;
		}

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
			'data-mw-entry-id' => $entry === false ? null : $entry->rle_id,
			'data-mw-list-page-count' => $list->rl_size,
			'link-class' => 'reading-lists-bookmark'
		];

		$output->addModules( 'ext.readingLists.bookmark' );
	}

	/**
	 * Get the URL for the user's default reading list or fallback to generic page
	 * @param UserIdentity $user
	 * @param ReadingListRepository $repository
	 * @return string
	 */
	private static function getDefaultReadingListUrl( UserIdentity $user, ReadingListRepository $repository ): string {
		$defaultListId = $repository->getDefaultListIdForUser();

		if ( $defaultListId === false ) {
			return SpecialPage::getTitleFor( 'ReadingLists' )->getLinkURL();
		}

		$userName = $user->getName();
		return SpecialPage::getTitleFor( 'ReadingLists', $userName . '/' . $defaultListId )->getLinkURL();
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
