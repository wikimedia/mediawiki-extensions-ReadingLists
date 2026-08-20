<?php

namespace MediaWiki\Extension\ReadingLists;

use MediaWiki\Extension\ReadingLists\Service\BookmarkBloomFilterCache;
use MediaWiki\Extension\ReadingLists\Service\BookmarkEntryLookupService;
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
			LoggerFactory::getInstance( 'readinglists' ),
			$services->getStatsFactory()->withComponent( 'readinglists' ),
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
			$services->getStatsFactory()->withComponent( 'readinglists' ),
			LoggerFactory::getInstance( 'readinglists' )
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
];
