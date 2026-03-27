<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Integration\Job;

use MediaWiki\Extension\ReadingLists\Job\BuildBloomFilterJob;
use MediaWiki\Extension\ReadingLists\Service\BookmarkBloomFilterCache;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\ReadingLists\Job\BuildBloomFilterJob
 */
class BuildBloomFilterJobTest extends MediaWikiIntegrationTestCase {

	public function testRunRebuildsBloomFilterForCentralId(): void {
		$cache = $this->createMock( BookmarkBloomFilterCache::class );
		$cache->expects( $this->once() )->method( 'rebuildBloomFilter' )
			->with( 42 );
		$this->setService( 'ReadingLists.BookmarkBloomFilterCache', $cache );

		$job = new BuildBloomFilterJob( [ 'centralId' => 42 ] );

		$this->assertTrue( $job->run() );
	}

	public function testConstructorConfiguresDeduplicatedJob(): void {
		$job = new BuildBloomFilterJob( [ 'centralId' => 42 ] );

		$this->assertSame( 'buildBookmarkBloomFilter', $job->getType() );
		$this->assertTrue( $job->ignoreDuplicates() );
		$this->assertSame( 42, $job->getParams()['centralId'] );
	}
}
