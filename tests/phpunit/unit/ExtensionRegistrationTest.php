<?php

namespace MediaWiki\Extension\ReadingLists\Tests;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\ReadingLists\ExtensionRegistration;
use MediaWiki\MainConfigNames;
use MediaWiki\Settings\SettingsBuilder;
use RuntimeException;

/**
 * @covers \MediaWiki\Extension\ReadingLists\ExtensionRegistration
 */
class ExtensionRegistrationTest extends \MediaWikiUnitTestCase {

	private function invokeOnRegistration( array $configValues ): void {
		$config = new HashConfig( $configValues );
		$settings = $this->createMock( SettingsBuilder::class );
		$settings->method( 'getConfig' )->willReturn( $config );
		$settings->expects( $this->any() )
			->method( 'putConfigValue' )
			->willReturnSelf();

		ExtensionRegistration::onRegistration( [], $settings );
	}

	public function testThrowsWhenBetaAndWebEnabled() {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage(
			'ReadingListBetaFeature and ReadingListsEnabled cannot both be enabled'
		);

		$this->invokeOnRegistration( [
			'ReadingListBetaFeature' => true,
			'ReadingListsEnabled' => true,
			'ReadingListsBetaDefaultForNewAccountsAfter' => null,
			MainConfigNames::ConditionalUserOptions => [],
		] );
	}

	public function testDoesNotThrowWhenOnlyBetaEnabled() {
		$this->expectNotToPerformAssertions();

		$this->invokeOnRegistration( [
			'ReadingListBetaFeature' => true,
			'ReadingListsEnabled' => false,
			'ReadingListsBetaDefaultForNewAccountsAfter' => null,
			MainConfigNames::ConditionalUserOptions => [],
		] );
	}

	public function testDoesNotThrowWhenOnlyWebEnabled() {
		$this->expectNotToPerformAssertions();

		$this->invokeOnRegistration( [
			'ReadingListBetaFeature' => false,
			'ReadingListsEnabled' => true,
			'ReadingListsBetaDefaultForNewAccountsAfter' => null,
			MainConfigNames::ConditionalUserOptions => [],
		] );
	}
}
