<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Unit\Service;

use MediaWiki\Extension\ReadingLists\Job\BuildBloomFilterJob;
use MediaWiki\Extension\ReadingLists\ReadingListRepository;
use MediaWiki\Extension\ReadingLists\ReadingListRepositoryException;
use MediaWiki\Extension\ReadingLists\ReadingListRepositoryFactory;
use MediaWiki\Extension\ReadingLists\Service\BookmarkBloomFilterCache;
use MediaWiki\Extension\ReadingLists\Service\BookmarkEntryLookupResult;
use MediaWiki\Extension\ReadingLists\Service\BookmarkEntryLookupService;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\Title\Title;
use MediaWiki\User\CentralId\CentralIdLookup;
use MediaWiki\User\CentralId\CentralIdLookupFactory;
use MediaWiki\User\UserIdentity;
use MediaWikiUnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use StatusValue;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\DBError;
use Wikimedia\Rdbms\FakeResultWrapper;
use Wikimedia\Stats\StatsFactory;
use Wikimedia\Stats\UnitTestingHelper;

/**
 * @covers \MediaWiki\Extension\ReadingLists\Service\BookmarkEntryLookupService
 */
class BookmarkEntryLookupServiceTest extends MediaWikiUnitTestCase {

	private const CENTRAL_ID = 42;
	private const MAX_ITEMS = 5;

	private WANObjectCache $cache;
	private HashBagOStuff $cacheBackend;
	private float $mockWallClock;

	protected function setUp(): void {
		parent::setUp();
		$this->cacheBackend = new HashBagOStuff();
		$this->cache = new WANObjectCache( [ 'cache' => $this->cacheBackend ] );

		// Freeze the cache clock so values seeded by tests are never treated
		// as stale by check keys lazily created at read time.
		$this->mockWallClock = 1549343521.5;
		$this->cache->setMockTime( $this->mockWallClock );
	}

	private function createService(
		ReadingListRepository $repository,
		?JobQueueGroup $jobQueueGroup = null,
		?StatsFactory $statsFactory = null
	): BookmarkEntryLookupService {
		/** @var ReadingListRepositoryFactory&MockObject $factory */
		$factory = $this->createMock( ReadingListRepositoryFactory::class );
		$factory->method( 'create' )->willReturn( $repository );

		return new BookmarkEntryLookupService(
			$factory,
			$this->createMockCentralIdLookupFactory(),
			$jobQueueGroup ?? $this->createMock( JobQueueGroup::class ),
			$this->createBloomFilterCache( $repository, $this->cache ),
			$statsFactory ?? StatsFactory::newNull(),
			new NullLogger()
		);
	}

	private function createBloomFilterCache(
		ReadingListRepository $repository,
		?WANObjectCache $cache = null,
		int $maxItems = self::MAX_ITEMS
	): BookmarkBloomFilterCache {
		/** @var ReadingListRepositoryFactory&MockObject $factory */
		$factory = $this->createMock( ReadingListRepositoryFactory::class );
		$factory->method( 'create' )->willReturn( $repository );

		return new BookmarkBloomFilterCache(
			$factory,
			$cache ?? $this->cache,
			new NullLogger(),
			StatsFactory::newNull(),
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
		$repository->method( 'getSavedPageTitlesForProject' )
			->willReturn( $savedTitles );
		return $repository;
	}

	private function newStatsHelper(): UnitTestingHelper {
		return StatsFactory::newUnitTestingHelper();
	}

	private function getLookupResult( StatusValue $status ): BookmarkEntryLookupResult {
		$this->assertInstanceOf( BookmarkEntryLookupResult::class, $status->getValue() );
		/** @var BookmarkEntryLookupResult $lookupResult */
		$lookupResult = $status->getValue();
		return $lookupResult;
	}

	public function testGetBookmarkEntryLookup_returnsFalseForNonSavedPage() {
		$repository = $this->createMockRepository( [ 'Cat', 'Dog' ] );
		$repository->expects( $this->never() )->method( 'getListsByPage' );

		$service = $this->createService( $repository );
		$this->createBloomFilterCache( $repository )->rebuildBloomFilter( self::CENTRAL_ID );
		$status = $service->getBookmarkEntryLookupStatus( $this->createTitle( 'Elephant' ), self::CENTRAL_ID );

		$this->assertTrue( $status->isOK() );
		$this->assertFalse( $this->getLookupResult( $status )->isSaved() );
	}

	public function testGetBookmarkEntryLookup_returnsTrueForSavedPage() {
		$matchingList = (object)[ 'rl_id' => 1, 'rl_name' => 'Saved pages', 'rl_is_default' => 1 ];

		$repository = $this->createMockRepository( [ 'Cat', 'Dog' ] );
		$repository->method( 'getListsByPage' )
			->willReturn( new FakeResultWrapper( [ $matchingList ] ) );

		$service = $this->createService( $repository );
		$status = $service->getBookmarkEntryLookupStatus( $this->createTitle( 'Cat' ), self::CENTRAL_ID );

		$this->assertTrue( $status->isOK() );
		$this->assertTrue( $this->getLookupResult( $status )->isSaved() );
	}

	/**
	 * @dataProvider provideBookmarkMembership
	 */
	public function testGetBookmarkEntryLookupStatus(
		array $listRows,
		bool $expectedSaved,
		bool $expectedCustomListEntry
	): void {
		$repository = $this->createMockRepository( [ 'Cat' ] );
		$repository->expects( $this->once() )->method( 'getListsByPage' )
			->with( '@local', 'Cat', 2, null, false )
			->willReturn( new FakeResultWrapper( $listRows ) );

		$status = $this->createService( $repository )->getBookmarkEntryLookupStatus(
			$this->createTitle( 'Cat' ),
			self::CENTRAL_ID
		);

		$this->assertTrue( $status->isOK() );
		$lookupResult = $this->getLookupResult( $status );
		$this->assertSame( $expectedSaved, $lookupResult->isSaved() );
		$this->assertSame( $expectedCustomListEntry, $lookupResult->hasCustomListEntry() );
	}

	public static function provideBookmarkMembership(): iterable {
		yield 'not saved' => [ [], false, false ];
		yield 'default list only' => [
			[ (object)[ 'rl_id' => 1, 'rl_is_default' => 1 ] ],
			true,
			false,
		];
		yield 'custom list only' => [
			[ (object)[ 'rl_id' => 2, 'rl_is_default' => 0 ] ],
			true,
			true,
		];
		yield 'default and custom lists' => [
			[
				(object)[ 'rl_id' => 1, 'rl_is_default' => 1 ],
				(object)[ 'rl_id' => 2, 'rl_is_default' => 0 ],
			],
			true,
			true,
		];
		yield 'multiple custom lists' => [
			[
				(object)[ 'rl_id' => 2, 'rl_is_default' => 0 ],
				(object)[ 'rl_id' => 3, 'rl_is_default' => 0 ],
			],
			true,
			true,
		];
	}

	public function testGetBookmarkEntryLookup_emitsCacheMissMetric() {
		$matchingList = (object)[ 'rl_id' => 1, 'rl_name' => 'Saved pages', 'rl_is_default' => 1 ];
		$statsHelper = $this->newStatsHelper();

		$repository = $this->createMock( ReadingListRepository::class );
		$repository->expects( $this->never() )->method( 'getSavedPageTitlesForProject' );
		$repository->expects( $this->once() )->method( 'getListsByPage' )
			->with( '@local', 'Cat', 2, null, false )
			->willReturn( new FakeResultWrapper( [ $matchingList ] ) );

		$jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$jobQueueGroup->expects( $this->once() )->method( 'lazyPush' );

		$service = $this->createService(
			$repository,
			$jobQueueGroup,
			$statsHelper->getStatsFactory()
		);

		$status = $service->getBookmarkEntryLookupStatus( $this->createTitle( 'Cat' ), self::CENTRAL_ID );

		$this->assertTrue( $status->isOK() );
		$this->assertSame( 1, $statsHelper->count(
			'bloom_lookup_total{result="cache_miss"}'
		) );
		$this->assertSame( 1, $statsHelper->count(
			'bloom_db_lookup_total{reason="cache_miss"}'
		) );
		$this->assertSame( 1, $statsHelper->count(
			'bloom_db_lookup_total{reason="default_list_check"}'
		) );
	}

	public function testGetBookmarkEntryLookup_storesAndReusesEmptyStateForUserWithoutDefaultList(): void {
		$statsHelper = $this->newStatsHelper();
		$repository = $this->createMock( ReadingListRepository::class );
		$repository->expects( $this->once() )
			->method( 'getDefaultListIdForUser' )
			->willReturn( false );
		$repository->expects( $this->never() )->method( 'getListsByPage' );
		$repository->expects( $this->never() )->method( 'getSavedPageTitlesForProject' );

		$jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$jobQueueGroup->expects( $this->never() )->method( 'lazyPush' );
		$service = $this->createService(
			$repository,
			$jobQueueGroup,
			$statsHelper->getStatsFactory()
		);

		$firstStatus = $service->getBookmarkEntryLookupStatus( $this->createTitle( 'Cat' ), self::CENTRAL_ID );
		$secondStatus = $service->getBookmarkEntryLookupStatus( $this->createTitle( 'Dog' ), self::CENTRAL_ID );

		$this->assertTrue( $firstStatus->isOK() );
		$this->assertFalse( $this->getLookupResult( $firstStatus )->isSaved() );
		$this->assertTrue( $secondStatus->isOK() );
		$this->assertFalse( $this->getLookupResult( $secondStatus )->isSaved() );
		$this->assertSame( 1, $statsHelper->count(
			'bloom_lookup_total{result="empty_cache_fill"}'
		) );
		$this->assertSame( 1, $statsHelper->count(
			'bloom_lookup_total{result="empty_cache_hit"}'
		) );
		$this->assertSame( 1, $statsHelper->count(
			'bloom_db_lookup_total{reason="default_list_check"}'
		) );
		$this->assertStringNotContainsString(
			'reason:cache_miss',
			implode( "\n", $statsHelper->getAllFormatted() )
		);
	}

	public function testGetBookmarkEntryLookup_doesNotServeEmptyStateAfterInvalidation(): void {
		$matchingList = (object)[ 'rl_id' => 1, 'rl_name' => 'Saved pages', 'rl_is_default' => 1 ];
		$repository = $this->createMockRepository( [ 'Cat' ] );
		$repository->expects( $this->once() )
			->method( 'getDefaultListIdForUser' )
			->willReturn( 1 );
		$repository->expects( $this->once() )
			->method( 'getListsByPage' )
			->willReturn( new FakeResultWrapper( [ $matchingList ] ) );

		$jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$jobQueueGroup->expects( $this->once() )->method( 'lazyPush' );
		$service = $this->createService( $repository, $jobQueueGroup );
		$cache = $this->createBloomFilterCache( $repository );
		$cache->storeEmptyBloomFilter( self::CENTRAL_ID );
		$cache->invalidateBloomFilter( self::CENTRAL_ID );

		$status = $service->getBookmarkEntryLookupStatus( $this->createTitle( 'Cat' ), self::CENTRAL_ID );

		$this->assertTrue( $status->isOK() );
		$this->assertTrue( $this->getLookupResult( $status )->isSaved() );
	}

	public function testGetBookmarkEntryLookup_fallsBackWhenDefaultListCheckFails(): void {
		$matchingList = (object)[ 'rl_id' => 1, 'rl_name' => 'Saved pages', 'rl_is_default' => 1 ];
		$repository = $this->createMock( ReadingListRepository::class );
		$repository->expects( $this->once() )
			->method( 'getDefaultListIdForUser' )
			->willThrowException( new DBError( null, 'temporary failure' ) );
		$repository->expects( $this->once() )
			->method( 'getListsByPage' )
			->willReturn( new FakeResultWrapper( [ $matchingList ] ) );

		$jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$jobQueueGroup->expects( $this->once() )->method( 'lazyPush' );
		$service = $this->createService( $repository, $jobQueueGroup );

		$status = $service->getBookmarkEntryLookupStatus( $this->createTitle( 'Cat' ), self::CENTRAL_ID );

		$this->assertTrue( $status->isOK() );
		$this->assertTrue( $this->getLookupResult( $status )->isSaved() );
	}

	public function testGetBookmarkEntryLookup_forTitleWithSpaces() {
		$matchingList = (object)[ 'rl_id' => 1, 'rl_name' => 'Saved pages', 'rl_is_default' => 1 ];

		$repository = $this->createMockRepository( [ 'United Arab Emirates' ] );
		$repository->method( 'getListsByPage' )
			->with( '@local', 'United_Arab_Emirates', 2, null, false )
			->willReturn( new FakeResultWrapper( [ $matchingList ] ) );

		$service = $this->createService( $repository );
		$this->createBloomFilterCache( $repository )->rebuildBloomFilter( self::CENTRAL_ID );
		$status = $service->getBookmarkEntryLookupStatus(
			$this->createTitle( 'United_Arab_Emirates' ),
			self::CENTRAL_ID
		);

		$this->assertTrue( $status->isOK() );
		$this->assertTrue( $this->getLookupResult( $status )->isSaved() );
	}

	public function testGetBookmarkEntryLookup_exceedingMaxItemsFallsBackToDbQuery() {
		$titles = array_map(
			static fn ( int $i ) => "Page_$i",
			range( 1, self::MAX_ITEMS + 1 )
		);
		$matchingList = (object)[ 'rl_id' => 1, 'rl_name' => 'Saved pages', 'rl_is_default' => 1 ];

		$repository = $this->createMockRepository( $titles );
		$repository->expects( $this->atLeastOnce() )->method( 'getListsByPage' )
			->willReturn( new FakeResultWrapper( [ $matchingList ] ) );

		$service = $this->createService( $repository );
		$this->createBloomFilterCache( $repository )->rebuildBloomFilter( self::CENTRAL_ID );
		$status = $service->getBookmarkEntryLookupStatus( $this->createTitle( 'Page_1' ), self::CENTRAL_ID );

		$this->assertTrue( $status->isOK() );
		$this->assertTrue( $this->getLookupResult( $status )->isSaved() );
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

		$service = $this->createService( $repository, $jobQueueGroup );

		$checkKey = $this->cache->makeKey( 'readinglists', 'bloom-check', self::CENTRAL_ID );
		$timeBefore = $this->cache->getCheckKeyTime( $checkKey );

		$user = $this->createMock( UserIdentity::class );
		$service->invalidateBookmarkBloomFilter( $user );

		$timeAfter = $this->cache->getCheckKeyTime( $checkKey );
		$this->assertGreaterThanOrEqual( $timeBefore, $timeAfter );
	}

	public function testGetBookmarkEntryLookup_queuesRebuildAndUsesDbLookupWhenBloomFilterCacheMissing() {
		$matchingList = (object)[ 'rl_id' => 1, 'rl_name' => 'Saved pages', 'rl_is_default' => 1 ];

		$repository = $this->createMock( ReadingListRepository::class );
		$repository->expects( $this->never() )->method( 'getSavedPageTitlesForProject' );
		$repository->expects( $this->once() )->method( 'getListsByPage' )
			->with( '@local', 'Cat', 2, null, false )
			->willReturn( new FakeResultWrapper( [ $matchingList ] ) );

		$jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$jobQueueGroup->expects( $this->once() )->method( 'lazyPush' );

		$service = $this->createService( $repository, $jobQueueGroup );

		$status = $service->getBookmarkEntryLookupStatus( $this->createTitle( 'Cat' ), self::CENTRAL_ID );

		$this->assertTrue( $status->isOK() );
		$this->assertTrue( $this->getLookupResult( $status )->isSaved() );
	}

	public function testGetBookmarkEntryLookup_usesDbLookupWhenBloomFilterWasInvalidated() {
		$matchingList = (object)[ 'rl_id' => 1, 'rl_name' => 'Saved pages', 'rl_is_default' => 1 ];

		$repository = $this->createMockRepository( [ 'Cat' ] );
		$repository->expects( $this->atLeastOnce() )->method( 'getListsByPage' )
			->willReturn( new FakeResultWrapper( [ $matchingList ] ) );

		$jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$jobQueueGroup->expects( $this->exactly( 2 ) )->method( 'lazyPush' );

		$service = $this->createService( $repository, $jobQueueGroup );
		$this->createBloomFilterCache( $repository )->rebuildBloomFilter( self::CENTRAL_ID );
		$service->invalidateBookmarkBloomFilter( $this->createMock( UserIdentity::class ) );

		$status = $service->getBookmarkEntryLookupStatus( $this->createTitle( 'Cat' ), self::CENTRAL_ID );

		$this->assertTrue( $status->isOK() );
		$this->assertTrue( $this->getLookupResult( $status )->isSaved() );
	}

	public function testGetBookmarkEntryLookup_onlyQueriesBookmarksOnceForMultipleLookups() {
		$repository = $this->createMockRepository( [ 'Cat' ] );

		// this should be called only once to build the bloom filter
		$repository->expects( $this->once() )->method( 'getSavedPageTitlesForProject' );

		// if there is a false positive or saved page, then this can be called.
		$repository->method( 'getListsByPage' )
			->willReturn( new FakeResultWrapper( [] ) );

		$service = $this->createService( $repository );
		$this->createBloomFilterCache( $repository )->rebuildBloomFilter( self::CENTRAL_ID );

		$service->getBookmarkEntryLookupStatus( $this->createTitle( 'Dog' ), self::CENTRAL_ID );
		$service->getBookmarkEntryLookupStatus( $this->createTitle( 'Fish' ), self::CENTRAL_ID );
	}

	public function testGetBookmarkEntryLookup_doesNotRebuildFilterOnSubsequentRequests() {
		$builderRepository = $this->createMockRepository( [ 'Cat' ] );
		$builderRepository->expects( $this->once() )->method( 'getSavedPageTitlesForProject' );
		$builderRepository->expects( $this->never() )->method( 'getListsByPage' );

		$this->createBloomFilterCache( $builderRepository )->rebuildBloomFilter( self::CENTRAL_ID );

		$freshCache = new WANObjectCache( [ 'cache' => $this->cacheBackend ] );
		$freshCache->setMockTime( $this->mockWallClock );

		/** @var ReadingListRepositoryFactory&MockObject $factory */
		$factory = $this->createMock( ReadingListRepositoryFactory::class );

		$cachedRepository = $this->createMock( ReadingListRepository::class );
		$cachedRepository->expects( $this->never() )->method( 'getSavedPageTitlesForProject' );
		$cachedRepository->expects( $this->never() )->method( 'getListsByPage' );
		$factory->method( 'create' )->willReturn( $cachedRepository );

		$service = new BookmarkEntryLookupService(
			$factory,
			$this->createMockCentralIdLookupFactory(),
			$this->createMock( JobQueueGroup::class ),
			$this->createBloomFilterCache( $cachedRepository, $freshCache ),
			StatsFactory::newNull(),
			new NullLogger()
		);

		$status = $service->getBookmarkEntryLookupStatus( $this->createTitle( 'Fish' ), self::CENTRAL_ID );
		$this->assertTrue( $status->isOK() );
		$this->assertFalse( $this->getLookupResult( $status )->isSaved() );
	}

	public function testGetBookmarkEntryLookup_stillWorksWhenBloomFilterBuildFailsDueToDbError() {
		$matchingList = (object)[ 'rl_id' => 1, 'rl_name' => 'Saved pages', 'rl_is_default' => 1 ];

		$failingRepository = $this->createMock( ReadingListRepository::class );
		$failingRepository->expects( $this->once() )->method( 'getSavedPageTitlesForProject' )
			->willThrowException( new DBError( null, 'temporary failure' ) );
		$failingRepository->expects( $this->atLeastOnce() )->method( 'getListsByPage' )
			->willReturn( new FakeResultWrapper( [ $matchingList ] ) );

		$cache = $this->createBloomFilterCache( $failingRepository );
		$cache->rebuildBloomFilter( self::CENTRAL_ID );
		$service = $this->createService( $failingRepository );
		$status = $service->getBookmarkEntryLookupStatus( $this->createTitle( 'Cat' ), self::CENTRAL_ID );
		$this->assertTrue( $status->isOK() );
		$this->assertTrue( $this->getLookupResult( $status )->isSaved() );

		$freshCache = new WANObjectCache( [ 'cache' => $this->cacheBackend ] );
		$freshCache->setMockTime( $this->mockWallClock );

		/** @var ReadingListRepositoryFactory&MockObject $factory */
		$factory = $this->createMock( ReadingListRepositoryFactory::class );

		$cachedRepository = $this->createMock( ReadingListRepository::class );
		$cachedRepository->expects( $this->never() )->method( 'getSavedPageTitlesForProject' );
		$cachedRepository->expects( $this->atLeastOnce() )->method( 'getListsByPage' )
			->willReturn( new FakeResultWrapper( [ $matchingList ] ) );
		$factory->method( 'create' )->willReturn( $cachedRepository );

		$service = new BookmarkEntryLookupService(
			$factory,
			$this->createMockCentralIdLookupFactory(),
			$this->createMock( JobQueueGroup::class ),
			$this->createBloomFilterCache( $cachedRepository, $freshCache ),
			StatsFactory::newNull(),
			new NullLogger()
		);

		$status = $service->getBookmarkEntryLookupStatus( $this->createTitle( 'Cat' ), self::CENTRAL_ID );
		$this->assertTrue( $status->isOK() );
		$this->assertTrue( $this->getLookupResult( $status )->isSaved() );
	}

	public function testGetBookmarkEntryLookupStatus_returnsErrorForInvalidProjectConfig() {
		$failingRepository = $this->createMock( ReadingListRepository::class );
		$failingRepository->expects( $this->once() )->method( 'getSavedPageTitlesForProject' )
			->willThrowException( $this->createMock( ReadingListRepositoryException::class ) );
		$failingRepository->expects( $this->never() )->method( 'getListsByPage' );

		$this->createBloomFilterCache( $failingRepository )->rebuildBloomFilter( self::CENTRAL_ID );
		$service = $this->createService( $failingRepository );
		$status = $service->getBookmarkEntryLookupStatus( $this->createTitle( 'Cat' ), self::CENTRAL_ID );

		$this->assertFalse( $status->isOK() );
	}

	public function testGetBookmarkEntryLookup_usesDbQueryWhenUserExceedsBloomFilterMaxItems() {
		$titles = array_map(
			static fn ( int $i ) => "Page_$i",
			range( 1, self::MAX_ITEMS + 1 )
		);
		$matchingList = (object)[ 'rl_id' => 1, 'rl_name' => 'Saved pages', 'rl_is_default' => 1 ];

		$builderRepository = $this->createMockRepository( $titles );
		$builderRepository->expects( $this->atLeastOnce() )->method( 'getListsByPage' )
			->willReturn( new FakeResultWrapper( [ $matchingList ] ) );

		$this->createBloomFilterCache( $builderRepository )->rebuildBloomFilter( self::CENTRAL_ID );
		$status = $this->createService( $builderRepository )
			->getBookmarkEntryLookupStatus( $this->createTitle( 'Page_1' ), self::CENTRAL_ID );
		$this->assertTrue( $status->isOK() );
		$this->assertTrue( $this->getLookupResult( $status )->isSaved() );

		$freshCache = new WANObjectCache( [ 'cache' => $this->cacheBackend ] );
		$freshCache->setMockTime( $this->mockWallClock );

		/** @var ReadingListRepositoryFactory&MockObject $factory */
		$factory = $this->createMock( ReadingListRepositoryFactory::class );

		$cachedRepository = $this->createMock( ReadingListRepository::class );
		$cachedRepository->expects( $this->never() )->method( 'getSavedPageTitlesForProject' );
		$cachedRepository->expects( $this->atLeastOnce() )->method( 'getListsByPage' )
			->willReturn( new FakeResultWrapper( [ $matchingList ] ) );
		$factory->method( 'create' )->willReturn( $cachedRepository );

		$service = new BookmarkEntryLookupService(
			$factory,
			$this->createMockCentralIdLookupFactory(),
			$this->createMock( JobQueueGroup::class ),
			$this->createBloomFilterCache( $cachedRepository, $freshCache ),
			StatsFactory::newNull(),
			new NullLogger()
		);

		$status = $service->getBookmarkEntryLookupStatus( $this->createTitle( 'Page_1' ), self::CENTRAL_ID );
		$this->assertTrue( $status->isOK() );
		$this->assertTrue( $this->getLookupResult( $status )->isSaved() );
	}

	public function testGetBookmarkEntryLookup_forEmptyReadingList() {
		$repository = $this->createMockRepository( [] );
		$repository->expects( $this->never() )->method( 'getListsByPage' );

		$service = $this->createService( $repository );
		$this->createBloomFilterCache( $repository )->rebuildBloomFilter( self::CENTRAL_ID );
		$status = $service->getBookmarkEntryLookupStatus( $this->createTitle( 'Anything' ), self::CENTRAL_ID );

		$this->assertTrue( $status->isOK() );
		$this->assertFalse( $this->getLookupResult( $status )->isSaved() );
	}

	public function testGetBookmarkEntryLookup_emitsDefiniteNegativeMetric() {
		$statsHelper = $this->newStatsHelper();
		$repository = $this->createMockRepository( [ 'Cat', 'Dog' ] );
		$repository->expects( $this->never() )->method( 'getListsByPage' );

		$service = $this->createService(
			$repository,
			null,
			$statsHelper->getStatsFactory()
		);
		$this->createBloomFilterCache( $repository )->rebuildBloomFilter( self::CENTRAL_ID );

		$status = $service->getBookmarkEntryLookupStatus( $this->createTitle( 'Elephant' ), self::CENTRAL_ID );

		$this->assertTrue( $status->isOK() );
		$this->assertFalse( $this->getLookupResult( $status )->isSaved() );
		$this->assertSame( 1, $statsHelper->count(
			'bloom_lookup_total{result="definite_negative"}'
		) );
	}

	public function testGetBookmarkEntryLookup_emitsTruePositiveAndDbLookupMetrics() {
		$matchingList = (object)[ 'rl_id' => 1, 'rl_is_default' => 1 ];
		$statsHelper = $this->newStatsHelper();
		$repository = $this->createMockRepository( [ 'Cat' ] );
		$repository->expects( $this->once() )
			->method( 'getListsByPage' )
			->willReturn( new FakeResultWrapper( [ $matchingList ] ) );

		$service = $this->createService(
			$repository,
			null,
			$statsHelper->getStatsFactory()
		);
		$this->createBloomFilterCache( $repository )->rebuildBloomFilter( self::CENTRAL_ID );

		$status = $service->getBookmarkEntryLookupStatus( $this->createTitle( 'Cat' ), self::CENTRAL_ID );

		$this->assertTrue( $status->isOK() );
		$this->assertSame( 1, $statsHelper->count(
			'bloom_lookup_total{result="true_positive"}'
		) );
		$this->assertSame( 1, $statsHelper->count(
			'bloom_db_lookup_total{reason="probable_positive"}'
		) );
	}

	public function testGetBookmarkEntryLookup_emitsFalsePositiveAndDbLookupMetrics() {
		$statsHelper = $this->newStatsHelper();
		$repository = $this->createMockRepository( [ 'Cat' ] );
		$repository->expects( $this->once() )
			->method( 'getListsByPage' )
			->willReturn( new FakeResultWrapper( [] ) );

		$service = $this->createService(
			$repository,
			null,
			$statsHelper->getStatsFactory()
		);
		$this->createBloomFilterCache( $repository )->rebuildBloomFilter( self::CENTRAL_ID );

		$status = $service->getBookmarkEntryLookupStatus( $this->createTitle( 'Cat' ), self::CENTRAL_ID );

		$this->assertTrue( $status->isOK() );
		$this->assertFalse( $this->getLookupResult( $status )->isSaved() );
		$this->assertSame( 1, $statsHelper->count(
			'bloom_lookup_total{result="false_positive"}'
		) );
		$this->assertSame( 1, $statsHelper->count(
			'bloom_db_lookup_total{reason="probable_positive"}'
		) );
	}

	public function testGetBookmarkEntryLookup_emitsTooLargeBypassMetric() {
		$titles = array_map(
			static fn ( int $i ) => "Page_$i",
			range( 1, self::MAX_ITEMS + 1 )
		);
		$matchingList = (object)[ 'rl_id' => 1, 'rl_is_default' => 1 ];
		$statsHelper = $this->newStatsHelper();

		$repository = $this->createMockRepository( $titles );
		$repository->expects( $this->atLeastOnce() )
			->method( 'getListsByPage' )
			->willReturn( new FakeResultWrapper( [ $matchingList ] ) );

		$service = $this->createService(
			$repository,
			null,
			$statsHelper->getStatsFactory()
		);
		$this->createBloomFilterCache( $repository )->rebuildBloomFilter( self::CENTRAL_ID );

		$status = $service->getBookmarkEntryLookupStatus( $this->createTitle( 'Page_1' ), self::CENTRAL_ID );

		$this->assertTrue( $status->isOK() );
		$this->assertTrue( $this->getLookupResult( $status )->isSaved() );
		$this->assertSame( 1, $statsHelper->count(
			'bloom_lookup_total{result="too_large_bypass_found"}'
		) );
		$this->assertSame( 1, $statsHelper->count(
			'bloom_db_lookup_total{reason="too_large_bypass"}'
		) );
	}

	public function testGetBookmarkEntryLookup_emitsCacheStatusErrorMetric() {
		$statsHelper = $this->newStatsHelper();
		$failingRepository = $this->createMock( ReadingListRepository::class );
		$failingRepository->expects( $this->once() )->method( 'getSavedPageTitlesForProject' )
			->willThrowException( $this->createMock( ReadingListRepositoryException::class ) );
		$failingRepository->expects( $this->never() )->method( 'getListsByPage' );

		$this->createBloomFilterCache( $failingRepository )->rebuildBloomFilter( self::CENTRAL_ID );
		$service = $this->createService(
			$failingRepository,
			null,
			$statsHelper->getStatsFactory()
		);

		$status = $service->getBookmarkEntryLookupStatus( $this->createTitle( 'Cat' ), self::CENTRAL_ID );

		$this->assertFalse( $status->isOK() );
		$this->assertSame( 1, $statsHelper->count(
			'bloom_lookup_total{result="error"}'
		) );
		$this->assertSame( 1, $statsHelper->count(
			'bloom_error_total{stage="cache_status"}'
		) );
	}

	public function testGetBookmarkEntryLookup_emitsBuildDbErrorBypassMetrics() {
		$matchingList = (object)[ 'rl_id' => 1, 'rl_is_default' => 1 ];
		$statsHelper = $this->newStatsHelper();
		$failingRepository = $this->createMock( ReadingListRepository::class );
		$failingRepository->expects( $this->once() )->method( 'getSavedPageTitlesForProject' )
			->willThrowException( new DBError( null, 'temporary failure' ) );
		$failingRepository->expects( $this->atLeastOnce() )
			->method( 'getListsByPage' )
			->willReturn( new FakeResultWrapper( [ $matchingList ] ) );

		$this->createBloomFilterCache( $failingRepository )->rebuildBloomFilter( self::CENTRAL_ID );
		$service = $this->createService(
			$failingRepository,
			null,
			$statsHelper->getStatsFactory()
		);

		$status = $service->getBookmarkEntryLookupStatus( $this->createTitle( 'Cat' ), self::CENTRAL_ID );

		$this->assertTrue( $status->isOK() );
		$this->assertTrue( $this->getLookupResult( $status )->isSaved() );
		$this->assertSame( 1, $statsHelper->count(
			'bloom_lookup_total{result="build_db_error_bypass"}'
		) );
		$this->assertSame( 1, $statsHelper->count(
			'bloom_db_lookup_total{reason="build_db_error_bypass"}'
		) );
	}

	public function testGetBookmarkEntryLookup_emitsCacheUnusableMetricsForCachedPayloadWithoutState() {
		$matchingList = (object)[ 'rl_id' => 1, 'rl_is_default' => 1 ];
		$statsHelper = $this->newStatsHelper();
		$repository = $this->createMockRepository();
		$repository->expects( $this->atLeastOnce() )
			->method( 'getListsByPage' )
			->willReturn( new FakeResultWrapper( [ $matchingList ] ) );

		$this->cache->set(
			$this->cache->makeKey( 'readinglists', 'bloom', self::CENTRAL_ID ),
			[
				'filter' => [],
			],
			3600,
			[ 'version' => BookmarkBloomFilterCache::CACHE_VERSION ]
		);

		$service = $this->createService(
			$repository,
			null,
			$statsHelper->getStatsFactory()
		);

		$status = $service->getBookmarkEntryLookupStatus( $this->createTitle( 'Cat' ), self::CENTRAL_ID );

		$this->assertTrue( $status->isOK() );
		$this->assertTrue( $this->getLookupResult( $status )->isSaved() );
		$this->assertSame( 1, $statsHelper->count(
			'bloom_lookup_total{result="cache_unusable"}'
		) );
		$this->assertSame( 1, $statsHelper->count(
			'bloom_db_lookup_total{reason="cache_unusable"}'
		) );
	}

	public function testGetBookmarkEntryLookup_emitsBuildDbErrorBypassDbLookupErrorMetric() {
		$statsHelper = $this->newStatsHelper();
		$failingRepository = $this->createMock( ReadingListRepository::class );
		$failingRepository->expects( $this->once() )->method( 'getSavedPageTitlesForProject' )
			->willThrowException( new DBError( null, 'temporary failure' ) );
		$failingRepository->expects( $this->once() )
			->method( 'getListsByPage' )
			->with( '@local', 'Cat', 2, null, false )
			->willThrowException( new DBError( null, 'temporary failure' ) );

		$this->createBloomFilterCache( $failingRepository )->rebuildBloomFilter( self::CENTRAL_ID );
		$service = $this->createService(
			$failingRepository,
			null,
			$statsHelper->getStatsFactory()
		);

		$status = $service->getBookmarkEntryLookupStatus( $this->createTitle( 'Cat' ), self::CENTRAL_ID );

		$this->assertFalse( $status->isOK() );
		$this->assertSame( 1, $statsHelper->count(
			'bloom_lookup_total{result="error"}'
		) );
		$this->assertSame( 1, $statsHelper->count(
			'bloom_error_total{stage="build_db_error_bypass_db_lookup"}'
		) );
		$this->assertSame( 1, $statsHelper->count(
			'bloom_db_lookup_total{reason="build_db_error_bypass"}'
		) );
	}

	public function testGetBookmarkEntryLookup_emitsDbLookupErrorMetric() {
		$statsHelper = $this->newStatsHelper();
		$failingRepository = $this->createMock( ReadingListRepository::class );
		$failingRepository->expects( $this->never() )->method( 'getSavedPageTitlesForProject' );
		$failingRepository->expects( $this->once() )
			->method( 'getListsByPage' )
			->with( '@local', 'Cat', 2, null, false )
			->willThrowException( new DBError( null, 'temporary failure' ) );

		$jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$jobQueueGroup->expects( $this->once() )->method( 'lazyPush' );

		$service = $this->createService(
			$failingRepository,
			$jobQueueGroup,
			$statsHelper->getStatsFactory()
		);

		$status = $service->getBookmarkEntryLookupStatus( $this->createTitle( 'Cat' ), self::CENTRAL_ID );

		$this->assertFalse( $status->isOK() );
		$this->assertSame( 1, $statsHelper->count(
			'bloom_lookup_total{result="error"}'
		) );
		$this->assertSame( 1, $statsHelper->count(
			'bloom_error_total{stage="cache_miss_db_lookup"}'
		) );
		$this->assertSame( 1, $statsHelper->count(
			'bloom_db_lookup_total{reason="cache_miss"}'
		) );
	}

	public function testGetBookmarkEntryLookup_emitsTooLargeDbLookupErrorMetric() {
		$titles = array_map(
			static fn ( int $i ) => "Page_$i",
			range( 1, self::MAX_ITEMS + 1 )
		);
		$statsHelper = $this->newStatsHelper();
		$failingRepository = $this->createMockRepository( $titles );
		$failingRepository->expects( $this->once() )
			->method( 'getListsByPage' )
			->with( '@local', 'Page_1', 2, null, false )
			->willThrowException( new DBError( null, 'temporary failure' ) );

		$service = $this->createService(
			$failingRepository,
			null,
			$statsHelper->getStatsFactory()
		);
		$this->createBloomFilterCache( $failingRepository )->rebuildBloomFilter( self::CENTRAL_ID );

		$status = $service->getBookmarkEntryLookupStatus( $this->createTitle( 'Page_1' ), self::CENTRAL_ID );

		$this->assertFalse( $status->isOK() );
		$this->assertSame( 1, $statsHelper->count(
			'bloom_lookup_total{result="error"}'
		) );
		$this->assertSame( 1, $statsHelper->count(
			'bloom_error_total{stage="too_large_bypass_db_lookup"}'
		) );
		$this->assertSame( 1, $statsHelper->count(
			'bloom_db_lookup_total{reason="too_large_bypass"}'
		) );
	}

	public function testGetBookmarkEntryLookup_emitsTooLargeResultMetricWhenEntryMissing() {
		$titles = array_map(
			static fn ( int $i ) => "Page_$i",
			range( 1, self::MAX_ITEMS + 1 )
		);
		$statsHelper = $this->newStatsHelper();
		$repository = $this->createMockRepository( $titles );
		$repository->expects( $this->once() )->method( 'getListsByPage' )
			->willReturn( new FakeResultWrapper( [] ) );

		$service = $this->createService(
			$repository,
			null,
			$statsHelper->getStatsFactory()
		);
		$this->createBloomFilterCache( $repository )->rebuildBloomFilter( self::CENTRAL_ID );

		$status = $service->getBookmarkEntryLookupStatus( $this->createTitle( 'Page_1' ), self::CENTRAL_ID );

		$this->assertTrue( $status->isOK() );
		$this->assertFalse( $this->getLookupResult( $status )->isSaved() );
		$this->assertSame( 1, $statsHelper->count(
			'bloom_lookup_total{result="too_large_bypass_not_found"}'
		) );
		$this->assertSame( 1, $statsHelper->count(
			'bloom_db_lookup_total{reason="too_large_bypass"}'
		) );
	}

	public function testGetBookmarkEntryLookup_emitsProbablePositiveDbLookupErrorMetric() {
		$statsHelper = $this->newStatsHelper();
		$builderRepository = $this->createMockRepository( [ 'Cat' ] );
		$this->createBloomFilterCache( $builderRepository )->rebuildBloomFilter( self::CENTRAL_ID );

		$failingRepository = $this->createMockRepository();
		$failingRepository->expects( $this->never() )->method( 'getSavedPageTitlesForProject' );
		$failingRepository->expects( $this->once() )
			->method( 'getListsByPage' )
			->with( '@local', 'Cat', 2, null, false )
			->willThrowException( new DBError( null, 'temporary failure' ) );

		$service = $this->createService(
			$failingRepository,
			null,
			$statsHelper->getStatsFactory()
		);

		$status = $service->getBookmarkEntryLookupStatus( $this->createTitle( 'Cat' ), self::CENTRAL_ID );

		$this->assertFalse( $status->isOK() );
		$this->assertSame( 1, $statsHelper->count(
			'bloom_lookup_total{result="error"}'
		) );
		$this->assertSame( 1, $statsHelper->count(
			'bloom_error_total{stage="probable_positive_db_lookup"}'
		) );
		$this->assertSame( 1, $statsHelper->count(
			'bloom_db_lookup_total{reason="probable_positive"}'
		) );
	}

	public function testTitleSpacesNormalizedToUnderscoresInFilter() {
		$matchingList = (object)[ 'rl_id' => 1, 'rl_name' => 'Saved pages', 'rl_is_default' => 1 ];

		$repository = $this->createMockRepository( [ 'Main Page' ] );
		$repository->method( 'getListsByPage' )
			->willReturn( new FakeResultWrapper( [ $matchingList ] ) );

		$service = $this->createService( $repository );
		$this->createBloomFilterCache( $repository )->rebuildBloomFilter( self::CENTRAL_ID );
		$status = $service->getBookmarkEntryLookupStatus( $this->createTitle( 'Main_Page' ), self::CENTRAL_ID );

		$this->assertTrue( $status->isOK() );
		$this->assertTrue( $this->getLookupResult( $status )->isSaved() );
	}
}
