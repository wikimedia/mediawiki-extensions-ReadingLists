<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Integration;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\ReadingLists\BetaFeatureHookHandler;
use MediaWiki\Extension\ReadingLists\Constants;
use MediaWiki\Skin\Skin;
use MediaWiki\Tests\User\TempUser\TempUserTestTrait;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\ReadingLists\BetaFeatureHookHandler
 */
class BetaFeatureHookHandlerTest extends MediaWikiIntegrationTestCase {
	use TempUserTestTrait;

	public function testOnGetBetaFeaturePreferencesDoesNotReadSkinNameIfNotFullyInitialised(): void {
		$this->overrideConfigValue( 'ReadingListBetaFeature', true );
		$this->setMwGlobals( 'wgFullyInitialised', false );
		$this->setTemporaryHook(
			'RequestContextCreateSkin',
			function () {
				$this->fail( 'Skin name should not be read when wgFullyInitialised is false' );
			}
		);

		$hookHandler = new BetaFeatureHookHandler( $this->getServiceContainer()->getMainConfig() );

		// Ensure that the skin hook has not been called before calling the code under test,
		// as the result of the hook is cached in the main instance of RequestContext
		RequestContext::resetMain();

		$prefs = [];
		$hookHandler->onGetBetaFeaturePreferences( $this->createMock( User::class ), $prefs );

		$this->assertArrayNotHasKey( Constants::PREF_KEY_BETA_FEATURES, $prefs );
	}

	public function testOnGetBetaFeaturePreferencesWhenSkinNameNotSupported(): void {
		$this->overrideConfigValue( 'ReadingListBetaFeature', true );
		$this->setTemporaryHook(
			'RequestContextCreateSkin',
			function ( $ignored, &$skin ) {
				$skin = $this->createMock( Skin::class );
				$skin->method( 'getSkinName' )
					->willReturn( 'unsupported-skin' );
			}
		);

		$hookHandler = new BetaFeatureHookHandler( $this->getServiceContainer()->getMainConfig() );

		RequestContext::resetMain();

		$prefs = [];
		$hookHandler->onGetBetaFeaturePreferences( $this->createMock( User::class ), $prefs );

		$this->assertArrayNotHasKey( Constants::PREF_KEY_BETA_FEATURES, $prefs );
	}

	public function testOnGetBetaFeaturePreferencesWhenSkinNameSupported(): void {
		$this->overrideConfigValue( 'ReadingListBetaFeature', true );
		$this->setTemporaryHook(
			'RequestContextCreateSkin',
			function ( $ignored, &$skin ) {
				$skin = $this->createMock( Skin::class );
				$skin->method( 'getSkinName' )
					->willReturn( 'vector-2022' );
			}
		);

		$hookHandler = new BetaFeatureHookHandler( $this->getServiceContainer()->getMainConfig() );

		RequestContext::resetMain();

		$prefs = [];
		$hookHandler->onGetBetaFeaturePreferences( $this->createMock( User::class ), $prefs );

		$this->assertArrayHasKey( Constants::PREF_KEY_BETA_FEATURES, $prefs );
	}
}
