<?php

namespace MediaWiki\Extension\ReadingLists\Tests;

use MediaWiki\Extension\ReadingLists\LocalProjectHelper;
use MediaWiki\MediaWikiServices;
use MediaWikiIntegrationTestCase;

/**
 * @group Database
 * @covers \MediaWiki\Extension\ReadingLists\LocalProjectHelper
 */
class LocalProjectHelperTest extends MediaWikiIntegrationTestCase {

	public function testGetLocalProject(): void {
		$urlUtils = MediaWikiServices::getInstance()->getUrlUtils();
		$parts = $urlUtils->parse( $urlUtils->getCanonicalServer() );
		$parts['port'] = null;

		$this->assertSame(
			$urlUtils->assemble( $parts ),
			LocalProjectHelper::getLocalProject()
		);
	}

	public function testIsLocalProject(): void {
		$localProject = LocalProjectHelper::getLocalProject();

		$this->assertTrue( LocalProjectHelper::isLocalProject( '@local', $localProject ) );
		$this->assertTrue( LocalProjectHelper::isLocalProject( $localProject, $localProject ) );
		$this->assertFalse(
			LocalProjectHelper::isLocalProject( 'https://commons.wikimedia.org', $localProject )
		);
	}
}
