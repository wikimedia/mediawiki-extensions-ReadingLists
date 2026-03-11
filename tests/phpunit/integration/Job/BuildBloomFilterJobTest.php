<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Integration\Job;

use MediaWiki\Extension\ReadingLists\Job\BuildBloomFilterJob;
use MediaWiki\Extension\ReadingLists\Service\BookmarkEntryLookupService;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\ReadingLists\Job\BuildBloomFilterJob
 */
class BuildBloomFilterJobTest extends MediaWikiIntegrationTestCase {

	public function testRunRebuildsBloomFilterForCentralId(): void {
		$service = $this->createMock( BookmarkEntryLookupService::class );
		$service->expects( $this->once() )->method( 'rebuildBloomFilter' )
			->with( 42 );
		$this->setService( 'ReadingLists.BookmarkEntryLookupService', $service );

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
