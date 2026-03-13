<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Integration\Maintenance;

use MediaWiki\Extension\ReadingLists\Constants;
use MediaWiki\Extension\ReadingLists\Maintenance\SetBetaPreference;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use MediaWiki\User\User;

require_once __DIR__ . '/../../../../maintenance/setBetaPreference.php';

/**
 * @group Database
 * @covers \MediaWiki\Extension\ReadingLists\Maintenance\SetBetaPreference
 */
class SetBetaPreferenceTest extends MaintenanceBaseTestCase {

	protected function getMaintenanceClass() {
		return SetBetaPreference::class;
	}

	public function testCopiesPreferenceForEligibleUsers() {
		$user1 = $this->getMutableTestUser()->getUser();
		$user2 = $this->getMutableTestUser()->getUser();

		$this->setHiddenPreference( $user1, '1' );
		$this->setHiddenPreference( $user2, '1' );

		$this->maintenance->execute();

		$this->assertSame( '1', $this->getBetaPreferenceFromDb( $user1 ) );
		$this->assertSame( '1', $this->getBetaPreferenceFromDb( $user2 ) );
	}

	public function testSkipsUsersWithoutOldPreference() {
		$user = $this->getMutableTestUser()->getUser();

		$this->maintenance->execute();

		$this->assertFalse( $this->getBetaPreferenceFromDb( $user ) );
	}

	public function testSkipsUsersWithExistingBetaPreference() {
		$user = $this->getMutableTestUser()->getUser();
		$this->setHiddenPreference( $user, '1' );
		$this->setBetaPreference( $user, '0' );

		$this->maintenance->execute();

		$this->assertSame( '0', $this->getBetaPreferenceFromDb( $user ) );
	}

	public function testRespectsLimit() {
		$user1 = $this->getMutableTestUser()->getUser();
		$user2 = $this->getMutableTestUser()->getUser();
		$user3 = $this->getMutableTestUser()->getUser();

		$this->setHiddenPreference( $user1, '1' );
		$this->setHiddenPreference( $user2, '1' );
		$this->setHiddenPreference( $user3, '1' );

		$this->maintenance->setOption( 'limit', 2 );
		$this->maintenance->execute();

		$copiedCount = 0;
		foreach ( [ $user1, $user2, $user3 ] as $u ) {
			if ( $this->getBetaPreferenceFromDb( $u ) === '1' ) {
				$copiedCount++;
			}
		}

		$this->assertSame( 2, $copiedCount );
	}

	public function testDryRunDoesNotWrite() {
		$user = $this->getMutableTestUser()->getUser();
		$this->setHiddenPreference( $user, '1' );

		$this->maintenance->setOption( 'dry-run', true );
		$this->maintenance->execute();

		$this->assertFalse( $this->getBetaPreferenceFromDb( $user ) );
	}

	public function testDryRunOutputUsesWouldCopy() {
		$user = $this->getMutableTestUser()->getUser();
		$this->setHiddenPreference( $user, '1' );

		$this->maintenance->setOption( 'dry-run', true );
		$this->maintenance->execute();

		$output = $this->getActualOutputForAssertion();
		$this->assertStringContainsString( '[dry run]', $output );
		$this->assertStringContainsString( 'would copy 1', $output );
	}

	private function getUserOptionsManager() {
		return $this->getServiceContainer()->getUserOptionsManager();
	}

	private function setHiddenPreference( User $user, string $value ): void {
		$this->getUserOptionsManager()->setOption( $user, Constants::PREF_KEY_WEB_UI_ENABLED, $value );
		$this->getUserOptionsManager()->saveOptions( $user );
	}

	private function setBetaPreference( User $user, string $value ): void {
		$this->getUserOptionsManager()->setOption( $user, Constants::PREF_KEY_BETA_FEATURES, $value );
		$this->getUserOptionsManager()->saveOptions( $user );
	}

	/**
	 * Uses a direct DB query instead of getOption() because getOption()
	 * returns the BetaFeatures default ('0') when no row exists, making
	 * it impossible to distinguish "no row written" from "row set to '0'".
	 *
	 * @return string|false false if no row exists
	 */
	private function getBetaPreferenceFromDb( User $user ) {
		$dbr = $this->getServiceContainer()->getConnectionProvider()->getReplicaDatabase();
		return $dbr->newSelectQueryBuilder()
			->select( 'up_value' )
			->from( 'user_properties' )
			->where( [
				'up_user' => $user->getId(),
				'up_property' => Constants::PREF_KEY_BETA_FEATURES,
			] )
			->caller( __METHOD__ )
			->fetchField();
	}
}
