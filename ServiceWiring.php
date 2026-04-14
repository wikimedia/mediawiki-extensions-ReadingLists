<?php

namespace MediaWiki\Extension\ReadingLists;

use MediaWiki\Extension\ReadingLists\Service\BookmarkBloomFilterCache;
use MediaWiki\Extension\ReadingLists\Service\BookmarkEntryLookupService;
use MediaWiki\Extension\ReadingLists\Service\UserPreferenceBatchUpdater;
use MediaWiki\Extension\ReadingLists\Validator\ReadingListPreferenceEligibilityValidator;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

/** @phpcs-require-sorted-array */
return [
	'ReadingLists.BookmarkBloomFilterCache' => static function (
		MediaWikiServices $services
	): BookmarkBloomFilterCache {
		$config = $services->getConfigFactory()->makeConfig( 'ReadingLists' );
		return new BookmarkBloomFilterCache(
			$services->get( 'ReadingLists.ReadingListRepositoryFactory' ),
			$services->getMainWANObjectCache(),
			LoggerFactory::getInstance( 'ReadingLists' ),
			$config->get( 'ReadingListsBloomFilterMaxItems' )
		);
	},
	'ReadingLists.BookmarkEntryLookupService' => static function (
		MediaWikiServices $services
	): BookmarkEntryLookupService {
		return new BookmarkEntryLookupService(
			$services->get( 'ReadingLists.ReadingListRepositoryFactory' ),
			$services->getCentralIdLookupFactory(),
			$services->getJobQueueGroup(),
			$services->get( 'ReadingLists.BookmarkBloomFilterCache' ),
			LoggerFactory::getInstance( 'ReadingLists' )
		);
	},
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
	'ReadingLists.ReverseInterwikiLookup' => static function (
		MediaWikiServices $services
	): ReverseInterwikiLookupInterface {
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
	'ReadingLists.UserPreferenceBatchUpdater' => static function (
		MediaWikiServices $services
	): UserPreferenceBatchUpdater {
		return new UserPreferenceBatchUpdater(
			$services->getDBLoadBalancerFactory(),
			$services->getUserFactory(),
			$services->getUserOptionsManager()
		);
	},
];
