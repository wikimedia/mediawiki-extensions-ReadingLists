<?php

namespace MediaWiki\Extension\ReadingLists;

use MediaWiki\User\CentralId\CentralIdLookupFactory;
use MediaWiki\User\UserIdentity;
use Wikimedia\Rdbms\LBFactory;

class ReadingListRepositoryFactory {

	public function __construct(
		private readonly LBFactory $lbFactory,
		private readonly CentralIdLookupFactory $centralIdLookupFactory
	) {
	}

	/**
	 * @param int|null $centralUserId Central ID of the user, or null for no user (testing only)
	 * @return ReadingListRepository
	 */
	public function create( ?int $centralUserId ): ReadingListRepository {
		return new ReadingListRepository(
			$centralUserId,
			$this->lbFactory
		);
	}

	public function getInstanceForUser( UserIdentity $user ): ReadingListRepository {
		$centralUserId = $this->centralIdLookupFactory->getLookup()
			->centralIdFromLocalUser( $user );
		return $this->create( $centralUserId );
	}
}
