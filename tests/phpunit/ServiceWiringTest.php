<?php

namespace MediaWiki\Extensions\ReadingLists;

use MediaWiki\MediaWikiServices;

/**
 * @coversNothing
 */
class ServiceWiringTest extends \PHPUnit\Framework\TestCase {

	public function testService() {
		$service = MediaWikiServices::getInstance()->getService( 'ReverseInterwikiLookup' );
		$this->assertInstanceOf( ReverseInterwikiLookupInterface::class, $service );
	}

}
