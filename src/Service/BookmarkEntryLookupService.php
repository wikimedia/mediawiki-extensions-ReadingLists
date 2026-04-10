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
use Wikimedia\Rdbms\DBError;
use Wikimedia\Stats\StatsFactory;

class BookmarkEntryLookupService {

	private const LOOKUP_RESULT_CACHE_MISS = 'cache_miss';
	private const LOOKUP_RESULT_DEFINITE_NEGATIVE = 'definite_negative';
	private const LOOKUP_RESULT_TRUE_POSITIVE = 'true_positive';
	private const LOOKUP_RESULT_FALSE_POSITIVE = 'false_positive';
	private const LOOKUP_RESULT_TOO_LARGE_BYPASS_FOUND = 'too_large_bypass_found';
	private const LOOKUP_RESULT_TOO_LARGE_BYPASS_NOT_FOUND = 'too_large_bypass_not_found';
	private const LOOKUP_RESULT_BUILD_DB_ERROR_BYPASS = 'build_db_error_bypass';
	private const LOOKUP_RESULT_CACHE_UNUSABLE = 'cache_unusable';
	private const LOOKUP_RESULT_ERROR = 'error';

	private const DB_LOOKUP_REASON_CACHE_MISS = 'cache_miss';
	private const DB_LOOKUP_REASON_PROBABLE_POSITIVE = 'probable_positive';
	private const DB_LOOKUP_REASON_TOO_LARGE_BYPASS = 'too_large_bypass';
	private const DB_LOOKUP_REASON_BUILD_DB_ERROR_BYPASS = 'build_db_error_bypass';
	private const DB_LOOKUP_REASON_CACHE_UNUSABLE = 'cache_unusable';

	private const FAILURE_POINT_CACHE_STATUS = 'cache_status';
	private const FAILURE_POINT_CACHE_MISS_DB_LOOKUP = 'cache_miss_db_lookup';
	private const FAILURE_POINT_TOO_LARGE_BYPASS_DB_LOOKUP = 'too_large_bypass_db_lookup';
	private const FAILURE_POINT_BUILD_DB_ERROR_BYPASS_DB_LOOKUP = 'build_db_error_bypass_db_lookup';
	private const FAILURE_POINT_CACHE_UNUSABLE_DB_LOOKUP = 'cache_unusable_db_lookup';
	private const FAILURE_POINT_PROBABLE_POSITIVE_DB_LOOKUP = 'probable_positive_db_lookup';

	public function __construct(
		private readonly ReadingListRepositoryFactory $readingListRepositoryFactory,
		private readonly CentralIdLookupFactory $centralIdLookupFactory,
		private readonly JobQueueGroup $jobQueueGroup,
		private readonly BookmarkBloomFilterCache $bookmarkBloomFilterCache,
		private readonly StatsFactory $statsFactory,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Look up whether a page is in the user's reading list.
	 *
	 * On success, the StatusValue's value is a matching reading list row for a list
	 * containing the page, or null if the page is not bookmarked.
	 *
	 * Known bloom-filter fallback states still return an OK StatusValue. Only real
	 * failures, such as DB or configuration errors, return a non-OK status.
	 *
	 * @param Title $title
	 * @param int $centralId
	 * @return StatusValue
	 */
	public function getBookmarkEntryStatus( Title $title, int $centralId ): StatusValue {
		$filterStatus = $this->bookmarkBloomFilterCache->getCachedBloomFilterStatus( $centralId );

		// Bloom filter in not in the cache, queue a rebuild job and fallback to the DB lookup.
		if ( $filterStatus === false ) {
			return $this->handleCacheMiss( $title, $centralId );
		}

		// Cache lookup succeeded, but interpreting the cached state returned a
		// non-OK status instead of a usable filter or known fallback state.
		if ( !$filterStatus->isOK() ) {
			return $this->handleCacheStatusFailure( $filterStatus );
		}

		$filter = $filterStatus->getValue();

		// User has too many bookmarked pages, fallback to the DB lookup.
		if ( $filter === BookmarkBloomFilterCache::BUILD_TOO_LARGE ) {
			return $this->handleTooLargeBypass( $title, $centralId );
		}

		if ( $filter === BookmarkBloomFilterCache::BUILD_DB_ERROR ) {
			// A previous bloom-filter build failed due to a DB error, and that failure
			// state was cached on purpose. This failure state is cached briefly to
			// avoid repeatedly retrying the same failing rebuild work on every
			// request. Reuse it here and fall back to the exact DB lookup.
			// If metrics show this happens often, then should investigate why the
			// bloom filter build is failing.
			return $this->handleCachedBuildDbError( $title, $centralId );
		}

		if ( !$filter instanceof BloomFilter ) {
			// Cache read succeeded, but the cached value was unusable, for example
			// because it had an unknown state, missing filter data, or failed to deserialize.
			return $this->handleUnusableCachedFilter( $title, $centralId );
		}

		if ( !$filter->exists( $title->getPrefixedDBkey() ) ) {
			return $this->handleDefiniteNegative();
		}

		return $this->handleProbablePositive( $title, $centralId );
	}

	private function handleCacheStatusFailure( StatusValue $filterStatus ): StatusValue {
		$this->recordLookupFailureMetrics( self::FAILURE_POINT_CACHE_STATUS );
		return $filterStatus;
	}

	private function handleCacheMiss( Title $title, int $centralId ): StatusValue {
		$this->queueBloomFilterBuildJob( $centralId );
		$this->recordDbLookupMetric( self::DB_LOOKUP_REASON_CACHE_MISS );
		$status = $this->lookupBookmarkListInDb( $title, $centralId );

		if ( !$status->isOK() ) {
			$this->recordLookupFailureMetrics( self::FAILURE_POINT_CACHE_MISS_DB_LOOKUP );
		} else {
			$this->recordBloomFilterLookupMetric( self::LOOKUP_RESULT_CACHE_MISS );
		}

		return $status;
	}

	private function handleTooLargeBypass( Title $title, int $centralId ): StatusValue {
		$this->recordDbLookupMetric( self::DB_LOOKUP_REASON_TOO_LARGE_BYPASS );
		$status = $this->lookupBookmarkListInDb( $title, $centralId );

		if ( !$status->isOK() ) {
			$this->recordLookupFailureMetrics( self::FAILURE_POINT_TOO_LARGE_BYPASS_DB_LOOKUP );
		} else {
			$this->recordBloomFilterLookupMetric(
				$status->getValue() !== null
					? self::LOOKUP_RESULT_TOO_LARGE_BYPASS_FOUND
					: self::LOOKUP_RESULT_TOO_LARGE_BYPASS_NOT_FOUND
			);
		}

		return $status;
	}

	private function handleCachedBuildDbError( Title $title, int $centralId ): StatusValue {
		$this->recordDbLookupMetric( self::DB_LOOKUP_REASON_BUILD_DB_ERROR_BYPASS );
		$status = $this->lookupBookmarkListInDb( $title, $centralId );

		if ( !$status->isOK() ) {
			$this->recordLookupFailureMetrics( self::FAILURE_POINT_BUILD_DB_ERROR_BYPASS_DB_LOOKUP );
		} else {
			$this->recordBloomFilterLookupMetric( self::LOOKUP_RESULT_BUILD_DB_ERROR_BYPASS );
		}

		return $status;
	}

	private function handleUnusableCachedFilter( Title $title, int $centralId ): StatusValue {
		$this->recordDbLookupMetric( self::DB_LOOKUP_REASON_CACHE_UNUSABLE );
		$status = $this->lookupBookmarkListInDb( $title, $centralId );

		if ( !$status->isOK() ) {
			$this->recordLookupFailureMetrics( self::FAILURE_POINT_CACHE_UNUSABLE_DB_LOOKUP );
		} else {
			$this->recordBloomFilterLookupMetric( self::LOOKUP_RESULT_CACHE_UNUSABLE );
		}

		return $status;
	}

	private function handleDefiniteNegative(): StatusValue {
		$this->recordBloomFilterLookupMetric( self::LOOKUP_RESULT_DEFINITE_NEGATIVE );
		return StatusValue::newGood( null );
	}

	private function handleProbablePositive( Title $title, int $centralId ): StatusValue {
		$this->recordDbLookupMetric( self::DB_LOOKUP_REASON_PROBABLE_POSITIVE );
		$status = $this->lookupBookmarkListInDb( $title, $centralId );
		if ( !$status->isOK() ) {
			$this->recordLookupFailureMetrics( self::FAILURE_POINT_PROBABLE_POSITIVE_DB_LOOKUP );
			return $status;
		}

		$this->recordBloomFilterLookupMetric(
			$status->getValue() !== null
				? self::LOOKUP_RESULT_TRUE_POSITIVE
				: self::LOOKUP_RESULT_FALSE_POSITIVE
		);

		return $status;
	}

	private function lookupBookmarkListInDb( Title $title, int $centralId ): StatusValue {
		$repository = $this->readingListRepositoryFactory->create( $centralId );

		// FIXME: The API does not normalize titles on write, so the DB may
		// contain spaces or underscores for the same title (T407936). The
		// bloom filter normalizes to underscores (see buildBookmarkedPagesBloomFilter),
		// but the DB lookup must try both formats to find matching lists.
		try {
			$listRow = $repository->getListsByPage( '@local', $title->getPrefixedDBkey(), 1 )->fetchObject();
			if ( !$listRow ) {
				$listRow = $repository->getListsByPage( '@local', $title->getPrefixedText(), 1 )->fetchObject();
			}
		} catch ( DBError $e ) {
			$this->logger->warning( 'Failed to look up bookmark list match due to a database error', [
				'exception' => $e,
				'centralId' => $centralId,
			] );
			return StatusValue::newFatal( 'readinglists-bookmark-lookup-db-error' );
		} catch ( ReadingListRepositoryException $e ) {
			$this->logger->error( 'Failed to look up bookmark list match: invalid project or configuration', [
				'exception' => $e,
				'centralId' => $centralId,
			] );
			return StatusValue::newFatal( 'readinglists-bookmark-lookup-config-error' );
		}

		return StatusValue::newGood( $listRow ?: null );
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
		$this->bookmarkBloomFilterCache->invalidateBloomFilter( $centralId );
		$this->queueBloomFilterBuildJob( $centralId );
	}

	/**
	 * Queues a bloom filter build job for the user.
	 *
	 * @param int $centralId
	 * @return void
	 */
	private function queueBloomFilterBuildJob( int $centralId ): void {
		$this->jobQueueGroup->lazyPush( new BuildBloomFilterJob( [
			'centralId' => $centralId,
		] ) );
	}

	/**
	 * Records the final result of a bookmark lookup request.
	 *
	 * @param string $result Outcome label for bloom_lookup_total
	 * @return void
	 */
	private function recordBloomFilterLookupMetric( string $result ): void {
		$this->statsFactory->getCounter( 'bloom_lookup_total' )
			->setLabel( 'result', $result )
			->increment();
	}

	/**
	 * Records why an exact DB lookup was required.
	 *
	 * @param string $reason Reason label for bloom_db_lookup_total
	 * @return void
	 */
	private function recordDbLookupMetric( string $reason ): void {
		$this->statsFactory->getCounter( 'bloom_db_lookup_total' )
			->setLabel( 'reason', $reason )
			->increment();
	}

	/**
	 * Records where a lookup request failed.
	 *
	 * @param string $failurePoint Value for the stage label in bloom_error_total
	 * @return void
	 */
	private function recordErrorMetric( string $failurePoint ): void {
		$this->statsFactory->getCounter( 'bloom_error_total' )
			->setLabel( 'stage', $failurePoint )
			->increment();
	}

	/**
	 * Records where the lookup failed and records the final lookup result as error.
	 *
	 * @param string $failurePoint Value for the stage label in bloom_error_total
	 * @return void
	 */
	private function recordLookupFailureMetrics( string $failurePoint ): void {
		$this->recordErrorMetric( $failurePoint );
		$this->recordBloomFilterLookupMetric( self::LOOKUP_RESULT_ERROR );
	}

}
