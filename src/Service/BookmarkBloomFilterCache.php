<?php

namespace MediaWiki\Extension\ReadingLists\Service;

use MediaWiki\Extension\ReadingLists\ReadingListRepositoryException;
use MediaWiki\Extension\ReadingLists\ReadingListRepositoryFactory;
use Pleo\BloomFilter\BloomFilter;
use Psr\Log\LoggerInterface;
use StatusValue;
use Wikimedia\LightweightObjectStore\ExpirationAwareness;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\DBError;
use Wikimedia\Rdbms\IDBAccessObject;

class BookmarkBloomFilterCache {

	public const CACHE_VERSION = 1;
	public const BUILD_SUCCESS = 'success';
	public const BUILD_DB_ERROR = 'db-error';
	public const BUILD_CONFIG_ERROR = 'config-error';
	public const BUILD_TOO_LARGE = 'too-large';

	private const BLOOM_FILTER_FALSE_POSITIVE_RATE = 0.01;
	private const BLOOM_FILTER_CACHE_TTL = ExpirationAwareness::TTL_WEEK;
	private const BLOOM_FILTER_FAILURE_TTL = ExpirationAwareness::TTL_MINUTE * 5;

	public function __construct(
		private readonly ReadingListRepositoryFactory $readingListRepositoryFactory,
		private readonly WANObjectCache $cache,
		private readonly LoggerInterface $logger,
		private readonly int $bloomFilterMaxItems,
	) {
		if ( $this->bloomFilterMaxItems < 1 ) {
			throw new \InvalidArgumentException( 'bloomFilterMaxItems must be at least 1' );
		}
	}

	/**
	 * Fetches the bloom filter for a user from cache.
	 *
	 * Returns false when the cache entry is missing, stale, or version-mismatched.
	 * Otherwise returns a StatusValue containing a BloomFilter, null for a
	 * non-usable cached state such as too-large/db-error, or a fatal status for
	 * configuration errors.
	 *
	 * @param int $centralId
	 * @return StatusValue|false
	 */
	public function getCachedBloomFilterStatus( int $centralId ): StatusValue|false {
		$cachedBloomFilter = $this->getRawCachedBloomFilter( $centralId );
		if ( $cachedBloomFilter === false ) {
			return false;
		}

		return $this->deserializeCachedBloomFilter( $cachedBloomFilter, $centralId );
	}

	/**
	 * Rebuilds and stores the user's bloom filter cache entry.
	 *
	 * Successful rebuilds cache the serialized filter.
	 * Failure and fallback states are also cached so repeated
	 * requests do not immediately retry the same rebuild work.
	 *
	 * @param int $centralId
	 * @return void
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
			$cacheSetOpts + [ 'version' => self::CACHE_VERSION ]
		);
	}

	/**
	 * Marks the user's bloom filter cache entry stale.
	 *
	 * @param int $centralId
	 * @return void
	 */
	public function invalidateBloomFilter( int $centralId ): void {
		$this->cache->touchCheckKey( $this->getInvalidationCheckKey( $centralId ) );
	}

	/**
	 * Returns an array which always contains a `state` field describing
	 * the cached build result, using one of the BUILD_* constants on this class.
	 * When the state is BUILD_SUCCESS, the payload also includes `filter`,
	 * which contains the serialized bloom filter data.
	 *
	 * Returns false when the cache entry is missing, stale, or stored with an
	 * incompatible cache version.
	 *
	 * @param int $centralId
	 * @return array{state: string, filter?: array}|false
	 */
	private function getRawCachedBloomFilter( int $centralId ) {
		$invalidationCheckKey = $this->getInvalidationCheckKey( $centralId );
		$info = WANObjectCache::PASS_BY_REF;
		$cachedBloomFilter = $this->cache->get(
			$this->getBloomFilterKey( $centralId ),
			$curTTL,
			[ $invalidationCheckKey ],
			$info
		);

		$hasExpectedCacheVersion =
			( $info[WANObjectCache::KEY_VERSION] ?? null ) === self::CACHE_VERSION;
		$hasTtlMetadata = ( $info[WANObjectCache::KEY_CUR_TTL] ?? null ) !== null;
		$isFresh = $curTTL > 0;

		if ( !$hasExpectedCacheVersion || !$hasTtlMetadata || !$isFresh ) {
			return false;
		}

		return $cachedBloomFilter;
	}

	/**
	 * Returns a StatusValue containing a BloomFilter for usable cached data,
	 * null for non-usable cached states, or a fatal status for configuration
	 * errors. The central ID is used only for logging.
	 *
	 * @param array $cachedBloomFilter
	 * @param int $centralId
	 * @return StatusValue
	 */
	private function deserializeCachedBloomFilter( array $cachedBloomFilter, int $centralId ): StatusValue {
		$state = $cachedBloomFilter['state'] ?? self::BUILD_DB_ERROR;

		if ( $state === self::BUILD_CONFIG_ERROR ) {
			return StatusValue::newFatal( 'readinglists-bloom-filter-config-error' );
		}

		if ( $state !== self::BUILD_SUCCESS ) {
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
	 * @return array{state: string, filter?: array}
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
			return [ 'state' => self::BUILD_DB_ERROR ];
		} catch ( ReadingListRepositoryException $e ) {
			$this->logger->warning( 'Failed to build bloom filter: invalid project or configuration', [
				'exception' => $e,
				'centralId' => $centralId,
			] );
			$ttl = self::BLOOM_FILTER_FAILURE_TTL;
			return [ 'state' => self::BUILD_CONFIG_ERROR ];
		}

		$titleCount = count( $titles );

		if ( $titleCount > $this->bloomFilterMaxItems ) {
			return [ 'state' => self::BUILD_TOO_LARGE ];
		}

		// bloom filter expects a positive integer for the number of items
		// so set this to 1 if the user has no saved pages.
		$filter = BloomFilter::init(
			max( $titleCount, 1 ),
			self::BLOOM_FILTER_FALSE_POSITIVE_RATE
		);
		foreach ( $titles as $rawTitle ) {
			$filter->add( strtr( $rawTitle, ' ', '_' ) );
		}

		return [
			'state' => self::BUILD_SUCCESS,
			'filter' => json_decode( json_encode( $filter ), true ),
		];
	}

	/**
	 * WANObjectCache check key used to invalidate a user's bloom filter entry.
	 *
	 * Touch this key when a bookmark change should make the cached bloom filter
	 * stale. Reads use it to decide whether the cached value key is still fresh.
	 *
	 * @param int $centralId
	 * @return string
	 */
	private function getInvalidationCheckKey( int $centralId ): string {
		return $this->cache->makeKey( 'readinglists', 'bloom-check', $centralId );
	}

	/**
	 * WANObjectCache key for the serialized bloom filter cache value itself.
	 *
	 * Use this key when storing or reading the cached bloom filter payload. This
	 * differs from the invalidation check key above, which only tracks staleness.
	 *
	 * @param int $centralId
	 * @return string
	 */
	private function getBloomFilterKey( int $centralId ): string {
		return $this->cache->makeKey( 'readinglists', 'bloom', $centralId );
	}
}
