<?php

namespace MediaWiki\Extension\ReadingLists\Tests;

use MediaWiki\Extension\ReadingLists\Utils;
use MediaWikiIntegrationTestCase;

/**
 * @group Database
 */
class UtilsTest extends MediaWikiIntegrationTestCase {
	/**
	 * @covers \MediaWiki\Extension\ReadingLists\Utils::getDeletedExpiry
	 */
	public function testGetDeletedExpiry() {
		$this->setMwGlobals( 'wgReadingListsDeletedRetentionDays', 15 );
		$actualTimestamp = Utils::getDeletedExpiry();
		$expectedTimestamp = wfTimestamp( TS_MW, strtotime( "-15 days" ) );
		$delta = abs( $expectedTimestamp - $actualTimestamp );
		$this->assertLessThanOrEqual( 10, $delta,
			"Difference between expected timestamp ($expectedTimestamp) "
			. "and actual timetamp ($actualTimestamp) is too large" );
	}
}
