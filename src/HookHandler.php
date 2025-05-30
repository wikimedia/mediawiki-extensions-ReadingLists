<?php

namespace MediaWiki\Extension\ReadingLists;

use MediaWiki\Api\ApiQuerySiteinfo;
use MediaWiki\Api\Hook\APIQuerySiteInfoGeneralInfoHook;
use MediaWiki\Extension\BetaFeatures\BetaFeatures;
use MediaWiki\Hook\SkinTemplateNavigation__UniversalHook;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Skin\SkinTemplate;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;

/**
 * Static entry points for hooks.
 */
class HookHandler implements APIQuerySiteInfoGeneralInfoHook, SkinTemplateNavigation__UniversalHook {
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
		$services = MediaWikiServices::getInstance();
		$user = $sktemplate->getUser();

		if (
			!$services->getMainConfig()->get( 'ReadingListBetaFeature' ) ||
			!ExtensionRegistry::getInstance()->isLoaded( 'BetaFeatures' ) ||
			// @phan-suppress-next-line PhanUndeclaredClassMethod
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

		self::hideWatchlistIcon( $sktemplate, $services, $user, $links );

		$output = $sktemplate->getOutput();
		$output->addModules( 'ext.readingLists.bookmark.icons' );

		if ( !$output->isArticle() ) {
			return;
		}

		$repository = new ReadingListRepository(
			$services->getCentralIdLookupFactory()
				->getLookup()
				->centralIdFromLocalUser( $user ),
			$services->getDBLoadBalancerFactory()
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
	 * Hide the watchlist link on mobile if the user has no edits and their watchlist is empty.
	 * @see https://phabricator.wikimedia.org/T394562
	 * @param SkinTemplate $sktemplate
	 * @param MediaWikiServices $services
	 * @param UserIdentity $user
	 * @param array &$links
	 */
	public static function hideWatchlistIcon( $sktemplate, $services, $user, &$links ) {
		if (
			$sktemplate->getSkinName() === 'minerva' &&
			$services->getUserEditTracker()->getUserEditCount( $user ) === 0
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
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/GetBetaFeaturePreferences
	 *
	 * @param User $user
	 * @param array[] &$prefs
	 */
	public static function onGetBetaFeaturePreferences( $user, array &$prefs ) {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		if ( $config->get( 'ReadingListBetaFeature' ) ) {
			$path = $config->get( MainConfigNames::ExtensionAssetsPath );
			$prefs[Constants::PREF_KEY_BETA_FEATURES] = [
				'label-message' => 'readinglists-beta-feature-name',
				'desc-message' => 'readinglists-beta-feature-description',
				'screenshot' => "$path/ReadingLists/resources/assets/beta.png",
				'info-link'
					=> 'https://www.mediawiki.org/wiki/Extension:ReadingLists/Beta_Feature',
				'discussion-link'
					=> 'https://www.mediawiki.org/wiki/Extension_talk:ReadingLists/Beta_Feature',
			];
		}
	}

	/**
	 * Add configuration data to the siteinfo API output.
	 * Used by the RESTBase proxy for help messages in the Swagger doc.
	 * @param ApiQuerySiteinfo $module
	 * @param array &$result
	 */
	public function onAPIQuerySiteInfoGeneralInfo( $module, &$result ) {
		global $wgReadingListsMaxListsPerUser, $wgReadingListsMaxEntriesPerList,
			   $wgReadingListsDeletedRetentionDays;
		$result['readinglists-config'] = [
			'maxListsPerUser' => $wgReadingListsMaxListsPerUser,
			'maxEntriesPerList' => $wgReadingListsMaxEntriesPerList,
			'deletedRetentionDays' => $wgReadingListsDeletedRetentionDays,
		];
	}
}
