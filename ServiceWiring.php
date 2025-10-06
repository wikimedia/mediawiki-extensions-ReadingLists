<?php

namespace MediaWiki\Extension\ReadingLists;

use MediaWiki\Extension\ReadingLists\Service\UserPreferenceBatchUpdater;
use MediaWiki\Extension\ReadingLists\Validator\ReadingListPreferenceEligibilityValidator;
use MediaWiki\MediaWikiServices;

/** @phpcs-require-sorted-array */
return [
	'ReadingLists.ReadingListEligibilityValidator' => static function (
		MediaWikiServices $services
	): ReadingListPreferenceEligibilityValidator {
		return new ReadingListPreferenceEligibilityValidator(
			$services->getUserEditTracker(),
			$services->getWatchedItemStore(),
			$services->get( 'ReadingLists.ReadingListRepositoryFactory' )
		);
	},
	'ReadingLists.ReadingListRepositoryFactory' => static function (
		MediaWikiServices $services
	): ReadingListRepositoryFactory {
		return new ReadingListRepositoryFactory(
			$services->getDBLoadBalancerFactory(),
			$services->getCentralIdLookupFactory()
		);
	},
	'ReverseInterwikiLookup' => static function ( MediaWikiServices $services ): ReverseInterwikiLookupInterface {
		$ownServer = $services->getMainConfig()->get( 'CanonicalServer' );
		$urlUtils = $services->getUrlUtils();
		$ownServerParts = $urlUtils->parse( $ownServer );
		$ownDomain = '';
		if ( !empty( $ownServerParts['host'] ) ) {
			$ownDomain = $ownServerParts['host'];
		}
		return new ReverseInterwikiLookup(
			$services->getInterwikiLookup(),
			$services->getLanguageNameUtils(),
			$urlUtils,
			$ownDomain
		);
	},
	'UserPreferenceBatchUpdater' => static function ( MediaWikiServices $services ): UserPreferenceBatchUpdater {
		return new UserPreferenceBatchUpdater(
			$services->getDBLoadBalancerFactory(),
			$services->getUserFactory(),
			$services->getUserOptionsManager()
		);
	},
];
