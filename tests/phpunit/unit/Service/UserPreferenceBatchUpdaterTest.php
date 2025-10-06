<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Service;

use MediaWiki\Extension\ReadingLists\Service\UserPreferenceBatchUpdater;
use MediaWiki\User\Options\UserOptionsManager;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use PHPUnit\Framework\MockObject\MockObject;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\LBFactory;

/**
 * @covers \MediaWiki\Extension\ReadingLists\Service\UserPreferenceBatchUpdater
 */
class UserPreferenceBatchUpdaterTest extends \MediaWikiUnitTestCase {

	private LBFactory&MockObject $lbFactory;
	private UserFactory&MockObject $userFactory;
	private UserOptionsManager&MockObject $userOptionsManager;
	private IDatabase&MockObject $database;

	protected function setUp(): void {
		parent::setUp();
		$this->lbFactory = $this->createMock( LBFactory::class );
		$this->userFactory = $this->createMock( UserFactory::class );
		$this->userOptionsManager = $this->createMock( UserOptionsManager::class );
		$this->database = $this->createMock( IDatabase::class );

		$this->lbFactory->method( 'getPrimaryDatabase' )
			->willReturn( $this->database );
	}

	private function createService(): UserPreferenceBatchUpdater {
		return new UserPreferenceBatchUpdater(
			$this->lbFactory,
			$this->userFactory,
			$this->userOptionsManager
		);
	}

	public function testAddUserPreference() {
		$service = $this->createService();
		$user = $this->createConfiguredMock( UserIdentity::class, [
			'getId' => 123
		] );

		$this->assertFalse( $service->hasPendingUpdates() );

		$service->addUserPreference( $user, 'test-pref', 'test-value' );

		$this->assertTrue( $service->hasPendingUpdates() );
	}

	public function testAddMultipleUserPreferences() {
		$service = $this->createService();

		$user1 = $this->createConfiguredMock( UserIdentity::class, [
			'getId' => 123
		] );

		$user2 = $this->createConfiguredMock( UserIdentity::class, [
			'getId' => 456
		] );

		$service->addUserPreference( $user1, 'pref1', 'value1' );
		$service->addUserPreference( $user2, 'pref2', 'value2' );
		$service->addUserPreference( $user2, 'pref3', 'value3' );

		$this->assertTrue( $service->hasPendingUpdates() );
	}

	public function testAddMultipleUserPreferences_rejectedDuplicates() {
		$service = $this->createService();

		$user1 = $this->createConfiguredMock( UserIdentity::class, [
			'getId' => 123
		] );

		$service->addUserPreference( $user1, 'pref1', 'value1' );

		$this->expectException( \InvalidArgumentException::class );

		$service->addUserPreference( $user1, 'pref1', 'value2' );
	}

	public function testHasPendingUpdates_noPendingUpdates() {
		$service = $this->createService();

		$this->assertFalse( $service->hasPendingUpdates() );
	}

	public function testExecuteBatchUpdate_noPendingUpdates() {
		$service = $this->createService();

		// no pending updates to insert
		$this->database->expects( $this->never() )
			->method( 'insert' );

		$result = $service->executeBatchUpdate();

		$this->assertSame( 0, $result );
		$this->assertFalse( $service->hasPendingUpdates() );
	}

	public function testExecuteBatchUpdate() {
		$service = $this->createService();
		$user = $this->createConfiguredMock( UserIdentity::class, [
			'getId' => 123
		] );

		$mockUser = $this->createMock( User::class );
		$this->userFactory->expects( $this->once() )
			->method( 'newFromId' )
			->with( 123 )
			->willReturn( $mockUser );

		$mockUser->expects( $this->once() )
			->method( 'touch' );

		$this->userOptionsManager->expects( $this->once() )
			->method( 'clearUserOptionsCache' )
			->with( $mockUser );

		$this->database->expects( $this->once() )
			->method( 'insert' )
			->with(
				'user_properties',
				[
					[
						'up_user' => 123,
						'up_property' => 'test-pref',
						'up_value' => 'test-value'
					]
				],
				$this->anything()
			);

		$service->addUserPreference( $user, 'test-pref', 'test-value' );
		$result = $service->executeBatchUpdate();

		$this->assertSame( 1, $result );
		$this->assertFalse( $service->hasPendingUpdates() );
	}

	public function testExecuteBatchUpdate_multipleUsers() {
		$service = $this->createService();

		$user1 = $this->createConfiguredMock( UserIdentity::class, [
			'getId' => 123
		] );

		$user2 = $this->createConfiguredMock( UserIdentity::class, [
			'getId' => 456
		] );

		$mockUser1 = $this->createMock( User::class );
		$mockUser2 = $this->createMock( User::class );

		$this->userFactory->expects( $this->exactly( 2 ) )
			->method( 'newFromId' )
			->willReturnMap( [
				[ 123, $mockUser1 ],
				[ 456, $mockUser2 ]
			] );

		$mockUser1->expects( $this->once() )->method( 'touch' );
		$mockUser2->expects( $this->once() )->method( 'touch' );

		$this->userOptionsManager->expects( $this->exactly( 2 ) )
			->method( 'clearUserOptionsCache' )
			->with( $this->logicalOr( $mockUser1, $mockUser2 ) );

		$this->database->expects( $this->once() )
			->method( 'insert' )
			->with(
				'user_properties',
				[
					[
						'up_user' => 123,
						'up_property' => 'pref1',
						'up_value' => 'value1'
					],
					[
						'up_user' => 456,
						'up_property' => 'pref2',
						'up_value' => 'value2'
					]
				],
				$this->anything()
			);

		$service->addUserPreference( $user1, 'pref1', 'value1' );
		$service->addUserPreference( $user2, 'pref2', 'value2' );

		$result = $service->executeBatchUpdate();

		$this->assertSame( 2, $result );
		$this->assertFalse( $service->hasPendingUpdates() );
	}

	public function testExecuteBatchUpdate_multipleBatches() {
		$service = $this->createService();
		$user = $this->createConfiguredMock( UserIdentity::class, [
			'getId' => 123
		] );

		$mockUser = $this->createMock( User::class );
		$this->userFactory->method( 'newFromId' )->willReturn( $mockUser );
		$mockUser->method( 'touch' );
		$this->userOptionsManager->method( 'clearUserOptionsCache' );

		$this->database->expects( $this->exactly( 2 ) )
			->method( 'insert' )
			->with( 'user_properties', $this->anything(), $this->anything() );

		$service->addUserPreference( $user, 'pref1', 'value1' );
		$this->assertTrue( $service->hasPendingUpdates() );

		$result1 = $service->executeBatchUpdate();
		$this->assertSame( 1, $result1 );
		$this->assertFalse( $service->hasPendingUpdates() );

		$service->addUserPreference( $user, 'pref2', 'value2' );
		$this->assertTrue( $service->hasPendingUpdates() );

		$result2 = $service->executeBatchUpdate();
		$this->assertSame( 1, $result2 );
		$this->assertFalse( $service->hasPendingUpdates() );
	}

}
