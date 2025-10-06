<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Integration\Maintenance;

use MediaWiki\Extension\ReadingLists\Constants;
use MediaWiki\Extension\ReadingLists\Maintenance\SetReadingListHiddenPreference;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use MediaWiki\User\Options\UserOptionsManager;
use MediaWiki\User\User;

require_once __DIR__ . '/../../../../maintenance/setReadingListHiddenPreference.php';

/**
 * @group Database
 * @covers \MediaWiki\Extension\ReadingLists\Maintenance\SetReadingListHiddenPreference
 */
class SetReadingListHiddenPreferenceTest extends MaintenanceBaseTestCase {

	protected function getMaintenanceClass() {
		return SetReadingListHiddenPreference::class;
	}

	public function testExecute_withValidUserIds() {
		$user1 = $this->getTestUser()->getUser();
		$user2 = $this->getTestUser()->getUser();

		$this->assertReadingListPreferenceNotSet( $user1 );
		$this->assertReadingListPreferenceNotSet( $user2 );

		$userIds = $user1->getId() . "\n" . $user2->getId();
		$tempFile = $this->createTempTestFile( $userIds );

		try {
			$this->maintenance->setArg( 'file', $tempFile );
			$this->maintenance->setOption( 'skip-verify', true );

			$this->maintenance->execute();
		} finally {
			unlink( $tempFile );
		}

		$this->assertReadingListPreferenceSet( $user1 );
		$this->assertReadingListPreferenceSet( $user2 );
	}

	public function testExecute_withNonExistentUser() {
		$tempFile = $this->createTempTestFile( '999999' );

		try {
			$this->maintenance->setArg( 'file', $tempFile );
			$this->maintenance->setOption( 'skip-verify', true );
			$this->maintenance->setOption( 'verbose', true );

			$this->maintenance->execute();

			$output = $this->getActualOutputForAssertion();
			$this->assertStringContainsString( 'not found', $output );
		} finally {
			unlink( $tempFile );
		}
	}

	public function testExecute_withBatchSize() {
		$users = [];
		for ( $i = 0; $i < 5; $i++ ) {
			$users[] = $this->getTestUser()->getUser();
		}

		$userIds = implode( "\n", array_map( static fn ( $u ) => $u->getId(), $users ) );
		$tempFile = $this->createTempTestFile( $userIds );

		try {
			$this->maintenance->setArg( 'file', $tempFile );
			$this->maintenance->setOption( 'batch-size', 2 );
			$this->maintenance->setOption( 'skip-verify', true );

			$this->maintenance->execute();

			foreach ( $users as $user ) {
				$this->assertReadingListPreferenceSet( $user );
			}
		} finally {
			unlink( $tempFile );
		}
	}

	public function testExecute_withIdRange() {
		$user1 = $this->getTestUser()->getUser();
		$user2 = $this->getTestUser()->getUser();
		$user3 = $this->getTestUser()->getUser();
		$users = [ $user1, $user2, $user3 ];

		$userIds = [ $user1->getId(), $user2->getId(), $user3->getId() ];
		sort( $userIds, SORT_NUMERIC );
		$tempFile = $this->createTempTestFile( implode( "\n", $userIds ) );
		$targetId = $userIds[1];

		$this->assertReadingListPreferenceNotSet( $user1 );
		$this->assertReadingListPreferenceNotSet( $user2 );
		$this->assertReadingListPreferenceNotSet( $user3 );

		try {
			$this->maintenance->setArg( 'file', $tempFile );
			$this->maintenance->setOption( 'start-id', $targetId );
			$this->maintenance->setOption( 'to-id', $targetId );
			$this->maintenance->setOption( 'skip-verify', true );

			$this->maintenance->execute();

			foreach ( $users as $user ) {
				$expectedValue = ( $user->getId() === $targetId ) ? '1' : '0';
				$this->assertReadingListPreferenceSet( $user, $expectedValue );
			}
		} finally {
			unlink( $tempFile );
		}
	}

	public function testExecute_skipsUsersWithPreferenceAlreadySet() {
		$user = $this->getTestUser()->getUser();

		$this->setReadingListPreference( $user, '1' );

		$tempFile = $this->createTempTestFile( (string)$user->getId() );

		try {
			$this->maintenance->setArg( 'file', $tempFile );
			$this->maintenance->setOption( 'skip-verify', true );
			$this->maintenance->setOption( 'verbose', true );

			$this->maintenance->execute();

			$output = $this->getActualOutputForAssertion();
			$this->assertStringContainsString( 'skipped: 1', $output );
		} finally {
			unlink( $tempFile );
		}
	}

	private function createTempTestFile( $content ): bool|string {
		$tempFile = tempnam( sys_get_temp_dir(), 'reading_list_test' );
		file_put_contents( $tempFile, $content );
		return $tempFile;
	}

	private function getUserOptionsManager(): UserOptionsManager {
		return $this->getServiceContainer()->getUserOptionsManager();
	}

	private function getReadingListPreference( User $user ) {
		return $this->getUserOptionsManager()->getOption( $user, Constants::PREF_KEY_WEB_UI_ENABLED );
	}

	private function setReadingListPreference( User $user, string $value ): void {
		$userOptionsManager = $this->getUserOptionsManager();
		$userOptionsManager->setOption( $user, Constants::PREF_KEY_WEB_UI_ENABLED, $value );
		$userOptionsManager->saveOptions( $user );
	}

	private function assertReadingListPreferenceSet( User $user, string $message = '' ): void {
		$user->clearInstanceCache();
		$this->assertSame( '1', $this->getReadingListPreference( $user ), $message );
	}

	private function assertReadingListPreferenceNotSet( User $user, string $message = '' ): void {
		$user->clearInstanceCache();
		$this->assertSame( '0', $this->getReadingListPreference( $user ), $message );
	}

}
