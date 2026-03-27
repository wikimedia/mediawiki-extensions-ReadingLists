<?php

namespace MediaWiki\Extension\ReadingLists\Job;

use Job;
use MediaWiki\Extension\ReadingLists\Service\BookmarkBloomFilterCache;
use MediaWiki\MediaWikiServices;

/**
 * Asynchronously rebuilds the bookmark bloom filter cache for a user.
 *
 * Queued when a user adds or removes a bookmark, so the filter is ready
 * before their next page view.
 *
 * If the job hasn't run yet, the next page view falls back to
 * a direct DB lookup for the current page.
 */
class BuildBloomFilterJob extends Job implements \GenericParameterJob {

	public function __construct( array $params ) {
		parent::__construct( 'buildBookmarkBloomFilter', $params );
		$this->removeDuplicates = true;
	}

	public function run(): bool {
		/** @var BookmarkBloomFilterCache $cache */
		$cache = MediaWikiServices::getInstance()
			->getService( 'ReadingLists.BookmarkBloomFilterCache' );
		$cache->rebuildBloomFilter( $this->params['centralId'] );
		return true;
	}

	public function getDeduplicationInfo(): array {
		// Job::getDeduplicationInfo() includes all job params in the
		// dedup key. centralId is the only param, so we can use
		// default deduplication info.
		// Deduplication ensures we rebuild only once even
		// if the user saves or unsaves multiple pages at once.
		return parent::getDeduplicationInfo();
	}

}
