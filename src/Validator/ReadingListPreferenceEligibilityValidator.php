<?php

namespace MediaWiki\Extension\ReadingLists\Validator;

use MediaWiki\Extension\ReadingLists\ReadingListRepositoryException;
use MediaWiki\Extension\ReadingLists\ReadingListRepositoryFactory;
use MediaWiki\User\UserEditTracker;
use MediaWiki\User\UserIdentity;
use MediaWiki\Watchlist\WatchedItemStoreInterface;

/**
 * Checks if a user is eligible for the reading list feature,
 * based on experiment criteria. See T397532
 */
class ReadingListPreferenceEligibilityValidator {

	/**
	 * When a new account is created, the user and user talk page are
	 * automatically added to the user's watchlist.
	 * We need to check for more than 2 items to determine
	 * if the user is using their watchlist.
	 */
	private const MAX_WATCHED_ITEMS = 2;

	public function __construct(
		private readonly UserEditTracker $userEditTracker,
		private readonly WatchedItemStoreInterface $watchedItemStore,
		private readonly ReadingListRepositoryFactory $repositoryFactory
	) {
	}

	/**
	 * @param UserIdentity $user
	 * @return bool
	 */
	public function isEligible( UserIdentity $user ): bool {
		if ( $this->userEditTracker->getUserEditCount( $user ) > 0 ) {
			return false;
		}

		if ( $this->userHasReadingList( $user ) ) {
			return false;
		}

		if ( $this->watchedItemStore->countWatchedItems( $user ) > self::MAX_WATCHED_ITEMS ) {
			return false;
		}

		return true;
	}

	/**
	 * @param UserIdentity $user
	 * @return bool
	 */
	private function userHasReadingList( UserIdentity $user ): bool {
		try {
			$readingListRepository = $this->repositoryFactory->getInstanceForUser( $user );
			$list = $readingListRepository->getDefaultListIdForUser();
			return (bool)$list;
		} catch ( ReadingListRepositoryException ) {
			return false;
		}
	}
}
