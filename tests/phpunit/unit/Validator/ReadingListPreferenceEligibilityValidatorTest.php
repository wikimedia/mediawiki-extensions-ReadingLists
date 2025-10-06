<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Validator;

use MediaWiki\Extension\ReadingLists\ReadingListRepository;
use MediaWiki\Extension\ReadingLists\ReadingListRepositoryFactory;
use MediaWiki\Extension\ReadingLists\Validator\ReadingListPreferenceEligibilityValidator;
use MediaWiki\User\UserEditTracker;
use MediaWiki\User\UserIdentity;
use MediaWiki\Watchlist\WatchedItemStoreInterface;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @covers \MediaWiki\Extension\ReadingLists\Validator\ReadingListPreferenceEligibilityValidator
 */
class ReadingListPreferenceEligibilityValidatorTest extends \MediaWikiUnitTestCase {

	private UserEditTracker&MockObject $userEditTracker;
	private WatchedItemStoreInterface&MockObject $watchedItemStore;
	private ReadingListRepositoryFactory&MockObject $repositoryFactory;

	protected function setUp(): void {
		parent::setUp();
		$this->userEditTracker = $this->createMock( UserEditTracker::class );
		$this->watchedItemStore = $this->createMock( WatchedItemStoreInterface::class );
		$this->repositoryFactory = $this->createMock( ReadingListRepositoryFactory::class );
	}

	private function createValidator(): ReadingListPreferenceEligibilityValidator {
		return new ReadingListPreferenceEligibilityValidator(
			$this->userEditTracker,
			$this->watchedItemStore,
			$this->repositoryFactory
		);
	}

	private function createMockUser( int $userId ): UserIdentity&MockObject {
		return $this->createConfiguredMock( UserIdentity::class, [
			'getId' => $userId
		] );
	}

	private function createValidatorWithMockedRepository(
		UserIdentity $user,
		?string $listId
	): ReadingListPreferenceEligibilityValidator {
		$mockRepository = $this->createMock( ReadingListRepository::class );
		$mockRepository->expects( $this->once() )
			->method( 'getDefaultListIdForUser' )
			->willReturn( $listId );

		$this->repositoryFactory->expects( $this->once() )
			->method( 'getInstanceForUser' )
			->with( $user )
			->willReturn( $mockRepository );

		return $this->createValidator();
	}

	public function testIsEligible() {
		$user = $this->createMockUser( 123 );
		$validator = $this->createValidatorWithMockedRepository( $user, null );

		$this->userEditTracker->expects( $this->once() )
			->method( 'getUserEditCount' )
			->with( $user )
			->willReturn( 0 );

		$this->watchedItemStore->expects( $this->once() )
			->method( 'countWatchedItems' )
			->with( $user )
			->willReturn( 2 );

		$result = $validator->isEligible( $user );

		$this->assertTrue( $result );
	}

	public function testIsEligible_userWithEdits() {
		$validator = $this->createValidator();
		$user = $this->createMockUser( 123 );

		$this->userEditTracker->expects( $this->once() )
			->method( 'getUserEditCount' )
			->with( $user )
			->willReturn( 5 );

		// not needed to check if user has reading list
		$this->repositoryFactory->expects( $this->never() )
			->method( 'getInstanceForUser' );

		// does not need to check watched items
		$this->watchedItemStore->expects( $this->never() )
			->method( 'countWatchedItems' );

		$result = $validator->isEligible( $user );

		$this->assertFalse( $result );
	}

	public function testIsEligible_userHasReadingList() {
		$user = $this->createMockUser( 123 );
		$validator = $this->createValidatorWithMockedRepository( $user, 'default-list-id' );

		$this->userEditTracker->expects( $this->once() )
			->method( 'getUserEditCount' )
			->with( $user )
			->willReturn( 0 );

		// does not need to check watched items
		$this->watchedItemStore->expects( $this->never() )
			->method( 'countWatchedItems' );

		$result = $validator->isEligible( $user );

		$this->assertFalse( $result );
	}

	public function testIsEligible_userWithTooManyWatchedItems() {
		$user = $this->createMockUser( 123 );

		$validator = $this->createValidatorWithMockedRepository( $user, null );

		$this->userEditTracker->expects( $this->once() )
			->method( 'getUserEditCount' )
			->with( $user )
			->willReturn( 0 );

		$this->watchedItemStore->expects( $this->once() )
			->method( 'countWatchedItems' )
			->with( $user )
			->willReturn( 5 );

		$result = $validator->isEligible( $user );

		$this->assertFalse( $result );
	}

}
