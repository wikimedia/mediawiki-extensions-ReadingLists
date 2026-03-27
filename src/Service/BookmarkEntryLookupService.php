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
use Psr\Log\LoggerInterface;
use StatusValue;
use Wikimedia\Rdbms\DBError;

class BookmarkEntryLookupService {

	public function __construct(
		private readonly ReadingListRepositoryFactory $readingListRepositoryFactory,
		private readonly CentralIdLookupFactory $centralIdLookupFactory,
		private readonly JobQueueGroup $jobQueueGroup,
		private readonly BookmarkBloomFilterCache $bookmarkBloomFilterCache,
		private readonly LoggerInterface $logger,
	) {
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
		$filterStatus = $this->bookmarkBloomFilterCache->getCachedBloomFilterStatus( $centralId );
		if ( $filterStatus === false ) {
			$this->queueBloomFilterBuildJob( $centralId );
			return $this->lookupBookmarkEntryInDb( $title, $centralId );
		}

		$prefixedDBkey = $title->getPrefixedDBkey();

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

}
