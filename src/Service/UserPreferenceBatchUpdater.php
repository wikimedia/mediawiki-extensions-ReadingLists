<?php

namespace MediaWiki\Extension\ReadingLists\Service;

use MediaWiki\User\Options\UserOptionsManager;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use Wikimedia\Rdbms\LBFactory;

class UserPreferenceBatchUpdater {

	private array $updateBatch = [];

	public function __construct(
		private readonly LBFactory $lbFactory,
		private readonly UserFactory $userFactory,
		private readonly UserOptionsManager $userOptionsManager
	) {
	}

	/**
	 * @param UserIdentity $user
	 * @param string $preference
	 * @param string $value
	 * @throws \InvalidArgumentException
	 */
	public function addUserPreference( UserIdentity $user, string $preference, string $value ): void {
		$key = $user->getId() . ':' . $preference;

		if ( isset( $this->updateBatch[$key] ) ) {
			throw new \InvalidArgumentException(
				"Duplicate user preference in batch: user {$user->getId()}, preference '{$preference}'"
			);
		}

		$this->updateBatch[$key] = [
			'up_user' => $user->getId(),
			'up_property' => $preference,
			'up_value' => $value,
		];
	}

	/**
	 * @return bool
	 */
	public function hasPendingUpdates(): bool {
		return $this->updateBatch !== [];
	}

	/**
	 * Get pending rows to be inserted into user_properties table
	 * @return array
	 */
	public function getUpdateBatch(): array {
		return $this->updateBatch;
	}

	/**
	 * @return int
	 */
	public function executeBatchUpdate(): int {
		if ( $this->updateBatch === [] ) {
			return 0;
		}

		$count = count( $this->updateBatch );

		$this->lbFactory->getPrimaryDatabase()->insert(
			'user_properties',
			array_values( $this->updateBatch ),
			__METHOD__
		);

		foreach ( $this->updateBatch as $update ) {
			$user = $this->userFactory->newFromId( $update['up_user'] );
			$this->userOptionsManager->clearUserOptionsCache( $user );
			$user->touch();
		}

		$this->updateBatch = [];

		return $count;
	}

}
