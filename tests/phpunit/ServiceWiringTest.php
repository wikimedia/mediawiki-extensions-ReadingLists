<?php

namespace MediaWiki\Extensions\ReadingLists\Tests;

use MediaWiki\MediaWikiServices;
use MediaWiki\Extensions\ReadingLists\ReverseInterwikiLookupInterface;

/**
 * @coversNothing
 */
class ServiceWiringTest extends \PHPUnit\Framework\TestCase {

	public function testService() {
		$service = MediaWikiServices::getInstance()->getService( 'ReverseInterwikiLookup' );
		$this->assertInstanceOf( ReverseInterwikiLookupInterface::class, $service );
	}

}
