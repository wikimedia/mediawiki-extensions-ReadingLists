<?php

namespace MediaWiki\Extension\ReadingLists;

use MediaWiki\Config\Config;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\BetaFeatures\Hooks\GetBetaFeaturePreferencesHook;
use MediaWiki\MainConfigNames;
use MediaWiki\User\User;

/**
 * This handler is separated from the main HookHandler to allow use of type
 * validation via the hook interface, without a hard dependency on BetaFeatures.
 * It is important that this class only be referenced/loaded by code
 * controlled by the BetaFeatures extension, to keep it a soft dependency.
 */
class BetaFeatureHookHandler implements GetBetaFeaturePreferencesHook {
	public function __construct(
		private readonly Config $config,
	) {
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/GetBetaFeaturePreferences
	 *
	 * @param User $user
	 * @param array[] &$prefs
	 */
	public function onGetBetaFeaturePreferences( User $user, array &$prefs ) {
		if ( !HookHandler::isSkinSupported( RequestContext::getMain()->getSkinName() ) ) {
			return;
		}
		if ( $this->config->get( 'ReadingListBetaFeature' ) ) {
			$path = $this->config->get( MainConfigNames::ExtensionAssetsPath );
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
}
