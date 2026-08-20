<?php

namespace MediaWiki\Extension\ReadingLists\Tests;

use MediaWiki\Extension\ReadingLists\ReadingListRepositoryFactory;
use MediaWiki\Extension\ReadingLists\ReverseInterwikiLookupInterface;
use MediaWiki\MediaWikiServices;

/**
 * @coversNothing
 */
class ServiceWiringTest extends \PHPUnit\Framework\TestCase {

	public function testReadingListRepositoryFactory() {
		$service = MediaWikiServices::getInstance()->getService( 'ReadingLists.ReadingListRepositoryFactory' );
		$this->assertInstanceOf( ReadingListRepositoryFactory::class, $service );
	}

	public function testReverseInterwikiLookup() {
		$service = MediaWikiServices::getInstance()->getService( 'ReadingLists.ReverseInterwikiLookup' );
		$this->assertInstanceOf( ReverseInterwikiLookupInterface::class, $service );
	}

}
