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
use Wikimedia\Stats\StatsFactory;

class BookmarkBloomFilterCache {

	public const CACHE_VERSION = 1;
	public const BUILD_SUCCESS = 'success';
	public const BUILD_EMPTY = 'empty';
	public const BUILD_DB_ERROR = 'db-error';
	public const BUILD_CONFIG_ERROR = 'config-error';
	public const BUILD_TOO_LARGE = 'too-large';
	public const BUILD_UNUSABLE = 'unusable';

	private const BLOOM_FILTER_FALSE_POSITIVE_RATE = 0.01;
	private const BLOOM_FILTER_CACHE_TTL = ExpirationAwareness::TTL_MONTH;
	private const BLOOM_FILTER_FAILURE_TTL = ExpirationAwareness::TTL_MINUTE * 5;

	private const CACHE_HIT_VALUE_AGE_BUCKETS = [
		ExpirationAwareness::TTL_HOUR,
		ExpirationAwareness::TTL_HOUR * 6,
		ExpirationAwareness::TTL_DAY,
		ExpirationAwareness::TTL_DAY * 2,
		ExpirationAwareness::TTL_DAY * 4,
		ExpirationAwareness::TTL_WEEK,
		ExpirationAwareness::TTL_WEEK * 2,
		ExpirationAwareness::TTL_WEEK * 3,
		ExpirationAwareness::TTL_DAY * 30,
	];

	public function __construct(
		private readonly ReadingListRepositoryFactory $readingListRepositoryFactory,
		private readonly WANObjectCache $cache,
		private readonly LoggerInterface $logger,
		private readonly StatsFactory $statsFactory,
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
	 * Otherwise returns a StatusValue. An OK status contains either a BloomFilter
	 * or a BUILD_* state string describing a known fallback path. A non-OK status
	 * represents a real failure, such as a configuration error.
	 *
	 * @param int $centralId
	 * @return StatusValue|false
	 */
	public function getCachedBloomFilterStatus( int $centralId ): StatusValue|false {
		$cacheResult = $this->getRawCachedBloomFilter( $centralId );
		if ( $cacheResult === false ) {
			return false;
		}

		$status = $this->deserializeCachedBloomFilter( $cacheResult['cachedBloomFilter'], $centralId );
		$cachedValue = $status->getValue();

		if (
			$status->isOK() &&
			( $cachedValue instanceof BloomFilter || $cachedValue === self::BUILD_EMPTY )
		) {
			$this->statsFactory->getHistogram(
				'bloom_cache_hit_value_age_seconds',
				self::CACHE_HIT_VALUE_AGE_BUCKETS
			)->observe( $cacheResult['valueAgeSeconds'] );
		}

		return $status;
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

		$cachedBloomFilter = $this->buildBookmarkedPagesBloomFilter(
			$centralId,
			$ttl
		);

		$this->storeCachedBloomFilter( $centralId, $cachedBloomFilter, $ttl );
	}

	/**
	 * Stores a definite-negative state for a user with no saved pages.
	 *
	 * @param int $centralId
	 * @return void
	 */
	public function storeEmptyBloomFilter( int $centralId ): void {
		$this->storeCachedBloomFilter(
			$centralId,
			[ 'state' => self::BUILD_EMPTY ],
			self::BLOOM_FILTER_CACHE_TTL
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
	 * @return array{
	 *   cachedBloomFilter: array{state: string, filter?: array},
	 *   valueAgeSeconds: float
	 * }|false
	 */
	private function getRawCachedBloomFilter( int $centralId ) {
		$currentTtl = null;
		$invalidationCheckKey = $this->getInvalidationCheckKey( $centralId );
		$info = WANObjectCache::PASS_BY_REF;

		$cachedBloomFilter = $this->cache->get(
			$this->getBloomFilterKey( $centralId ),
			$currentTtl,
			[ $invalidationCheckKey ],
			$info
		);

		$asOf = $info[WANObjectCache::KEY_AS_OF] ?? null;
		if ( $cachedBloomFilter === false || $asOf === null ) {
			$this->recordCacheMissMetric( 'absent' );
			return false;
		}

		// Report an old cache version as version_mismatch, even if the entry is also stale.
		if ( ( $info[WANObjectCache::KEY_VERSION] ?? null ) !== self::CACHE_VERSION ) {
			$this->recordCacheMissMetric( 'version_mismatch' );
			return false;
		}

		$ttl = $info[WANObjectCache::KEY_TTL] ?? null;
		if ( $ttl === null || $currentTtl === null || $currentTtl <= 0 ) {
			$this->recordCacheMissMetric( 'stale' );
			return false;
		}

		return [
			'cachedBloomFilter' => $cachedBloomFilter,
			'valueAgeSeconds' => max( 0.0, (float)$ttl - (float)$currentTtl ),
		];
	}

	private function recordCacheMissMetric( string $reason ): void {
		$this->statsFactory->getCounter( 'bloom_cache_miss_total' )
			->setLabel( 'reason', $reason )
			->increment();
	}

	/**
	 * Converts cached bloom-filter data into a StatusValue.
	 *
	 * An OK status contains either a usable BloomFilter or a BUILD_* state string
	 * for a known fallback path. A non-OK status represents a real failure, such
	 * as a configuration error. The central ID is used only for logging.
	 *
	 * @param array $cachedBloomFilter
	 * @param int $centralId
	 * @return StatusValue
	 */
	private function deserializeCachedBloomFilter( array $cachedBloomFilter, int $centralId ): StatusValue {
		$state = $cachedBloomFilter['state'] ?? self::BUILD_UNUSABLE;

		if ( $state === self::BUILD_CONFIG_ERROR ) {
			return StatusValue::newFatal( 'readinglists-bloom-filter-config-error' );
		}

		if (
			$state === self::BUILD_EMPTY ||
			$state === self::BUILD_TOO_LARGE ||
			$state === self::BUILD_DB_ERROR
		) {
			return StatusValue::newGood( $state );
		}

		if ( $state !== self::BUILD_SUCCESS ) {
			return StatusValue::newGood( self::BUILD_UNUSABLE );
		}

		$filterData = $cachedBloomFilter['filter'] ?? null;
		if ( !is_array( $filterData ) ) {
			return StatusValue::newGood( self::BUILD_UNUSABLE );
		}

		try {
			$filter = BloomFilter::initFromJson( $filterData );
		} catch ( \Throwable $e ) {
			$this->logger->warning( 'Failed to deserialize bloom filter', [
				'exception' => $e,
				'centralId' => $centralId,
			] );
			return StatusValue::newGood( self::BUILD_UNUSABLE );
		}

		return StatusValue::newGood( $filter );
	}

	/**
	 * @param int $centralId
	 * @param int &$ttl Cache TTL, adjusted on failure to avoid retrying too often
	 * @return array{state: string, filter?: array}
	 */
	private function buildBookmarkedPagesBloomFilter(
		int $centralId,
		int &$ttl
	) {
		$repository = $this->readingListRepositoryFactory->create( $centralId );

		try {
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
			$this->logger->error( 'Failed to build bloom filter: invalid project or configuration', [
				'exception' => $e,
				'centralId' => $centralId,
			] );
			$ttl = self::BLOOM_FILTER_FAILURE_TTL;
			return [ 'state' => self::BUILD_CONFIG_ERROR ];
		}

		$titleCount = count( $titles );

		if ( $titleCount === 0 ) {
			return [ 'state' => self::BUILD_EMPTY ];
		}

		if ( $titleCount > $this->bloomFilterMaxItems ) {
			return [ 'state' => self::BUILD_TOO_LARGE ];
		}

		$filter = BloomFilter::init(
			$titleCount,
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
	 * @param int $centralId
	 * @param array{state: string, filter?: array} $cachedBloomFilter
	 * @param int $ttl
	 * @return void
	 */
	private function storeCachedBloomFilter(
		int $centralId,
		array $cachedBloomFilter,
		int $ttl
	): void {
		$this->cache->set(
			$this->getBloomFilterKey( $centralId ),
			$cachedBloomFilter,
			$ttl,
			[ 'version' => self::CACHE_VERSION ]
		);
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
