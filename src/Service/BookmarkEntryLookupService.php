<?php

namespace MediaWiki\Extension\ReadingLists\Service;

use MediaWiki\Extension\ReadingLists\Job\BuildBloomFilterJob;
use MediaWiki\Extension\ReadingLists\ReadingListRepositoryException;
use MediaWiki\Extension\ReadingLists\ReadingListRepositoryFactory;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\Title\Title;
use MediaWiki\User\CentralId\CentralIdLookup;
use MediaWiki\User\CentralId\CentralIdLookupFactory;
use MediaWiki\User\UserIdentity;
use Pleo\BloomFilter\BloomFilter;
use Psr\Log\LoggerInterface;
use StatusValue;
use Wikimedia\LightweightObjectStore\ExpirationAwareness;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\DBError;
use Wikimedia\Rdbms\IDBAccessObject;

class BookmarkEntryLookupService {

	private const BLOOM_FILTER_FALSE_POSITIVE_RATE = 0.01;
	private const BLOOM_FILTER_CACHE_VERSION = 1;

	// Cache TTLs
	private const BLOOM_FILTER_CACHE_TTL = ExpirationAwareness::TTL_WEEK;
	private const BLOOM_FILTER_FAILURE_TTL = ExpirationAwareness::TTL_MINUTE * 5;

	// Result states returned by buildBookmarkedPagesBloomFilter()
	private const BLOOM_FILTER_BUILD_SUCCESS = 'success';
	private const BLOOM_FILTER_BUILD_DB_ERROR = 'db-error';
	private const BLOOM_FILTER_BUILD_CONFIG_ERROR = 'config-error';
	private const BLOOM_FILTER_BUILD_TOO_LARGE = 'too-large';

	public function __construct(
		private readonly ReadingListRepositoryFactory $readingListRepositoryFactory,
		private readonly WANObjectCache $cache,
		private readonly CentralIdLookupFactory $centralIdLookupFactory,
		private readonly JobQueueGroup $jobQueueGroup,
		private readonly LoggerInterface $logger,
		private readonly int $bloomFilterMaxItems,
	) {
		if ( $this->bloomFilterMaxItems < 1 ) {
			throw new \InvalidArgumentException( 'bloomFilterMaxItems must be at least 1' );
		}
	}

	/**
	 * Look up whether a page is in the user's reading list.
	 *
	 * On success, the StatusValue's value is the reading list entry object,
	 * or null if the page is not bookmarked.
	 * On failure (DB error, configuration issue), the StatusValue
	 * is not OK and contains an error.
	 *
	 * @param Title $title
	 * @param int $centralId
	 * @return StatusValue
	 */
	public function getBookmarkEntryStatus( Title $title, int $centralId ): StatusValue {
		$cachedBloomFilter = $this->getCachedBloomFilter( $centralId );
		if ( $cachedBloomFilter === false ) {
			$this->queueBloomFilterRebuild( $centralId );
			return $this->lookupBookmarkEntryInDb( $title, $centralId );
		}

		$prefixedDBkey = $title->getPrefixedDBkey();
		$filterStatus = $this->deserializeBloomFilter( $cachedBloomFilter, $centralId );

		if ( !$filterStatus->isOK() ) {
			return $filterStatus;
		}

		$filter = $filterStatus->getValue();

		// If the bloom filter is available and the page is definitely not in it,
		// skip the DB query. A null filter (too many bookmarks) falls through
		// to the DB lookup below.
		if ( $filter !== null && !$filter->exists( $prefixedDBkey ) ) {
			return StatusValue::newGood( null );
		}

		return $this->lookupBookmarkEntryInDb( $title, $centralId );
	}

	private function lookupBookmarkEntryInDb( Title $title, int $centralId ): StatusValue {
		$repository = $this->readingListRepositoryFactory->create( $centralId );

		// FIXME: The API does not normalize titles on write, so the DB may
		// contain spaces or underscores for the same title (T407936). The
		// bloom filter normalizes to underscores (see buildBookmarkedPagesBloomFilter),
		// but the DB lookup must try both formats to find existing entries.
		try {
			$entry = $repository->getListsByPage( '@local', $title->getPrefixedDBkey(), 1 )->fetchObject();
			if ( !$entry ) {
				$entry = $repository->getListsByPage( '@local', $title->getPrefixedText(), 1 )->fetchObject();
			}
		} catch ( DBError $e ) {
			$this->logger->warning( 'Failed to look up bookmark entry due to a database error', [
				'exception' => $e,
				'centralId' => $centralId,
			] );
			return StatusValue::newFatal( 'readinglists-bookmark-lookup-db-error' );
		} catch ( ReadingListRepositoryException $e ) {
			$this->logger->warning( 'Failed to look up bookmark entry: invalid project or configuration', [
				'exception' => $e,
				'centralId' => $centralId,
			] );
			return StatusValue::newFatal( 'readinglists-bookmark-lookup-config-error' );
		}

		return StatusValue::newGood( $entry ?: null );
	}

	/**
	 * Invalidates the user's bookmark bloom filter and queues an async job
	 * to rebuild it. Call this when a user adds or removes a bookmark.
	 *
	 * The job rebuilds the bloom filter in cache so the filter is ready
	 * before the user's next page view. If the job has not run yet,
	 * the next page view falls back to the exact DB lookup.
	 *
	 * @param UserIdentity $user
	 */
	public function invalidateBookmarkBloomFilter( UserIdentity $user ): void {
		$centralId = $this->centralIdLookupFactory
			->getLookup()
			->centralIdFromLocalUser( $user, CentralIdLookup::AUDIENCE_RAW );
		$this->cache->touchCheckKey( $this->getInvalidationCheckKey( $centralId ) );
		$this->queueBloomFilterRebuild( $centralId );
	}

	/**
	 * Rebuild the bloom filter for a user and store it in cache.
	 * Called by BuildBloomFilterJob to rebuild the filter asynchronously
	 * so it's ready before the user's next page view.
	 *
	 * @param int $centralId
	 */
	public function rebuildBloomFilter( int $centralId ): void {
		$ttl = self::BLOOM_FILTER_CACHE_TTL;

		$cacheSetOpts = [];
		$cachedBloomFilter = $this->buildBookmarkedPagesBloomFilter(
			$centralId,
			$ttl,
			$cacheSetOpts
		);

		/**
		 * buildBookmarkedPagesBloomFilter adds DB freshness metadata
		 * to $cacheSetOpts. buildBookmarkedPagesBloomFilter queries
		 * from the primary DB, but the freshness metadata is still needed.
		 * Pass this metadata here to WANObjectCache so it
		 * treats the cached value as only as current as the
		 * DB read it was built from.
		 */
		$this->cache->set(
			$this->getBloomFilterKey( $centralId ),
			$cachedBloomFilter,
			$ttl,
			$cacheSetOpts + [ 'version' => self::BLOOM_FILTER_CACHE_VERSION ]
		);
	}

	/**
	 * Fetch the bloom filter from cache only. Returns false when the cache entry
	 * is missing, stale, or stored with an incompatible version.
	 *
	 * @param int $centralId
	 * @return array|false
	 */
	private function getCachedBloomFilter( int $centralId ) {
		$invalidationCheckKey = $this->getInvalidationCheckKey( $centralId );
		$info = WANObjectCache::PASS_BY_REF;
		$cachedBloomFilter = $this->cache->get(
			$this->getBloomFilterKey( $centralId ),
			$curTTL,
			[ $invalidationCheckKey ],
			$info
		);

		if (
			( $info[WANObjectCache::KEY_VERSION] ?? null ) !== self::BLOOM_FILTER_CACHE_VERSION ||
			( $info[WANObjectCache::KEY_CUR_TTL] ?? null ) === null ||
			$curTTL <= 0
		) {
			return false;
		}

		return $cachedBloomFilter;
	}

	/**
	 * Interpret the raw cache entry and produce a BloomFilter or an error.
	 *
	 * @param array $cachedBloomFilter Value returned by getCachedBloomFilter()
	 * @param int $centralId For logging
	 * @return StatusValue
	 */
	private function deserializeBloomFilter( array $cachedBloomFilter, int $centralId ): StatusValue {
		$state = $cachedBloomFilter['state'] ?? self::BLOOM_FILTER_BUILD_DB_ERROR;

		if ( $state === self::BLOOM_FILTER_BUILD_CONFIG_ERROR ) {
			return StatusValue::newFatal( 'readinglists-bloom-filter-config-error' );
		}

		if ( $state !== self::BLOOM_FILTER_BUILD_SUCCESS ) {
			return StatusValue::newGood( null );
		}

		$filterData = $cachedBloomFilter['filter'] ?? null;
		if ( !is_array( $filterData ) ) {
			return StatusValue::newGood( null );
		}

		try {
			$filter = BloomFilter::initFromJson( $filterData );
		} catch ( \Throwable $e ) {
			$this->logger->warning( 'Failed to deserialize bloom filter', [
				'exception' => $e,
				'centralId' => $centralId,
			] );
			return StatusValue::newGood( null );
		}

		return StatusValue::newGood( $filter );
	}

	/**
	 * @param int $centralId
	 * @param int &$ttl Cache TTL, adjusted on failure to avoid retrying too often
	 * @param array &$cacheSetOpts WANObjectCache set options for the primary DB read
	 * @return array{state: string, filter?: array} Contains the bloom filter data on success,
	 *   or just a state indicating why the filter could not be built
	 */
	private function buildBookmarkedPagesBloomFilter(
		int $centralId,
		int &$ttl,
		array &$cacheSetOpts = []
	) {
		$repository = $this->readingListRepositoryFactory->create( $centralId );

		try {
			$cacheSetOpts += $repository->getSavedPagesCacheSetOptions(
				IDBAccessObject::READ_LATEST
			);
			$titles = $repository->getSavedPageTitlesForProject(
				'@local',
				$this->bloomFilterMaxItems + 1,
				IDBAccessObject::READ_LATEST
			);
		} catch ( DBError $e ) {
			$this->logger->warning( 'Failed to build bloom filter due to a database error', [
				'exception' => $e,
				'centralId' => $centralId,
			] );
			$ttl = self::BLOOM_FILTER_FAILURE_TTL;
			return [ 'state' => self::BLOOM_FILTER_BUILD_DB_ERROR ];
		} catch ( ReadingListRepositoryException $e ) {
			// Configuration issue (e.g. unknown project), rather than
			// a DB problem. Still cache briefly to avoid
			// retrying on every page view.
			$this->logger->warning( 'Failed to build bloom filter: invalid project or configuration', [
				'exception' => $e,
				'centralId' => $centralId,
			] );
			$ttl = self::BLOOM_FILTER_FAILURE_TTL;
			return [ 'state' => self::BLOOM_FILTER_BUILD_CONFIG_ERROR ];
		}

		if ( count( $titles ) > $this->bloomFilterMaxItems ) {
			// Uses the default TTL (BLOOM_FILTER_CACHE_TTL) to avoid running
			// this query on every page view. Invalidated via check key when
			// the user adds or removes a bookmark.
			return [ 'state' => self::BLOOM_FILTER_BUILD_TOO_LARGE ];
		}

		$filter = BloomFilter::init(
			max( count( $titles ), 1 ),
			self::BLOOM_FILTER_FALSE_POSITIVE_RATE
		);
		foreach ( $titles as $rawTitle ) {
			// FIXME: Normalize to underscores to match getPrefixedDBkey()
			// used in getBookmarkEntry(). Both this and the dual DB lookup
			// above compensate for unnormalized titles on write (T407936).
			$filter->add( strtr( $rawTitle, ' ', '_' ) );
		}

		// Convert to a plain array via JSON round-trip. BloomFilter::jsonSerialize()
		// returns nested objects (e.g. BitArray) which survive WANObjectCache's PHP
		// serialization, but BloomFilter::initFromJson() expects plain arrays as
		// produced by json_decode().
		return [
			'state' => self::BLOOM_FILTER_BUILD_SUCCESS,
			'filter' => json_decode( json_encode( $filter ), true ),
		];
	}

	/**
	 * WANObjectCache "check key" used to invalidate the bloom filter.
	 *
	 * Touched when a user adds or removes a bookmark. On the next read,
	 * WANObjectCache::get() treats cache entries older than the check key
	 * as stale, so the request falls back to the exact DB lookup and relies
	 * on the queued rebuild job to repopulate the cache.
	 */
	private function getInvalidationCheckKey( int $centralId ): string {
		return $this->cache->makeKey( 'readinglists', 'bloom-check', $centralId );
	}

	/**
	 * WANObjectCache key for the serialized bloom filter value itself,
	 * as opposed to the invalidation check key (see above) which is
	 * used to determine if the cached value is stale.
	 */
	private function getBloomFilterKey( int $centralId ): string {
		return $this->cache->makeKey( 'readinglists', 'bloom', $centralId );
	}

	private function queueBloomFilterRebuild( int $centralId ): void {
		$this->jobQueueGroup->lazyPush( new BuildBloomFilterJob( [
			'centralId' => $centralId,
		] ) );
	}

}
