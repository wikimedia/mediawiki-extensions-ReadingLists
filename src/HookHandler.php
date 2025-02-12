<?php

namespace MediaWiki\Extension\ReadingLists;

use MediaWiki\Api\ApiQuerySiteinfo;
use MediaWiki\Api\Hook\APIQuerySiteInfoGeneralInfoHook;
use MediaWiki\Extension\BetaFeatures\BetaFeatures;
use MediaWiki\Hook\SkinTemplateNavigation__UniversalHook;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\User;
use SkinTemplate;

/**
 * Static entry points for hooks.
 */
class HookHandler implements APIQuerySiteInfoGeneralInfoHook, SkinTemplateNavigation__UniversalHook {
	/**
	 * Handler for SkinTemplateNavigation::Universal hook.
	 * Adds "Notifications" items to the notifications content navigation.
	 * SkinTemplate automatically merges these into the personal tools for older skins.
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/SkinTemplateNavigation::Universal
	 * @param SkinTemplate $skinTemplate
	 * @param array &$links Array of URLs to append to.
	 */
	public function onSkinTemplateNavigation__Universal( $skinTemplate, &$links ): void {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		if (
			$config->get( 'ReadingListBetaFeature' ) &&
			ExtensionRegistry::getInstance()->isLoaded( 'BetaFeatures' ) &&
			// @phan-suppress-next-line PhanUndeclaredClassMethod BetaFeatures is not necessarily installed.
			BetaFeatures::isFeatureEnabled( $skinTemplate->getUser(), Constants::PREF_KEY_BETA_FEATURES )
		) {
			$out = $skinTemplate->getOutput();
			$out->addModuleStyles( 'readinglist.page.styles' );
			$out->addModules( 'readinglist.page.scripts' );
			$rlUrl = SpecialPage::getTitleFor(
				'ReadingLists'
			)->getLinkURL();
			$userMenu = $links['user-menu'] ?? [];
			$links['actions']['readinglists-bookmark'] = [
				'class' => 'reading-list-bookmark',
				'href' => $rlUrl,
				'icon' => 'bookmark',
			];
			$links['user-menu'] = wfArrayInsertAfter( $userMenu, [
				// The following messages are generated upstream
				// * tooltip-pt-betafeatures
				'readinglist' => [
					'text' => wfMessage( 'readinglists-menu-item' )->text(),
					'href' => $rlUrl,
					'icon' => 'bookmark',
				],
			], 'watchlist' );
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
				'screenshot' => "$path/ReadingLists/resources/beta.png",
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
