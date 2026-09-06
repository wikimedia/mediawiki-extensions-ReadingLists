<?php

namespace MediaWiki\Extension\ReadingLists\Tests;

use MediaWiki\Extension\ReadingLists\ReadingListRepositoryFactory;
use MediaWiki\Extension\ReadingLists\ReverseInterwikiLookupInterface;
use MediaWikiIntegrationTestCase;

/**
 * @coversNothing
 */
class ServiceWiringTest extends MediaWikiIntegrationTestCase {

	public function testReadingListRepositoryFactory() {
		$service = $this->getServiceContainer()->getService( 'ReadingLists.ReadingListRepositoryFactory' );
		$this->assertInstanceOf( ReadingListRepositoryFactory::class, $service );
	}

	public function testReverseInterwikiLookup() {
		$service = $this->getServiceContainer()->getService( 'ReadingLists.ReverseInterwikiLookup' );
		$this->assertInstanceOf( ReverseInterwikiLookupInterface::class, $service );
	}

}
