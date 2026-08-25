<?php

namespace MediaWiki\Extension\ReadingLists\Tests;

use MediaWiki\Extension\ReadingLists\Utils;
use MediaWikiIntegrationTestCase;
use Wikimedia\Timestamp\ConvertibleTimestamp;

class UtilsTest extends MediaWikiIntegrationTestCase {
	/**
	 * @covers \MediaWiki\Extension\ReadingLists\Utils::getDeletedExpiry
	 */
	public function testGetDeletedExpiry() {
		$this->overrideConfigValue( 'ReadingListsDeletedRetentionDays', 15 );
		ConvertibleTimestamp::setFakeTime( '20200116000000' );

		$this->assertSame( '20200101000000', Utils::getDeletedExpiry() );
	}
}
