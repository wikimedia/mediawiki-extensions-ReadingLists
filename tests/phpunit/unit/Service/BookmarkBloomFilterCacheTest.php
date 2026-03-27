<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Unit\Service;

use MediaWiki\Extension\ReadingLists\ReadingListRepository;
use MediaWiki\Extension\ReadingLists\ReadingListRepositoryException;
use MediaWiki\Extension\ReadingLists\ReadingListRepositoryFactory;
use MediaWiki\Extension\ReadingLists\Service\BookmarkBloomFilterCache;
use MediaWikiUnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\IDBAccessObject;

/**
 * @covers \MediaWiki\Extension\ReadingLists\Service\BookmarkBloomFilterCache
 */
class BookmarkBloomFilterCacheTest extends MediaWikiUnitTestCase {

	private const CENTRAL_ID = 42;
	private const MAX_ITEMS = 5;

	private WANObjectCache $cache;

	protected function setUp(): void {
		parent::setUp();
		$this->cache = new WANObjectCache( [ 'cache' => new HashBagOStuff() ] );
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
			$maxItems
		);
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

	public function testGetBloomFilterStatus_returnsFalseWhenCacheMissing() {
		$cache = $this->createBloomFilterCache( $this->createMockRepository() );

		$this->assertFalse( $cache->getCachedBloomFilterStatus( self::CENTRAL_ID ) );
	}

	public function testRebuildBloomFilter_usesPrimaryReadForCacheRebuild() {
		$repository = $this->createMock( ReadingListRepository::class );
		$repository->expects( $this->once() )->method( 'getSavedPagesCacheSetOptions' )
			->with( IDBAccessObject::READ_LATEST )
			->willReturn( [] );
		$repository->expects( $this->once() )->method( 'getSavedPageTitlesForProject' )
			->with( '@local', self::MAX_ITEMS + 1, IDBAccessObject::READ_LATEST )
			->willReturn( [ 'Cat' ] );

		$cache = $this->createBloomFilterCache( $repository );
		$cache->rebuildBloomFilter( self::CENTRAL_ID );
	}

	public function testGetBloomFilterStatus_returnsFatalForInvalidProjectConfig() {
		$repository = $this->createMock( ReadingListRepository::class );
		$repository->expects( $this->once() )->method( 'getSavedPageTitlesForProject' )
			->willThrowException( $this->createMock( ReadingListRepositoryException::class ) );

		$cache = $this->createBloomFilterCache( $repository );
		$cache->rebuildBloomFilter( self::CENTRAL_ID );

		$status = $cache->getCachedBloomFilterStatus( self::CENTRAL_ID );

		$this->assertInstanceOf( \StatusValue::class, $status );
		$this->assertFalse( $status->isOK() );
	}

	public function testGetBloomFilterStatus_returnsNullWhenUserExceedsMaxItems() {
		$titles = array_map(
			static fn ( int $i ) => "Page_$i",
			range( 1, self::MAX_ITEMS + 1 )
		);

		$cache = $this->createBloomFilterCache( $this->createMockRepository( $titles ) );
		$cache->rebuildBloomFilter( self::CENTRAL_ID );

		$status = $cache->getCachedBloomFilterStatus( self::CENTRAL_ID );

		$this->assertInstanceOf( \StatusValue::class, $status );
		$this->assertTrue( $status->isOK() );
		$this->assertNull( $status->getValue() );
	}

	public function testInvalidateBloomFilter_marksCachedFilterStale() {
		$cache = $this->createBloomFilterCache( $this->createMockRepository( [ 'Cat' ] ) );
		$cache->rebuildBloomFilter( self::CENTRAL_ID );

		$this->assertInstanceOf( \StatusValue::class, $cache->getCachedBloomFilterStatus( self::CENTRAL_ID ) );

		$cache->invalidateBloomFilter( self::CENTRAL_ID );

		$this->assertFalse( $cache->getCachedBloomFilterStatus( self::CENTRAL_ID ) );
	}

	public function testGetCachedBloomFilterStatus_returnsFalseForVersionMismatch() {
		$cache = $this->createBloomFilterCache( $this->createMockRepository( [ 'Cat' ] ) );
		$cache->rebuildBloomFilter( self::CENTRAL_ID );

		$this->cache->set(
			$this->cache->makeKey( 'readinglists', 'bloom', self::CENTRAL_ID ),
			[
				'state' => BookmarkBloomFilterCache::BUILD_SUCCESS,
				'filter' => [],
			],
			3600,
			[ 'version' => BookmarkBloomFilterCache::CACHE_VERSION + 1 ]
		);

		$this->assertFalse( $cache->getCachedBloomFilterStatus( self::CENTRAL_ID ) );
	}

	public function testRebuildBloomFilter_normalizesTitleSpacesToUnderscores() {
		$cache = $this->createBloomFilterCache( $this->createMockRepository( [ 'Main Page' ] ) );
		$cache->rebuildBloomFilter( self::CENTRAL_ID );

		$status = $cache->getCachedBloomFilterStatus( self::CENTRAL_ID );

		$this->assertInstanceOf( \StatusValue::class, $status );
		$this->assertTrue( $status->isOK() );
		$this->assertTrue( $status->getValue()->exists( 'Main_Page' ) );
		$this->assertFalse( $status->getValue()->exists( 'Other_Page' ) );
	}

	public function testConstructor_throwsOnInvalidMaxItems() {
		$this->expectException( \InvalidArgumentException::class );
		new BookmarkBloomFilterCache(
			$this->createMock( ReadingListRepositoryFactory::class ),
			$this->cache,
			new NullLogger(),
			0
		);
	}
}
