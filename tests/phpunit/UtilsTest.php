<?php

namespace MediaWiki\Extension\ReadingLists\Tests;

use Closure;
use MediaWiki\Extension\ReadingLists\Utils;
use MediaWiki\MediaWikiServices;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use Wikimedia\Rdbms\IDatabase;

class UtilsTest extends MediaWikiIntegrationTestCase {

	/**
	 * @covers \MediaWiki\Extension\ReadingLists\Utils::getDB
	 */
	public function testGetDB() {
		$dbw = Utils::getDB( DB_PRIMARY, MediaWikiServices::getInstance() );
		$dbr = Utils::getDB( DB_REPLICA, MediaWikiServices::getInstance() );
		$this->assertInstanceOf( IDatabase::class, $dbw );
		$this->assertInstanceOf( IDatabase::class, $dbr );
	}

	/**
	 * @dataProvider provideIsCentralWiki
	 * @covers \MediaWiki\Extension\ReadingLists\Utils::isCentralWiki
	 */
	public function testIsCentralWiki( $readingListsCentralWiki, $expectedResult ) {
		// Wiki name is changed between the data provider and the test so allow delayed lookup.
		if ( $readingListsCentralWiki instanceof Closure ) {
			$readingListsCentralWiki = $readingListsCentralWiki();
		}
		$this->setMwGlobals( 'wgReadingListsCentralWiki', $readingListsCentralWiki );
		$this->assertSame( $expectedResult, Utils::isCentralWiki( MediaWikiServices::getInstance() ) );
	}

	public static function provideIsCentralWiki() {
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
