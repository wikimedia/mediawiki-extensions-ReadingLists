<?php

namespace MediaWiki\Extensions\ReadingLists;

use Closure;
use MediaWiki\MediaWikiServices;
use MediaWikiTestCase;
use Wikimedia\Rdbms\DBConnRef;

class UtilsTest extends MediaWikiTestCase {

	public function testGetDB() {
		$dbw = Utils::getDB( DB_MASTER, MediaWikiServices::getInstance() );
		$dbr = Utils::getDB( DB_REPLICA, MediaWikiServices::getInstance() );
		$this->assertInstanceOf( DBConnRef::class, $dbw );
		$this->assertInstanceOf( DBConnRef::class, $dbr );
	}

	/**
	 * @dataProvider provideIsCentralWiki
	 */
	public function testIsCentralWiki( $wgReadingListsCentralWiki, $expectedResult ) {
		// Wiki name is changed between the data provider and the test so allow delayed lookup.
		if ( $wgReadingListsCentralWiki instanceof Closure ) {
			$wgReadingListsCentralWiki = $wgReadingListsCentralWiki();
		}
		$this->setMwGlobals( 'wgReadingListsCentralWiki', $wgReadingListsCentralWiki );
		$this->assertSame( $expectedResult, Utils::isCentralWiki( MediaWikiServices::getInstance() ) );
	}

	public function provideIsCentralWiki() {
		$wfWikiID = function () {
			return wfWikiID();
		};
		return [
			[ false, true ],
			[ $wfWikiID, true ],
			[ 'foo', false ],
		];
	}

}
