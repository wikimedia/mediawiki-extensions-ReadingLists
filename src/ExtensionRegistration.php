<?php

namespace MediaWiki\Extension\ReadingLists;

use MediaWiki\MainConfigNames;
use MediaWiki\Settings\SettingsBuilder;

class ExtensionRegistration {

	public static function onRegistration( array $info, SettingsBuilder $settings ): void {
		$config = $settings->getConfig();
		$enrollAfter = $config->get( 'ReadingListsBetaDefaultForNewAccountsAfter' );
		if ( $enrollAfter !== null ) {
			$conditionalOptions = $config->get( MainConfigNames::ConditionalUserOptions ) ?? [];
			$conditionalOptions[Constants::PREF_KEY_BETA_FEATURES] = [
				[ 1, [ CUDCOND_AFTER, $enrollAfter ] ]
			];
			$settings->putConfigValue( MainConfigNames::ConditionalUserOptions, $conditionalOptions );
		}
	}
}
