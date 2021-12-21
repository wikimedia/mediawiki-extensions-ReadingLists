<?php

namespace MediaWiki\Extensions\ReadingLists\Tests;

use Closure;
use MediaWiki\Extensions\ReadingLists\Utils;
use MediaWiki\MediaWikiServices;
use MediaWikiIntegrationTestCase;
use WikiMap;
use Wikimedia\Rdbms\DBConnRef;

class UtilsTest extends MediaWikiIntegrationTestCase {

	/**
	 * @covers \MediaWiki\Extensions\ReadingLists\Utils::getDB
	 */
	public function testGetDB() {
		$dbw = Utils::getDB( DB_PRIMARY, MediaWikiServices::getInstance() );
		$dbr = Utils::getDB( DB_REPLICA, MediaWikiServices::getInstance() );
		$this->assertInstanceOf( DBConnRef::class, $dbw );
		$this->assertInstanceOf( DBConnRef::class, $dbr );
	}

	/**
	 * @dataProvider provideIsCentralWiki
	 * @covers \MediaWiki\Extensions\ReadingLists\Utils::isCentralWiki
	 */
	public function testIsCentralWiki( $readingListsCentralWiki, $expectedResult ) {
		// Wiki name is changed between the data provider and the test so allow delayed lookup.
		if ( $readingListsCentralWiki instanceof Closure ) {
			$readingListsCentralWiki = $readingListsCentralWiki();
		}
		$this->setMwGlobals( 'wgReadingListsCentralWiki', $readingListsCentralWiki );
		$this->assertSame( $expectedResult, Utils::isCentralWiki( MediaWikiServices::getInstance() ) );
	}

	public function provideIsCentralWiki() {
		$currentWikiId = static function () {
			return WikiMap::getCurrentWikiId();
		};
		return [
			[ false, true ],
			[ $currentWikiId, true ],
			[ 'foo', false ],
		];
	}

	/**
	 * @covers \MediaWiki\Extensions\ReadingLists\Utils::getDeletedExpiry
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
