<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Unit\Service;

use MediaWiki\Extension\ReadingLists\Job\BuildBloomFilterJob;
use MediaWiki\Extension\ReadingLists\ReadingListRepository;
use MediaWiki\Extension\ReadingLists\ReadingListRepositoryException;
use MediaWiki\Extension\ReadingLists\ReadingListRepositoryFactory;
use MediaWiki\Extension\ReadingLists\Service\BookmarkEntryLookupService;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\Title\Title;
use MediaWiki\User\CentralId\CentralIdLookup;
use MediaWiki\User\CentralId\CentralIdLookupFactory;
use MediaWiki\User\UserIdentity;
use MediaWikiUnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\DBError;
use Wikimedia\Rdbms\FakeResultWrapper;
use Wikimedia\Rdbms\IDBAccessObject;

/**
 * @covers \MediaWiki\Extension\ReadingLists\Service\BookmarkEntryLookupService
 */
class BookmarkEntryLookupServiceTest extends MediaWikiUnitTestCase {

	private const CENTRAL_ID = 42;
	private const MAX_ITEMS = 5;

	private WANObjectCache $cache;
	private HashBagOStuff $cacheBackend;

	protected function setUp(): void {
		parent::setUp();
		$this->cacheBackend = new HashBagOStuff();
		$this->cache = new WANObjectCache( [ 'cache' => $this->cacheBackend ] );
	}

	private function createService(
		ReadingListRepository $repository,
		int $maxItems = self::MAX_ITEMS,
		?JobQueueGroup $jobQueueGroup = null
	): BookmarkEntryLookupService {
		/** @var ReadingListRepositoryFactory&MockObject $factory */
		$factory = $this->createMock( ReadingListRepositoryFactory::class );
		$factory->method( 'create' )->willReturn( $repository );

		return new BookmarkEntryLookupService(
			$factory,
			$this->cache,
			$this->createMockCentralIdLookupFactory(),
			$jobQueueGroup ?? $this->createMock( JobQueueGroup::class ),
			new NullLogger(),
			$maxItems
		);
	}

	private function createMockCentralIdLookupFactory(): CentralIdLookupFactory {
		$lookup = $this->createMock( CentralIdLookup::class );
		$lookup->method( 'centralIdFromLocalUser' )->willReturn( self::CENTRAL_ID );

		/** @var CentralIdLookupFactory&MockObject $factory */
		$factory = $this->createMock( CentralIdLookupFactory::class );
		$factory->method( 'getLookup' )->willReturn( $lookup );

		return $factory;
	}

	private function createTitle( string $dbKey ): Title {
		$title = $this->createMock( Title::class );
		$title->method( 'getPrefixedDBkey' )->willReturn( $dbKey );
		$title->method( 'getPrefixedText' )->willReturn( str_replace( '_', ' ', $dbKey ) );
		return $title;
	}

	/**
	 * @return ReadingListRepository&MockObject
	 */
	private function createMockRepository( array $savedTitles = [] ): ReadingListRepository&MockObject {
		$repository = $this->createMock( ReadingListRepository::class );
		$repository->method( 'getSavedPagesCacheSetOptions' )
			->willReturn( [] );
		$repository->method( 'getSavedPageTitlesForProject' )
			->willReturn( $savedTitles );
		return $repository;
	}

	public function testGetBookmarkEntry_returnsNullForNonSavedPage() {
		$repository = $this->createMockRepository( [ 'Cat', 'Dog' ] );
		$repository->expects( $this->never() )->method( 'getListsByPage' );

		$service = $this->createService( $repository );
		$service->rebuildBloomFilter( self::CENTRAL_ID );
		$status = $service->getBookmarkEntryStatus( $this->createTitle( 'Elephant' ), self::CENTRAL_ID );

		$this->assertTrue( $status->isOK() );
		$this->assertNull( $status->getValue() );
	}

	public function testGetBookmarkEntry_returnsReadingListEntryForSavedPage() {
		$entry = (object)[ 'rle_id' => 1, 'rle_title' => 'Cat' ];

		$repository = $this->createMockRepository( [ 'Cat', 'Dog' ] );
		$repository->method( 'getListsByPage' )
			->willReturn( new FakeResultWrapper( [ $entry ] ) );

		$service = $this->createService( $repository );
		$status = $service->getBookmarkEntryStatus( $this->createTitle( 'Cat' ), self::CENTRAL_ID );

		$this->assertTrue( $status->isOK() );
		$this->assertNotNull( $status->getValue() );
		$this->assertSame( 'Cat', $status->getValue()->rle_title );
	}

	public function testGetBookmarkEntry_forTitleWithSpaces() {
		$entry = (object)[ 'rle_id' => 1, 'rle_title' => 'United Arab Emirates' ];

		$repository = $this->createMockRepository( [ 'United Arab Emirates' ] );
		$repository->method( 'getListsByPage' )
			->willReturnCallback( static function ( $project, $title ) use ( $entry ) {
				if ( $title === 'United_Arab_Emirates' ) {
					return new FakeResultWrapper( [] );
				}
				if ( $title === 'United Arab Emirates' ) {
					return new FakeResultWrapper( [ $entry ] );
				}
				return new FakeResultWrapper( [] );
			} );

		$service = $this->createService( $repository );
		$service->rebuildBloomFilter( self::CENTRAL_ID );
		$status = $service->getBookmarkEntryStatus(
			$this->createTitle( 'United_Arab_Emirates' ),
			self::CENTRAL_ID
		);

		$this->assertTrue( $status->isOK() );
		$this->assertNotNull( $status->getValue() );
		$this->assertSame( 'United Arab Emirates', $status->getValue()->rle_title );
	}

	public function testGetBookmarkEntry_exceedingMaxItemsFallsBackToDbQuery() {
		$titles = array_map(
			static fn ( int $i ) => "Page_$i",
			range( 1, self::MAX_ITEMS + 1 )
		);
		$entry = (object)[ 'rle_id' => 1, 'rle_title' => 'Page_1' ];

		$repository = $this->createMockRepository( $titles );
		$repository->expects( $this->atLeastOnce() )->method( 'getListsByPage' )
			->willReturn( new FakeResultWrapper( [ $entry ] ) );

		$service = $this->createService( $repository );
		$service->rebuildBloomFilter( self::CENTRAL_ID );
		$status = $service->getBookmarkEntryStatus( $this->createTitle( 'Page_1' ), self::CENTRAL_ID );

		$this->assertTrue( $status->isOK() );
		$this->assertNotNull( $status->getValue() );
	}

	public function testInvalidateBookmarkBloomFilter_triggersFilterRebuildOnNextLookup() {
		$repository = $this->createMockRepository( [ 'Cat' ] );
		$repository->method( 'getListsByPage' )
			->willReturn( new FakeResultWrapper( [] ) );

		$jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$jobQueueGroup->expects( $this->once() )->method( 'lazyPush' )
			->with( $this->callback( static function ( $job ) {
				return $job instanceof BuildBloomFilterJob
					&& $job->getType() === 'buildBookmarkBloomFilter'
					&& $job->ignoreDuplicates()
					&& $job->getParams()['centralId'] === self::CENTRAL_ID;
			} ) );

		$service = $this->createService( $repository, self::MAX_ITEMS, $jobQueueGroup );

		$checkKey = $this->cache->makeKey( 'readinglists', 'bloom-check', self::CENTRAL_ID );
		$timeBefore = $this->cache->getCheckKeyTime( $checkKey );

		$user = $this->createMock( UserIdentity::class );
		$service->invalidateBookmarkBloomFilter( $user );

		$timeAfter = $this->cache->getCheckKeyTime( $checkKey );
		$this->assertGreaterThanOrEqual( $timeBefore, $timeAfter );
	}

	public function testRebuildBloomFilter_usesPrimaryReadForAsyncCacheRebuild() {
		$repository = $this->createMock( ReadingListRepository::class );
		$repository->expects( $this->once() )->method( 'getSavedPagesCacheSetOptions' )
			->with( IDBAccessObject::READ_LATEST )
			->willReturn( [] );
		$repository->expects( $this->once() )->method( 'getSavedPageTitlesForProject' )
			->with( '@local', self::MAX_ITEMS + 1, IDBAccessObject::READ_LATEST )
			->willReturn( [ 'Cat' ] );
		$repository->expects( $this->never() )->method( 'getListsByPage' );

		$service = $this->createService( $repository );
		$service->rebuildBloomFilter( self::CENTRAL_ID );
	}

	public function testGetBookmarkEntry_queuesRebuildAndUsesDbLookupWhenBloomFilterCacheMissing() {
		$entry = (object)[ 'rle_id' => 1, 'rle_title' => 'Cat' ];

		$repository = $this->createMock( ReadingListRepository::class );
		$repository->expects( $this->never() )->method( 'getSavedPagesCacheSetOptions' );
		$repository->expects( $this->never() )->method( 'getSavedPageTitlesForProject' );
		$repository->expects( $this->once() )->method( 'getListsByPage' )
			->with( '@local', 'Cat', 1 )
			->willReturn( new FakeResultWrapper( [ $entry ] ) );

		$jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$jobQueueGroup->expects( $this->once() )->method( 'lazyPush' );

		$service = $this->createService( $repository, self::MAX_ITEMS, $jobQueueGroup );

		$status = $service->getBookmarkEntryStatus( $this->createTitle( 'Cat' ), self::CENTRAL_ID );

		$this->assertTrue( $status->isOK() );
		$this->assertSame(
			$entry,
			$status->getValue()
		);
	}

	public function testGetBookmarkEntry_usesDbLookupWhenBloomFilterWasInvalidated() {
		$entry = (object)[ 'rle_id' => 1, 'rle_title' => 'Cat' ];

		$repository = $this->createMockRepository( [ 'Cat' ] );
		$repository->expects( $this->atLeastOnce() )->method( 'getListsByPage' )
			->willReturn( new FakeResultWrapper( [ $entry ] ) );

		$jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$jobQueueGroup->expects( $this->exactly( 2 ) )->method( 'lazyPush' );

		$service = $this->createService( $repository, self::MAX_ITEMS, $jobQueueGroup );
		$service->rebuildBloomFilter( self::CENTRAL_ID );
		$service->invalidateBookmarkBloomFilter( $this->createMock( UserIdentity::class ) );

		$status = $service->getBookmarkEntryStatus( $this->createTitle( 'Cat' ), self::CENTRAL_ID );

		$this->assertTrue( $status->isOK() );
		$this->assertSame(
			$entry,
			$status->getValue()
		);
	}

	public function testGetBookmarkEntry_onlyQueriesBookmarksOnceForMultipleLookups() {
		$repository = $this->createMockRepository( [ 'Cat' ] );
		$repository->expects( $this->once() )->method( 'getSavedPageTitlesForProject' );
		$repository->expects( $this->never() )->method( 'getListsByPage' );

		$service = $this->createService( $repository );
		$service->rebuildBloomFilter( self::CENTRAL_ID );

		$service->getBookmarkEntryStatus( $this->createTitle( 'Dog' ), self::CENTRAL_ID );
		$service->getBookmarkEntryStatus( $this->createTitle( 'Fish' ), self::CENTRAL_ID );
	}

	public function testGetBookmarkEntry_doesNotRebuildFilterOnSubsequentRequests() {
		$builderRepository = $this->createMockRepository( [ 'Cat' ] );
		$builderRepository->expects( $this->once() )->method( 'getSavedPageTitlesForProject' );
		$builderRepository->expects( $this->never() )->method( 'getListsByPage' );

		$builderService = $this->createService( $builderRepository );
		$builderService->rebuildBloomFilter( self::CENTRAL_ID );

		$freshCache = new WANObjectCache( [ 'cache' => $this->cacheBackend ] );

		/** @var ReadingListRepositoryFactory&MockObject $factory */
		$factory = $this->createMock( ReadingListRepositoryFactory::class );

		$cachedRepository = $this->createMock( ReadingListRepository::class );
		$cachedRepository->expects( $this->never() )->method( 'getSavedPageTitlesForProject' );
		$cachedRepository->expects( $this->never() )->method( 'getListsByPage' );
		$factory->method( 'create' )->willReturn( $cachedRepository );

		$service = new BookmarkEntryLookupService(
			$factory,
			$freshCache,
			$this->createMockCentralIdLookupFactory(),
			$this->createMock( JobQueueGroup::class ),
			new NullLogger(),
			self::MAX_ITEMS
		);

		$status = $service->getBookmarkEntryStatus( $this->createTitle( 'Fish' ), self::CENTRAL_ID );
		$this->assertTrue( $status->isOK() );
		$this->assertNull( $status->getValue() );
	}

	public function testGetBookmarkEntry_stillWorksWhenBloomFilterBuildFailsDueToDbError() {
		$entry = (object)[ 'rle_id' => 1, 'rle_title' => 'Cat' ];

		$failingRepository = $this->createMock( ReadingListRepository::class );
		$failingRepository->expects( $this->once() )->method( 'getSavedPageTitlesForProject' )
			->willThrowException( new DBError( null, 'temporary failure' ) );
		$failingRepository->expects( $this->atLeastOnce() )->method( 'getListsByPage' )
			->willReturn( new FakeResultWrapper( [ $entry ] ) );

		$failingService = $this->createService( $failingRepository );
		$failingService->rebuildBloomFilter( self::CENTRAL_ID );
		$status = $failingService->getBookmarkEntryStatus( $this->createTitle( 'Cat' ), self::CENTRAL_ID );
		$this->assertTrue( $status->isOK() );
		$this->assertSame(
			$entry,
			$status->getValue()
		);

		$freshCache = new WANObjectCache( [ 'cache' => $this->cacheBackend ] );

		/** @var ReadingListRepositoryFactory&MockObject $factory */
		$factory = $this->createMock( ReadingListRepositoryFactory::class );

		$cachedRepository = $this->createMock( ReadingListRepository::class );
		$cachedRepository->expects( $this->never() )->method( 'getSavedPageTitlesForProject' );
		$cachedRepository->expects( $this->atLeastOnce() )->method( 'getListsByPage' )
			->willReturn( new FakeResultWrapper( [ $entry ] ) );
		$factory->method( 'create' )->willReturn( $cachedRepository );

		$service = new BookmarkEntryLookupService(
			$factory,
			$freshCache,
			$this->createMockCentralIdLookupFactory(),
			$this->createMock( JobQueueGroup::class ),
			new NullLogger(),
			self::MAX_ITEMS
		);

		$status = $service->getBookmarkEntryStatus( $this->createTitle( 'Cat' ), self::CENTRAL_ID );
		$this->assertTrue( $status->isOK() );
		$this->assertSame(
			$entry,
			$status->getValue()
		);
	}

	public function testGetBookmarkEntryStatus_returnsErrorForInvalidProjectConfig() {
		$failingRepository = $this->createMock( ReadingListRepository::class );
		$failingRepository->expects( $this->once() )->method( 'getSavedPageTitlesForProject' )
			->willThrowException( $this->createMock( ReadingListRepositoryException::class ) );
		$failingRepository->expects( $this->never() )->method( 'getListsByPage' );

		$service = $this->createService( $failingRepository );
		$service->rebuildBloomFilter( self::CENTRAL_ID );
		$status = $service->getBookmarkEntryStatus( $this->createTitle( 'Cat' ), self::CENTRAL_ID );

		$this->assertFalse( $status->isOK() );
	}

	public function testGetBookmarkEntry_usesDbQueryWhenUserExceedsBloomFilterMaxItems() {
		$titles = array_map(
			static fn ( int $i ) => "Page_$i",
			range( 1, self::MAX_ITEMS + 1 )
		);
		$entry = (object)[ 'rle_id' => 1, 'rle_title' => 'Page_1' ];

		$builderRepository = $this->createMockRepository( $titles );
		$builderRepository->expects( $this->atLeastOnce() )->method( 'getListsByPage' )
			->willReturn( new FakeResultWrapper( [ $entry ] ) );

		$builderService = $this->createService( $builderRepository );
		$builderService->rebuildBloomFilter( self::CENTRAL_ID );
		$status = $builderService->getBookmarkEntryStatus( $this->createTitle( 'Page_1' ), self::CENTRAL_ID );
		$this->assertTrue( $status->isOK() );
		$this->assertNotNull( $status->getValue() );

		$freshCache = new WANObjectCache( [ 'cache' => $this->cacheBackend ] );

		/** @var ReadingListRepositoryFactory&MockObject $factory */
		$factory = $this->createMock( ReadingListRepositoryFactory::class );

		$cachedRepository = $this->createMock( ReadingListRepository::class );
		$cachedRepository->expects( $this->never() )->method( 'getSavedPageTitlesForProject' );
		$cachedRepository->expects( $this->atLeastOnce() )->method( 'getListsByPage' )
			->willReturn( new FakeResultWrapper( [ $entry ] ) );
		$factory->method( 'create' )->willReturn( $cachedRepository );

		$service = new BookmarkEntryLookupService(
			$factory,
			$freshCache,
			$this->createMockCentralIdLookupFactory(),
			$this->createMock( JobQueueGroup::class ),
			new NullLogger(),
			self::MAX_ITEMS
		);

		$status = $service->getBookmarkEntryStatus( $this->createTitle( 'Page_1' ), self::CENTRAL_ID );
		$this->assertTrue( $status->isOK() );
		$this->assertNotNull( $status->getValue() );
	}

	public function testGetBookmarkEntry_forEmptyReadingList() {
		$repository = $this->createMockRepository( [] );
		$repository->expects( $this->never() )->method( 'getListsByPage' );

		$service = $this->createService( $repository );
		$service->rebuildBloomFilter( self::CENTRAL_ID );
		$status = $service->getBookmarkEntryStatus( $this->createTitle( 'Anything' ), self::CENTRAL_ID );

		$this->assertTrue( $status->isOK() );
		$this->assertNull( $status->getValue() );
	}

	public function testConstructor_throwsOnInvalidMaxItems() {
		$this->expectException( \InvalidArgumentException::class );
		new BookmarkEntryLookupService(
			$this->createMock( ReadingListRepositoryFactory::class ),
			$this->cache,
			$this->createMockCentralIdLookupFactory(),
			$this->createMock( JobQueueGroup::class ),
			new NullLogger(),
			0
		);
	}

	public function testTitleSpacesNormalizedToUnderscoresInFilter() {
		$entry = (object)[ 'rle_id' => 1, 'rle_title' => 'Main Page' ];

		$repository = $this->createMockRepository( [ 'Main Page' ] );
		$repository->method( 'getListsByPage' )
			->willReturn( new FakeResultWrapper( [ $entry ] ) );

		$service = $this->createService( $repository );
		$service->rebuildBloomFilter( self::CENTRAL_ID );
		$status = $service->getBookmarkEntryStatus( $this->createTitle( 'Main_Page' ), self::CENTRAL_ID );

		$this->assertTrue( $status->isOK() );
		$this->assertNotNull( $status->getValue() );
	}
}
