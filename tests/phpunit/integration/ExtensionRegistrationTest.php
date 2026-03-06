<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Integration;

use MediaWiki\Extension\BetaFeatures\BetaFeatures;
use MediaWiki\Extension\ReadingLists\Constants;
use MediaWiki\MainConfigNames;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\User\Registration\UserRegistrationLookup;
use MediaWiki\User\UserIdentity;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\ReadingLists\ExtensionRegistration
 * @group Database
 */
class ExtensionRegistrationTest extends MediaWikiIntegrationTestCase {

	private const CUTOFF = '20260201000000';

	protected function setUp(): void {
		parent::setUp();

		if ( !ExtensionRegistry::getInstance()->isLoaded( 'BetaFeatures' ) ) {
			$this->markTestSkipped( 'Test requires the BetaFeatures extension' );
		}

		$this->overrideConfigValues( [
			'ReadingListBetaFeature' => true,
			MainConfigNames::ConditionalUserOptions => [
				Constants::PREF_KEY_BETA_FEATURES => [
					[ 1, [ CUDCOND_AFTER, self::CUTOFF ] ]
				]
			],
		] );
	}

	private function setUserRegistration( string $timestamp ): void {
		$registrationLookup = $this->createMock( UserRegistrationLookup::class );
		$registrationLookup->method( 'getRegistration' )
			->willReturnCallback(
				static fn ( UserIdentity $user ) => $timestamp
			);
		$this->setService( 'UserRegistrationLookup', $registrationLookup );
	}

	public function testNewAccountGetsFeatureEnabledByDefault() {
		$this->setUserRegistration( '20260301000000' );
		$user = $this->getTestUser()->getUser();

		$this->assertTrue(
			BetaFeatures::isFeatureEnabled( $user, Constants::PREF_KEY_BETA_FEATURES ),
			'Feature should be enabled by default for accounts created after cutoff'
		);
	}

	public function testOldAccountDoesNotGetFeatureEnabledByDefault() {
		$this->setUserRegistration( '20250101000000' );
		$user = $this->getTestUser()->getUser();

		$this->assertFalse(
			BetaFeatures::isFeatureEnabled( $user, Constants::PREF_KEY_BETA_FEATURES ),
			'Feature should not be enabled by default for accounts created before cutoff'
		);
	}

	public function testNewAccountCanOptOut() {
		$this->setUserRegistration( '20260301000000' );
		$user = $this->getTestUser()->getUser();

		$userOptionsManager = $this->getServiceContainer()->getUserOptionsManager();
		$userOptionsManager->setOption( $user, Constants::PREF_KEY_BETA_FEATURES, 0 );
		$userOptionsManager->saveOptions( $user );

		$this->assertFalse(
			BetaFeatures::isFeatureEnabled( $user, Constants::PREF_KEY_BETA_FEATURES ),
			'Feature should be disabled when user explicitly opts out'
		);
	}

	public function testOldAccountCanOptIn() {
		$this->setUserRegistration( '20250101000000' );
		$user = $this->getTestUser()->getUser();

		$userOptionsManager = $this->getServiceContainer()->getUserOptionsManager();
		$userOptionsManager->setOption( $user, Constants::PREF_KEY_BETA_FEATURES, 1 );
		$userOptionsManager->saveOptions( $user );

		$this->assertTrue(
			BetaFeatures::isFeatureEnabled( $user, Constants::PREF_KEY_BETA_FEATURES ),
			'Feature should be enabled when user explicitly opts in'
		);
	}
}
