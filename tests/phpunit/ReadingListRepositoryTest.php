<?php

namespace MediaWiki\Extension\ReadingLists\Tests;

use MediaWiki\Extension\ReadingLists\Doc\ReadingListEntryRow;
use MediaWiki\Extension\ReadingLists\Doc\ReadingListRow;
use MediaWiki\Extension\ReadingLists\ReadingListRepository;
use MediaWiki\Extension\ReadingLists\ReadingListRepositoryException;
use MediaWiki\MediaWikiServices;
use MediaWikiIntegrationTestCase;
use PHPUnit\Framework\Constraint\Exception;
use Wikimedia\Rdbms\IResultWrapper;
use Wikimedia\Rdbms\LBFactory;

/**
 * @group Database
 * @covers \MediaWiki\Extension\ReadingLists\ReadingListRepository
 * @covers \MediaWiki\Extension\ReadingLists\ReadingListRepositoryException
 */
class ReadingListRepositoryTest extends MediaWikiIntegrationTestCase {

	use ReadingListsTestHelperTrait;

	/** @var LBFactory */
	private $lbFactory;

	public function setUp(): void {
		parent::setUp();
		$this->lbFactory = $this->getServiceContainer()->getDBLoadBalancerFactory();
	}

	/**
	 * @dataProvider provideAssertUser
	 */
	public function testAssertUser( $method ) {
		$this->addProjects( [ 'dummy' ] );
		$repository = $this->getReadingListRepository();
		$call = func_get_args();
		$this->assertFailsWith( 'readinglists-db-error-user-required',
			static function () use ( $repository, $call ) {
				$method = array_shift( $call );
				$params = $call;
				call_user_func_array( [ $repository, $method ], $params );
			}
		);
	}

	/**
	 * @return array
	 */
	public static function provideAssertUser() {
		return [
			[ 'setupForUser' ],
			[ 'teardownForUser' ],
			[ 'getDefaultListIdForUser' ],
			[ 'addList', 'foo' ],
			[ 'getAllLists', ReadingListRepository::SORT_BY_NAME,
				ReadingListRepository::SORT_DIR_ASC ],
			[ 'updateList', 1, 'foo' ],
			[ 'deleteList', 1 ],
			[ 'addListEntry', 1, 'foo', 'bar' ],
			[ 'getListEntries', [ 1 ], ReadingListRepository::SORT_BY_NAME,
				ReadingListRepository::SORT_DIR_ASC ],
			[ 'deleteListEntry', 1 ],
			[ 'getListsByDateUpdated', wfTimestampNow() ],
			[ 'getListsByPage', 'foo', 'bar' ],
		];
	}

	/**
	 * @dataProvider provideUninitializedErrors
	 */
	public function testUninitializedErrors( $method ) {
		$this->addProjects( [ 'dummy' ] );
		$this->addDataForAnotherUser();
		$repository = $this->getReadingListRepository( 1 );
		$call = func_get_args();
		$this->assertFailsWith( 'readinglists-db-error-not-set-up',
			static function () use ( $repository, $call ) {
				$method = array_shift( $call );
				$params = $call;
				call_user_func_array( [ $repository, $method ], $params );
			}
		);
	}

	/**
	 * @return array
	 */
	public static function provideUninitializedErrors() {
		return [
			[ 'teardownForUser' ],
			[ 'addList', 'foo' ],
			[ 'getAllLists', ReadingListRepository::SORT_BY_NAME,
				ReadingListRepository::SORT_DIR_ASC ],
			[ 'getListsByDateUpdated', wfTimestampNow() ],
			[ 'getListsByPage', 'foo', 'bar' ],
		];
	}

	public function testSetupFailsWithoutProjects() {
		$repository = $this->getReadingListRepository( 1 );
		$this->assertFailsWith( 'readinglists-db-error-no-projects',
			static function () use ( $repository ) {
				$repository->setupForUser();
			}
		);
	}

	public function testInitializeProjects() {
		$repository = $this->getReadingListRepository( 1 );

		$this->assertFalse(
			$repository->hasProjects(),
			'hasProjects() should initially return false'
		);
		$this->assertTrue(
			$repository->initializeProjectIfNeeded(),
			'initializeProjectIfNeeded() should return true if there ' .
			'were no projects in the database before'
		);

		$this->assertTrue(
			$repository->hasProjects(),
			'hasProjects() should return true after initializeProjectIfNeeded()'
		);
		$this->assertFalse(
			$repository->initializeProjectIfNeeded(),
			'initializeProjectIfNeeded() should return false if there ' .
			'already were projects in the database'
		);
	}

	public function testSetupAndTeardown() {
		$this->addProjects( [ 'dummy' ] );
		$this->addDataForAnotherUser();
		$repository = $this->getReadingListRepository( 1 );

		// no rows initially; isSetupForUser() is false
		$this->assertFalse( $repository->getDefaultListIdForUser() );
		$res = $this->getDb()->newSelectQueryBuilder()
			->select( '*' )
			->from( 'reading_list' )
			->where( [ 'rl_user_id' => 1 ] )
			->fetchResultSet();
		$this->assertSame( 0, $res->numRows() );

		// one row after setup; isSetupForUser() is true
		$list = $repository->setupForUser();
		$this->assertFailsWith( 'readinglists-db-error-already-set-up',
			static function () use ( $repository ) {
				$repository->setupForUser();
			}
		);
		$this->assertNotFalse( $repository->getDefaultListIdForUser() );
		$res = $this->getDb()->newSelectQueryBuilder()
			->select( '*' )
			->from( 'reading_list' )
			->where( [ 'rl_user_id' => 1 ] )
			->fetchResultSet();
		$this->assertSame( 1, $res->numRows() );
		/** @var ReadingListRow $row */
		$row = $res->fetchObject();
		$this->assertSame( '1', $row->rl_is_default );
		$this->assertEquals( 'default', $row->rl_name );

		// default list data is returned from setupForUser()
		$data = (array)$list;
		// Save the default list id, to compare with later.
		$oldDefaultListId = $data['rl_id'];

		unset( $data['rl_id'], $data['rl_date_created'], $data['rl_date_updated'] );
		$this->assertArrayEquals( [
			'rl_user_id' => '1',
			'rl_name' => 'default',
			'rl_description' => '',
			'rl_is_default' => '1',
			'rl_deleted' => '0',
			'rl_size' => '0'
		], $data, false, true );

		// Add a non-default list to the table to ensure it gets
		// torn down as well as the default one.
		$this->addLists( 1, [
			[
				'rl_name' => 'not-a-default-list',
				'rl_date_created' => '20100101000000',
				'rl_date_updated' => '20120101000000',
			],
		] );

		$repository->teardownForUser();

		$this->assertFalse( $repository->getDefaultListIdForUser(),
			"teardownForUser failed to reset isSetupForUser value"
		);

		$res = $this->getDb()->newSelectQueryBuilder()
			->select( '*' )
			->from( 'reading_list' )
			->where( [ 'rl_user_id' => 1, 'rl_deleted' => 0 ] )
			->fetchResultSet();
		$this->assertSame( 0, $res->numRows(),
			"teardownForUser failed to soft-delete all lists"
		);

		$list = (array)$repository->setupForUser();
		$this->assertNotEquals( $list['rl_id'], $oldDefaultListId,
			"new default list has same id as old default list"
		);
	}

	/**
	 * @param int|null $centralId
	 * @return ReadingListRepository
	 */
	private function getReadingListRepository( ?int $centralId = null ): ReadingListRepository {
		return new ReadingListRepository( $centralId, $this->lbFactory );
	}

	public function testAddList() {
		$this->addProjects( [ 'dummy' ] );
		$repository = $this->getReadingListRepository( 1 );
		$repository->setupForUser();

		$list = $repository->addList( 'foo' );
		$this->assertTimestampEquals( wfTimestampNow(), $list->rl_date_created );
		$this->assertTimestampEquals( wfTimestampNow(), $list->rl_date_updated );
		$data = (array)$list;
		unset( $data['rl_id'], $data['rl_date_created'], $data['rl_date_updated'] );
		$this->assertArrayEquals( [
			'rl_user_id' => '1',
			'rl_name' => 'foo',
			'rl_description' => '',
			'rl_is_default' => '0',
			'rl_deleted' => '0',
			'rl_size' => '0',
			'merged' => false,
		], $data, false, true );
		/** @var ReadingListRow $row */
		$row = $this->getDb()->newSelectQueryBuilder()
			->select( '*' )
			->from( 'reading_list' )
			->where( [ 'rl_id' => $list->rl_id ] )
			->caller( __METHOD__ )->fetchRow();
		$this->assertTimestampEquals( wfTimestampNow(), $row->rl_date_created );
		$this->assertTimestampEquals( wfTimestampNow(), $row->rl_date_updated );
		$data2 = (array)$row;
		unset( $data['merged'], $data2['rl_id'], $data2['rl_date_created'], $data2['rl_date_updated'] );
		$this->assertArrayEquals( $data + [
			'rl_size' => '0',
		], $data2, false, true );

		$list = $repository->addList( 'bar', 'here is some bar' );
		$this->assertTimestampEquals( wfTimestampNow(), $list->rl_date_created );
		$this->assertTimestampEquals( wfTimestampNow(), $list->rl_date_updated );
		$data = (array)$list;
		unset( $data['rl_id'], $data['rl_date_created'], $data['rl_date_updated'] );
		$this->assertArrayEquals( [
			'rl_user_id' => '1',
			'rl_name' => 'bar',
			'rl_description' => 'here is some bar',
			'rl_is_default' => '0',
			'rl_deleted' => '0',
			'rl_size' => '0',
			'merged' => false,
		], $data, false, true );

		$mergedList = $repository->addList( 'bar', 'more bar' );
		$this->assertEquals( $list->rl_id, $mergedList->rl_id );
		$this->assertEquals( 'more bar', $mergedList->rl_description );
		$this->assertTrue( $mergedList->merged );

		$mergedList = $repository->addList( 'bar', 'more bar' );
		$this->assertEquals( $list->rl_id, $mergedList->rl_id );
		$this->assertTrue( $mergedList->merged );

		$this->assertFailsWith( 'readinglists-db-error-too-long', static function () use ( $repository ) {
			$repository->addList( 'boom', str_pad( '', 1000, 'x' ) );
		} );
	}

	public function testAddList_count() {
		$this->addProjects( [ 'dummy' ] );
		$repository = $this->getReadingListRepository( 1 );
		$repository->setLimits( 2, null );
		$repository->setupForUser();

		$list = $repository->addList( 'foo' );
		$repository->deleteList( $list->rl_id );
		$list2 = $repository->addList( 'bar' );
		$this->assertFailsWith( 'readinglists-db-error-list-limit', static function () use ( $repository ) {
			$repository->addList( 'baz' );
		} );

		// test that duplicates do not count towards the limit; since limit check is done before
		// checking duplicates, the duplicate cannot be the limit+1th item
		$repository->deleteList( $list2->rl_id );
		$repository->addList( 'default' );
		$repository->addList( 'default' );
		$repository->addList( 'bar' );
	}

	/**
	 * @dataProvider provideGetAllLists
	 */
	public function testGetAllLists( array $args, array $expected ) {
		$this->addProjects( [ 'dummy' ] );
		$this->addDataForAnotherUser();
		$repository = $this->getReadingListRepository( 1 );
		$repository->setupForUser();
		$this->addLists( 1, [
			[
				'rl_name' => 'foo1',
				'rl_date_created' => '20100101000000',
				'rl_date_updated' => '20120101000000',
				'rl_deleted' => '0',
			],
			[
				'rl_name' => 'foo2',
				'rl_description' => 'this is the second foo',
				'rl_date_created' => '20170101000000',
				'rl_date_updated' => '20170101000000',
				'rl_deleted' => '0',
			],
			[
				'rl_name' => 'bar',
				'rl_date_created' => '20010101000000',
				'rl_date_updated' => '20120101000000',
				'rl_deleted' => '0',
			],
			[
				'rl_name' => 'deleted-123',
				'rl_description' => 'deleted',
				'rl_date_created' => '20170101000000',
				'rl_date_updated' => '20110101000000',
				'rl_deleted' => '1',
			],
		] );
		$compareResultItems = function ( array $expected, array $actual ) {
			$this->assertTimestampEquals( $expected['rl_date_created'], $actual['rl_date_created'],
				"expected: {$expected['rl_name']}; actual: {$actual['rl_name']}" );
			$this->assertTimestampEquals( $expected['rl_date_updated'], $actual['rl_date_updated'],
				"expected: {$expected['rl_name']}; actual: {$actual['rl_name']}" );
			unset( $expected['rl_date_created'], $expected['rl_date_updated'] );
			unset( $actual['rl_id'], $actual['rl_date_created'], $actual['rl_date_updated'] );
			$this->assertArrayEquals( $expected, $actual, false, true );
		};
		$compare = function ( array $expected, IResultWrapper $res ) use ( $compareResultItems ) {
			$data = $this->resultWrapperToArray( $res );
			$this->assertSameSize( $expected, $data, 'result length is different!' );
			array_map( $compareResultItems, $expected, $data );
		};

		$res = call_user_func_array( [ $repository, 'getAllLists' ], $args );
		$compare( $expected, $res );
	}

	public static function provideGetAllLists() {
		$lists = [
			'default' => [
				'rl_name' => 'default',
				'rl_description' => '',
				'rl_is_default' => '1',
				'rl_date_created' => wfTimestampNow(),
				'rl_date_updated' => wfTimestampNow(),
				'rl_deleted' => '0',
				'rl_size' => '0'
			],
			'foo' => [
				'rl_name' => 'foo1',
				'rl_description' => '',
				'rl_is_default' => '0',
				'rl_date_created' => '20100101000000',
				'rl_date_updated' => '20120101000000',
				'rl_deleted' => '0',
				'rl_size' => '0'
			],
			'foo_2' => [
				'rl_name' => 'foo2',
				'rl_description' => 'this is the second foo',
				'rl_is_default' => '0',
				'rl_date_created' => '20170101000000',
				'rl_date_updated' => '20170101000000',
				'rl_deleted' => '0',
				'rl_size' => '0'
			],
			'bar' => [
				'rl_name' => 'bar',
				'rl_description' => '',
				'rl_is_default' => '0',
				'rl_date_created' => '20010101000000',
				'rl_date_updated' => '20120101000000',
				'rl_deleted' => '0',
				'rl_size' => '0'
			],
		];
		// 1 list from addDataForAnotherUser, 1 from setupForUser, plus 1-based index in addLists()
		$fooId = 3;
		$foo2Id = 4;
		$barId = 5;

		// Assert the default list is first, unless continue is passsed
		return [
			'name, basic' => [
				[ ReadingListRepository::SORT_BY_NAME, ReadingListRepository::SORT_DIR_ASC ],
				[ $lists['default'], $lists['bar'], $lists['foo'], $lists['foo_2'] ],
			],
			'name, reverse' => [
				[ ReadingListRepository::SORT_BY_NAME, ReadingListRepository::SORT_DIR_DESC ],
				[ $lists['default'], $lists['foo_2'], $lists['foo'], $lists['bar'] ],
			],
			'name, limit' => [
				[ ReadingListRepository::SORT_BY_NAME, ReadingListRepository::SORT_DIR_ASC, 1 ],
				[ $lists['default'] ],
			],
			'name, limit + offset' => [
				[ ReadingListRepository::SORT_BY_NAME, ReadingListRepository::SORT_DIR_ASC,
					1, [ 'foo', 2 ] ],
				[ $lists['foo'] ],
			],
			'updated, basic' => [
				[ ReadingListRepository::SORT_BY_UPDATED, ReadingListRepository::SORT_DIR_ASC ],
				[ $lists['default'], $lists['foo'], $lists['bar'], $lists['foo_2'] ],
			],
			'updated, limit' => [
				[ ReadingListRepository::SORT_BY_UPDATED, ReadingListRepository::SORT_DIR_ASC, 1 ],
				[ $lists['default'] ],
			],
			'updated, limit + offset' => [
				[ ReadingListRepository::SORT_BY_UPDATED, ReadingListRepository::SORT_DIR_ASC,
				  1, [ '20120101000000', $fooId ] ],
				[ $lists['foo'] ],
			],
			'updated, limit + other offset' => [
				[ ReadingListRepository::SORT_BY_UPDATED, ReadingListRepository::SORT_DIR_ASC,
				  1, [ '20120101000000', $barId ] ],
				[ $lists['bar'] ],
			],
		];
	}

	public function testUpdateList() {
		$this->addProjects( [ 'dummy' ] );
		$repository = $this->getReadingListRepository( 1 );
		$repository->setupForUser();
		[ $listId, $listId2, $deletedListId ] = $this->addLists( 1, [
			[
				'rl_name' => 'foo',
				'rl_description' => 'xxx',
				'rl_date_created' => '20100101000000',
				'rl_date_updated' => '20120101000000',
				'rl_deleted' => '0',
			],
			[
				'rl_name' => 'foo2',
				'rl_description' => 'xxx',
				'rl_date_created' => '20100101000000',
				'rl_date_updated' => '20120101000000',
				'rl_deleted' => '0',
			],
			[
				'rl_name' => 'deleted-123',
				'rl_description' => 'yyy',
				'rl_date_created' => '20100101000000',
				'rl_date_updated' => '20120101000000',
				'rl_deleted' => '1',
			],
		] );

		$list = $repository->updateList( $listId, 'bar' );
		$this->assertEquals( 'bar', $list->rl_name );
		$this->assertEquals( 'xxx', $list->rl_description );
		$this->assertTimestampEquals( '20100101000000', $list->rl_date_created );
		$this->assertTimestampEquals( wfTimestampNow(), $list->rl_date_updated );
		/** @var ReadingListRow $row */
		$row = $this->getDb()->newSelectQueryBuilder()
			->select( '*' )
			->from( 'reading_list' )
			->where( [ 'rl_id' => $listId ] )
			->caller( __METHOD__ )->fetchRow();
		$this->assertEquals( $list->rl_name, $row->rl_name );
		$this->assertEquals( $list->rl_description, $row->rl_description );
		$this->assertTimestampEquals( $list->rl_date_created, $row->rl_date_created );
		$this->assertTimestampEquals( $list->rl_date_updated, $row->rl_date_updated );

		$list = $repository->updateList( $listId, 'bar', 'yyy' );
		$this->assertEquals( 'bar', $list->rl_name );
		$this->assertEquals( 'yyy', $list->rl_description );

		$this->assertFailsWith( 'readinglists-db-error-no-such-list',
			static function () use ( $repository ) {
				$repository->updateList( 123, 'foo' );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-not-own-list',
			function () use ( $listId ) {
				$repository = $this->getReadingListRepository( 123 );
				$repository->updateList( $listId, 'foo' );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-list-deleted',
			static function () use ( $repository, $deletedListId ) {
				$repository->updateList( $deletedListId, 'bar' );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-duplicate-list',
			static function () use ( $repository, $listId ) {
				$repository->updateList( $listId, 'foo2' );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-cannot-update-default-list',
			function () use ( $repository ) {
				$defaultId = $this->getDb()->newSelectQueryBuilder()
					->select( 'rl_id' )
					->from( 'reading_list' )
					->where( [ 'rl_user_id' => 1, 'rl_is_default' => 1 ] )
					->fetchField();
				$this->assertNotFalse( $defaultId );
				$repository->updateList( $defaultId, 'not default' );
			}
		);
	}

	public function testDeleteList() {
		$this->addProjects( [ 'dummy' ] );
		$repository = $this->getReadingListRepository( 1 );
		$repository->setupForUser();
		[ $listId, $deletedListId ] = $this->addLists( 1, [
			[
				'rl_name' => 'foo',
				'rl_deleted' => '0',
			],
			[
				'rl_name' => 'deleted-123',
				'rl_deleted' => '1',
			],
		] );

		$repository->deleteList( $listId );
		$this->assertSame(
			0,
			$this->getDb()->newSelectQueryBuilder()
				->select( '1' )
				->from( 'reading_list' )
				->where( [ 'rl_user_id' => 1, 'rl_name' => 'foo', 'rl_deleted' => 0 ] )
				->fetchRowCount()
		);
		$this->assertTimestampEquals(
			wfTimestampNow(),
			$this->getDb()->newSelectQueryBuilder()
				->select( 'rl_date_updated' )
				->from( 'reading_list' )
				->where( [ 'rl_id' => $listId ] )
				->caller( __METHOD__ )->fetchField()
		);
		$this->assertFailsWith( 'readinglists-db-error-no-such-list',
			static function () use ( $repository ) {
				$repository->deleteList( 123 );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-not-own-list',
			function () use ( $listId ) {
				$repository = $this->getReadingListRepository( 123 );
				$repository->deleteList( $listId );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-list-deleted',
			static function () use ( $repository, $deletedListId ) {
				$repository->deleteList( $deletedListId );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-cannot-delete-default-list',
			function () use ( $repository ) {
				$defaultId = $this->getDb()->newSelectQueryBuilder()
					->select( 'rl_id' )
					->from( 'reading_list' )
					->where( [ 'rl_user_id' => 1, 'rl_is_default' => 1 ] )
					->fetchField();
				$this->assertNotFalse( $defaultId );
				$repository->deleteList( $defaultId );
			}
		);
	}

	public function testGetSavedPageTitlesForProject_excludesDeletedLists() {
		$this->addProjects( [ 'dummy', 'https://en.wikipedia.org' ] );
		$repository = $this->getReadingListRepository( 1 );
		$repository->setupForUser();

		$activeList = $repository->addList( 'active', 'active' );
		$deletedList = $repository->addList( 'deleted', 'deleted' );

		$repository->addListEntry( $activeList->rl_id, 'https://en.wikipedia.org', 'Cat' );
		$repository->addListEntry( $deletedList->rl_id, 'https://en.wikipedia.org', 'Dog' );
		$repository->deleteList( $deletedList->rl_id );

		$this->assertEqualsCanonicalizing(
			[ 'Cat' ],
			$repository->getSavedPageTitlesForProject( 'https://en.wikipedia.org' )
		);
	}

	public function testAddListEntry() {
		$this->addProjects( [ 'dummy' ] );
		$repository = $this->getReadingListRepository( 1 );
		$repository->setupForUser();
		[ $projectId ] = $this->addProjects( [ 'https://en.wikipedia.org',
			'https://de.wikipedia.org' ] );
		[ $listId, $deletedListId ] = $this->addLists( 1, [
			[
				'rl_name' => 'foo',
				'rl_deleted' => '0',
			],
			[
				'rl_name' => 'deleted-123',
				'rl_deleted' => '1',
			],
		] );

		$entry = $repository->addListEntry( $listId, 'https://en.wikipedia.org', 'Foo' );
		$this->assertEquals( 'Foo', $entry->rle_title );
		$this->assertTimestampEquals( wfTimestampNow(), $entry->rle_date_created );
		$this->assertTimestampEquals( wfTimestampNow(), $entry->rle_date_updated );
		$this->assertSame( '0', $entry->rle_deleted );
		$this->assertFalse( $entry->merged );
		/** @var ReadingListEntryRow $row */

		$row = $this->getDb()->newSelectQueryBuilder()
			->select( '*' )
			->from( 'reading_list_entry' )
			->where( [ 'rle_id' => $entry->rle_id ] )
			->caller( __METHOD__ )->fetchRow();
		$this->assertSame( '1', $row->rle_user_id );
		$this->assertEquals( $projectId, $row->rle_rlp_id );
		$this->assertEquals( $entry->rle_title, $row->rle_title );
		$this->assertTimestampEquals( $entry->rle_date_created, $row->rle_date_created );
		$this->assertTimestampEquals( $entry->rle_date_updated, $row->rle_date_updated );
		$this->assertEquals( $entry->rle_deleted, $row->rle_deleted );

		// test that deletion + recreation does not trip the unique contstraint
		$repository->deleteListEntry( $entry->rle_id );
		$entry2 = $repository->addListEntry( $listId, 'https://en.wikipedia.org', 'Foo' );
		$this->assertEquals( $entry->rle_id, $entry2->rle_id );
		$this->assertEquals( 'Foo', $entry2->rle_title );
		$this->assertTimestampEquals( wfTimestampNow(), $entry2->rle_date_created );
		$this->assertTimestampEquals( wfTimestampNow(), $entry2->rle_date_updated );
		$this->assertSame( '0', $entry2->rle_deleted );
		$this->assertFalse( $entry->merged );

		$repository->addListEntry( $listId, 'https://en.wikipedia.org', 'Bar' );
		$repository->addListEntry( $listId, 'https://de.wikipedia.org', 'Foo' );

		// test that adding a duplicate is a no-op
		$dupeEntry = $repository->addListEntry( $listId, 'https://en.wikipedia.org', 'Foo' );
		$this->assertEquals( $dupeEntry->rle_id, $entry2->rle_id );
		$this->assertTrue( $dupeEntry->merged );

		$this->assertFailsWith( 'readinglists-db-error-no-such-list',
			static function () use ( $repository ) {
				$repository->addListEntry( 123, 'https://en.wikipedia.org', 'A' );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-not-own-list',
			function () use ( $listId ) {
				$repository = $this->getReadingListRepository( 123 );
				$repository->addListEntry( $listId, 'https://en.wikipedia.org', 'B' );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-list-deleted',
			static function () use ( $repository, $deletedListId ) {
				$repository->addListEntry( $deletedListId, 'https://en.wikipedia.org', 'C' );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-no-such-project',
			static function () use ( $repository, $listId ) {
				$repository->addListEntry( $listId, 'https://nosuch.project.org', 'Foo' );
			}
		);
	}

	public function testAddListEntry_normalizesLocalTitleOnInsert() {
		$this->addProjects( [ 'dummy' ] );
		$repository = $this->getReadingListRepository( 1 );
		$repository->setupForUser();

		$urlUtils = MediaWikiServices::getInstance()->getUrlUtils();
		$parts = $urlUtils->parse( $urlUtils->getCanonicalServer() );
		$parts['port'] = null;
		$localProject = $urlUtils->assemble( $parts );
		$this->addProjects( [ $localProject, 'https://commons.wikimedia.org' ] );
		[ $listId ] = $this->addLists( 1, [
			[
				'rl_name' => 'foo',
				'rl_deleted' => '0',
			],
		] );

		$localEntry = $repository->addListEntry( $listId, '@local', 'formula one' );
		$this->assertSame(
			'Formula_one',
			$localEntry->rle_title,
			'Converts spaces to underscores and first-letter capitalization for local titles'
		);
	}

	public function testAddListEntry_normalizesCrossProjectTitleOnInsert() {
		$this->addProjects( [ 'dummy' ] );
		$repository = $this->getReadingListRepository( 1 );
		$repository->setupForUser();

		$urlUtils = MediaWikiServices::getInstance()->getUrlUtils();
		$parts = $urlUtils->parse( $urlUtils->getCanonicalServer() );
		$parts['port'] = null;
		$localProject = $urlUtils->assemble( $parts );
		$this->addProjects( [ $localProject, 'https://commons.wikimedia.org' ] );
		[ $listId ] = $this->addLists( 1, [
			[
				'rl_name' => 'foo',
				'rl_deleted' => '0',
			],
		] );

		$crossWikiEntry = $repository->addListEntry(
			$listId,
			'https://commons.wikimedia.org',
			'formula one'
		);
		$this->assertSame(
			'formula_one',
			$crossWikiEntry->rle_title,
			'Only converts spaces to underscores for cross-project titles, not capitalization'
		);
	}

	public function testAddListEntry_matchesExistingLocalEntryAfterNormalization() {
		$this->addProjects( [ 'dummy' ] );
		$repository = $this->getReadingListRepository( 1 );
		$repository->setupForUser();

		$urlUtils = MediaWikiServices::getInstance()->getUrlUtils();
		$parts = $urlUtils->parse( $urlUtils->getCanonicalServer() );
		$parts['port'] = null;
		$localProject = $urlUtils->assemble( $parts );
		$this->addProjects( [ $localProject ] );
		[ $listId ] = $this->addLists( 1, [
			[
				'rl_name' => 'foo',
				'rl_deleted' => '0',
			],
		] );

		$localEntry = $repository->addListEntry( $listId, '@local', 'formula one' );
		$localDupeEntry = $repository->addListEntry( $listId, $localProject, 'Formula_one' );
		$this->assertSame( $localEntry->rle_id, $localDupeEntry->rle_id );
		$this->assertTrue( $localDupeEntry->merged );
	}

	public function testAddListEntry_matchesExistingCrossProjectEntryAfterNormalization() {
		$this->addProjects( [ 'dummy' ] );
		$repository = $this->getReadingListRepository( 1 );
		$repository->setupForUser();

		$this->addProjects( [ 'https://commons.wikimedia.org' ] );
		[ $listId ] = $this->addLists( 1, [
			[
				'rl_name' => 'foo',
				'rl_deleted' => '0',
			],
		] );

		$crossWikiEntry = $repository->addListEntry(
			$listId,
			'https://commons.wikimedia.org',
			'formula one'
		);

		$crossWikiDupeEntry = $repository->addListEntry(
			$listId,
			'https://commons.wikimedia.org',
			'formula_one'
		);
		$this->assertSame( $crossWikiEntry->rle_id, $crossWikiDupeEntry->rle_id );
		$this->assertTrue( $crossWikiDupeEntry->merged );
	}

	public function testGetListsByPage_matchesLocalPageAfterNormalization() {
		$this->addProjects( [ 'dummy' ] );
		$repository = $this->getReadingListRepository( 1 );
		$repository->setupForUser();

		$urlUtils = MediaWikiServices::getInstance()->getUrlUtils();
		$parts = $urlUtils->parse( $urlUtils->getCanonicalServer() );
		$parts['port'] = null;
		$localProject = $urlUtils->assemble( $parts );
		$this->addProjects( [ $localProject ] );
		[ $listId ] = $this->addLists( 1, [
			[
				'rl_name' => 'foo',
				'rl_deleted' => '0',
			],
		] );

		$repository->addListEntry( $listId, '@local', 'formula one' );

		$rows = $this->resultWrapperToArray(
			$repository->getListsByPage( $localProject, 'formula one', 10 )
		);
		$this->assertCount( 1, $rows );
		$this->assertSame( (string)$listId, $rows[0]['rl_id'] );
	}

	public function testGetListsByPage_matchesCrossProjectPageAfterNormalization() {
		$this->addProjects( [ 'dummy', 'https://commons.wikimedia.org' ] );
		$repository = $this->getReadingListRepository( 1 );
		$repository->setupForUser();
		[ $listId ] = $this->addLists( 1, [
			[
				'rl_name' => 'foo',
				'rl_deleted' => '0',
			],
		] );

		$repository->addListEntry( $listId, 'https://commons.wikimedia.org', 'formula one' );

		$rows = $this->resultWrapperToArray(
			$repository->getListsByPage( 'https://commons.wikimedia.org', 'formula one', 10 )
		);
		$this->assertCount( 1, $rows );
		$this->assertSame( (string)$listId, $rows[0]['rl_id'] );
	}

	public function testGetListsByPage_matchesLegacyLocalPageTitleVariants() {
		$this->addProjects( [ 'dummy' ] );
		$repository = $this->getReadingListRepository( 1 );
		$repository->setupForUser();

		$urlUtils = MediaWikiServices::getInstance()->getUrlUtils();
		$parts = $urlUtils->parse( $urlUtils->getCanonicalServer() );
		$parts['port'] = null;
		$localProject = $urlUtils->assemble( $parts );
		$this->addProjects( [ $localProject ] );
		[ $listId ] = $this->addLists( 1, [
			[
				'rl_name' => 'foo',
				'rl_deleted' => '0',
			],
		] );
		$this->addListEntries( $listId, 1, [
			[
				'rlp_project' => $localProject,
				'rle_title' => 'formula_one',
				'rle_date_created' => '20100101000000',
				'rle_date_updated' => '20150101000000',
				'rle_deleted' => 0,
			],
		] );

		$rows = $this->resultWrapperToArray(
			$repository->getListsByPage( $localProject, 'formula one', 10 )
		);
		$this->assertCount( 1, $rows );
		$this->assertSame( (string)$listId, $rows[0]['rl_id'] );
	}

	public function testGetListsByPage_deduplicatesListsWithMultipleMatchingEntries() {
		$this->addProjects( [ 'dummy' ] );
		$repository = $this->getReadingListRepository( 1 );
		$repository->setupForUser();

		$urlUtils = MediaWikiServices::getInstance()->getUrlUtils();
		$parts = $urlUtils->parse( $urlUtils->getCanonicalServer() );
		$parts['port'] = null;
		$localProject = $urlUtils->assemble( $parts );
		$this->addProjects( [ $localProject ] );
		[ $listId ] = $this->addLists( 1, [
			[
				'rl_name' => 'foo',
				'rl_deleted' => '0',
			],
		] );
		$this->addListEntries( $listId, 1, [
			[
				'rlp_project' => $localProject,
				'rle_title' => 'Formula_one',
				'rle_date_created' => '20100101000000',
				'rle_date_updated' => '20150101000000',
				'rle_deleted' => 0,
			],
			[
				'rlp_project' => $localProject,
				'rle_title' => 'formula_one',
				'rle_date_created' => '20100102000000',
				'rle_date_updated' => '20150102000000',
				'rle_deleted' => 0,
			],
		] );

		$rows = $this->resultWrapperToArray(
			$repository->getListsByPage( $localProject, 'formula one', 10 )
		);
		$this->assertCount( 1, $rows );
		$this->assertSame( (string)$listId, $rows[0]['rl_id'] );
	}

	public function testAddListEntry_count() {
		$this->addProjects( [ 'dummy' ] );
		$repository = $this->getReadingListRepository( 1 );
		$repository->setLimits( null, 1 );
		$repository->setupForUser();

		$this->addProjects( [ 'https://en.wikipedia.org' ] );
		[ $listId ] = $this->addLists( 1, [
			[
				'rl_name' => 'foo',
				'rl_deleted' => '0',
			],
		] );
		// assert that limits work
		$entry = $repository->addListEntry( $listId, 'https://en.wikipedia.org', 'Foo' );
		$this->assertFailsWith( 'readinglists-db-error-entry-limit',
			static function () use ( $repository, $listId ) {
				$repository->addListEntry( $listId, 'https://en.wikipedia.org', 'Baz' );
			}
		);
		// assert that deleting frees up space on the list
		$repository->deleteListEntry( $entry->rle_id );
		$entry2 = $repository->addListEntry( $listId, 'https://en.wikipedia.org', 'Bar' );
		$this->assertFailsWith( 'readinglists-db-error-entry-limit',
			static function () use ( $repository, $listId ) {
				$repository->addListEntry( $listId, 'https://en.wikipedia.org', 'Baz' );
			}
		);
		// assert that recreating a deleted entry is counted normally
		$repository->deleteListEntry( $entry2->rle_id );
		$repository->addListEntry( $listId, 'https://en.wikipedia.org', 'Bar' );
		$this->assertFailsWith( 'readinglists-db-error-entry-limit',
			static function () use ( $repository, $listId ) {
				$repository->addListEntry( $listId, 'https://en.wikipedia.org', 'Baz' );
			}
		);

		// assert that duplicates do not take up space; we must do a deletion first since inserting
		// into a full list would be rejected even if it's a duplicate
		$repository->setLimits( null, 2 );
		[ $listId2 ] = $this->addLists( 1, [
			[
				'rl_name' => 'bar',
				'rl_deleted' => '0',
			],
		] );
		$repository->addListEntry( $listId2, 'https://en.wikipedia.org', 'Foo' );
		$repository->addListEntry( $listId2, 'https://en.wikipedia.org', 'Foo' );
		$repository->addListEntry( $listId2, 'https://en.wikipedia.org', 'Bar' );
		$this->assertFailsWith( 'readinglists-db-error-entry-limit',
			static function () use ( $repository, $listId2 ) {
				$repository->addListEntry( $listId2, 'https://en.wikipedia.org', 'Baz' );
			}
		);
	}

	public function testDeleteListEntriesByPageTitleAndProject() {
		$this->addProjects( [ 'dummy' ] );
		$repository = $this->getReadingListRepository( 1 );
		$repository->setupForUser();

		$urlUtils = MediaWikiServices::getInstance()->getUrlUtils();
		$parts = $urlUtils->parse( $urlUtils->getCanonicalServer() );
		$parts['port'] = null;
		$localProject = $urlUtils->assemble( $parts );
		$this->addProjects( [ $localProject ] );
		[ $listId, $listId2 ] = $this->addLists( 1, [
			[
				'rl_name' => 'foo',
				'rl_deleted' => '0',
			],
			[
				'rl_name' => 'bar',
				'rl_deleted' => '0',
			],
		] );
		[ $spaceEntryId, $otherEntryId ] = $this->addListEntries( $listId, 1, [
			[
				'rlp_project' => $localProject,
				'rle_title' => 'Foo Bar',
				'rle_date_created' => '20100101000000',
				'rle_date_updated' => '20150101000000',
				'rle_deleted' => 0,
			],
			[
				'rlp_project' => $localProject,
				'rle_title' => 'Something Else',
				'rle_date_created' => '20100101000000',
				'rle_date_updated' => '20150101000000',
				'rle_deleted' => 0,
			],
		] );
		[ $underscoreEntryId ] = $this->addListEntries( $listId2, 1, [
			[
				'rlp_project' => $localProject,
				'rle_title' => 'Foo_Bar',
				'rle_date_created' => '20100101000000',
				'rle_date_updated' => '20150101000000',
				'rle_deleted' => 0,
			],
		] );

		$repository->deleteListEntriesByPageTitleAndProject( 'Foo_Bar', $localProject );

		$rows = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'rle_id', 'rle_deleted' ] )
			->from( 'reading_list_entry' )
			->where( [ 'rle_id' => [ $spaceEntryId, $otherEntryId, $underscoreEntryId ] ] )
			->caller( __METHOD__ )
			->fetchResultSet();
		$deletedById = [];
		foreach ( $rows as $row ) {
			$deletedById[$row->rle_id] = $row->rle_deleted;
		}
		$this->assertSame( '1', $deletedById[$spaceEntryId] );
		$this->assertSame( '0', $deletedById[$otherEntryId] );
		$this->assertSame( '1', $deletedById[$underscoreEntryId] );

		$sizes = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'rl_id', 'rl_size' ] )
			->from( 'reading_list' )
			->where( [ 'rl_id' => [ $listId, $listId2 ] ] )
			->caller( __METHOD__ )
			->fetchResultSet();
		$sizesById = [];
		foreach ( $sizes as $row ) {
			$sizesById[$row->rl_id] = $row->rl_size;
		}
		$this->assertSame( '1', $sizesById[$listId] );
		$this->assertSame( '0', $sizesById[$listId2] );
	}

	public function testDeleteListEntriesByPageTitleAndProject_normalizesLocalLookup() {
		$this->addProjects( [ 'dummy' ] );
		$repository = $this->getReadingListRepository( 1 );
		$repository->setupForUser();

		$urlUtils = MediaWikiServices::getInstance()->getUrlUtils();
		$parts = $urlUtils->parse( $urlUtils->getCanonicalServer() );
		$parts['port'] = null;
		$localProject = $urlUtils->assemble( $parts );
		$this->addProjects( [ $localProject ] );
		[ $listId ] = $this->addLists( 1, [
			[
				'rl_name' => 'foo',
				'rl_deleted' => '0',
			],
		] );

		$entry = $repository->addListEntry( $listId, '@local', 'formula one' );
		$repository->deleteListEntriesByPageTitleAndProject( 'formula one', $localProject );

		$row = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'rle_deleted' ] )
			->from( 'reading_list_entry' )
			->where( [ 'rle_id' => $entry->rle_id ] )
			->caller( __METHOD__ )
			->fetchRow();
		$this->assertSame( '1', $row->rle_deleted );
	}

	public function testDeleteListEntriesByPageTitleAndProject_matchesLegacyLocalTitleVariants() {
		$this->addProjects( [ 'dummy' ] );
		$repository = $this->getReadingListRepository( 1 );
		$repository->setupForUser();

		$urlUtils = MediaWikiServices::getInstance()->getUrlUtils();
		$parts = $urlUtils->parse( $urlUtils->getCanonicalServer() );
		$parts['port'] = null;
		$localProject = $urlUtils->assemble( $parts );
		$this->addProjects( [ $localProject ] );
		[ $listId ] = $this->addLists( 1, [
			[
				'rl_name' => 'foo',
				'rl_deleted' => '0',
			],
		] );
		[ $entryId ] = $this->addListEntries( $listId, 1, [
			[
				'rlp_project' => $localProject,
				'rle_title' => 'formula_one',
				'rle_date_created' => '20100101000000',
				'rle_date_updated' => '20150101000000',
				'rle_deleted' => 0,
			],
		] );

		$repository->deleteListEntriesByPageTitleAndProject( 'formula one', $localProject );

		$row = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'rle_deleted' ] )
			->from( 'reading_list_entry' )
			->where( [ 'rle_id' => $entryId ] )
			->caller( __METHOD__ )
			->fetchRow();
		$this->assertSame( '1', $row->rle_deleted );
	}

	/**
	 * @dataProvider provideGetListEntries
	 */
	public function testGetListEntries( array $args, array $expected ) {
		$this->addProjects( [ 'dummy' ] );
		$repository = $this->getReadingListRepository( 1 );
		$repository->setupForUser();
		$defaultId = 1;
		$this->addListEntries( $defaultId, 1, [
			[
				'rle_user_id' => 1,
				'rlp_project' => 'foo',
				'rle_title' => 'Foo',
				'rle_date_created' => wfTimestampNow(),
				'rle_date_updated' => wfTimestampNow(),
				'rle_deleted' => 0,
			],
		] );
		$this->addLists( 1, [
			[
				'rl_is_default' => 0,
				'rl_name' => 'test',
				'entries' => [
					[
						'rlp_project' => 'foo',
						'rle_title' => 'Bar',
						'rle_date_created' => '20100101000000',
						'rle_date_updated' => '20150101000000',
						'rle_deleted' => 0,
					],
					[
						'rlp_project' => 'foo2',
						'rle_title' => 'Bar2',
						'rle_date_created' => '20100101000000',
						'rle_date_updated' => '20120101000000',
						'rle_deleted' => 0,
					],
					[
						'rlp_project' => 'foo3',
						'rle_title' => 'Bar2',
						'rle_date_created' => '20100101000000',
						'rle_date_updated' => '20170101000000',
						'rle_deleted' => 0,
					],
					[
						'rlp_project' => 'foo4',
						'rle_title' => 'Bar4',
						'rle_date_created' => '20100101000000',
						'rle_date_updated' => '20160101000000',
						'rle_deleted' => 1,
					],
				],
			],
		] );
		$compareResultItems = function ( $expected, $actual, $n ) {
			$error = "Mismatch in item $n (expected project/title: {$expected['rlp_project']}"
				. "/{$expected['rle_title']}; actual: {$actual['rlp_project']}/{$actual['rle_title']})";
			$this->assertTimestampEquals( $expected['rle_date_created'], $actual['rle_date_created'],
				$error );
			$this->assertTimestampEquals( $expected['rle_date_updated'], $actual['rle_date_updated'],
				$error );
			unset( $expected['rle_date_created'], $expected['rle_date_updated'] );
			unset( $actual['rle_id'], $actual['rle_rlp_id'],
				$actual['rle_date_created'], $actual['rle_date_updated'] );
			$this->assertArrayEquals( $expected, $actual, false, true );
		};
		$compare = function ( $expected, $res ) use ( $compareResultItems ) {
			$data = $this->resultWrapperToArray( $res );
			$this->assertSameSize( $expected, $data, 'result length is different!' );
			array_map( $compareResultItems, $expected, $data, range( 1, count( $expected ) ) );
		};

		$res = call_user_func_array( [ $repository, 'getListEntries' ], $args );
		$compare( $expected, $res );
	}

	public static function provideGetListEntries() {
		$defaultId = 1;
		$testId = 2;
		$expected = [
			'default-foo' => [
				'rle_rl_id' => $defaultId,
				'rlp_project' => 'foo',
				'rle_title' => 'Foo',
				'rle_date_created' => wfTimestampNow(),
				'rle_date_updated' => wfTimestampNow(),
				'rle_deleted' => 0,
			],
			'list-foo' => [
				'rle_rl_id' => $testId,
				'rlp_project' => 'foo',
				'rle_title' => 'Bar',
				'rle_date_created' => '20100101000000',
				'rle_date_updated' => '20150101000000',
				'rle_deleted' => 0,
			],
			'list-foo2' => [
				'rle_rl_id' => $testId,
				'rlp_project' => 'foo2',
				'rle_title' => 'Bar2',
				'rle_date_created' => '20100101000000',
				'rle_date_updated' => '20120101000000',
				'rle_deleted' => 0,
			],
			'list-foo3' => [
				'rle_rl_id' => $testId,
				'rlp_project' => 'foo3',
				'rle_title' => 'Bar2',
				'rle_date_created' => '20100101000000',
				'rle_date_updated' => '20170101000000',
				'rle_deleted' => 0,
			],
		];

		return [
			'name, basic' => [
				[ [ $defaultId, $testId ], ReadingListRepository::SORT_BY_NAME,
					ReadingListRepository::SORT_DIR_ASC ],
				[ $expected['list-foo'], $expected['list-foo2'], $expected['list-foo3'],
					$expected['default-foo'] ],
			],
			'name, desc' => [
				[ [ $defaultId, $testId ], ReadingListRepository::SORT_BY_NAME,
					ReadingListRepository::SORT_DIR_DESC ],
				[ $expected['default-foo'], $expected['list-foo3'], $expected['list-foo2'],
					$expected['list-foo'] ],
			],
			'name, offset + limit' => [
				[ [ $defaultId, $testId ], ReadingListRepository::SORT_BY_NAME,
					ReadingListRepository::SORT_DIR_ASC, 2, [ 'Bar2', 3 ] ],
				[ $expected['list-foo2'], $expected['list-foo3'] ],
			],
			'tiebreaker' => [
				[ [ $defaultId, $testId ], ReadingListRepository::SORT_BY_NAME,
					ReadingListRepository::SORT_DIR_ASC, 2, [ 'Bar2', 4 ] ],
				[ $expected['list-foo3'], $expected['default-foo'] ],
			],
			'updated' => [
				[ [ $defaultId, $testId ], ReadingListRepository::SORT_BY_UPDATED,
					ReadingListRepository::SORT_DIR_ASC ],
				[ $expected['list-foo2'], $expected['list-foo'], $expected['list-foo3'],
					$expected['default-foo'] ],
			],
			'filter by list id' => [
				[ [ $defaultId ], ReadingListRepository::SORT_BY_NAME,
					ReadingListRepository::SORT_DIR_ASC ],
				[ $expected['default-foo'] ],
			],
		];
	}

	public function testGetListEntries_error() {
		$this->addProjects( [ 'dummy' ] );
		$repository = $this->getReadingListRepository( 1 );
		$repository->setupForUser();
		$defaultId = 1;
		[ $deletedListId ] = $this->addLists( 1, [
			[
				'rl_is_default' => 0,
				'rl_name' => 'deleted-123',
				'rl_description' => 'test-deleted',
				'rl_deleted' => 1,
			],
		] );

		$this->assertFailsWith( 'readinglists-db-error-empty-list-ids',
			static function () use ( $repository ) {
				$repository->getListEntries( [], ReadingListRepository::SORT_BY_NAME,
					ReadingListRepository::SORT_DIR_ASC );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-no-such-list',
			static function () use ( $repository ) {
				$repository->getListEntries( [ 123 ], ReadingListRepository::SORT_BY_NAME,
					ReadingListRepository::SORT_DIR_ASC );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-not-own-list',
			function () use ( $defaultId ) {
				$repository = $this->getReadingListRepository( 123 );
				$repository->getListEntries( [ $defaultId ], ReadingListRepository::SORT_BY_NAME,
					ReadingListRepository::SORT_DIR_ASC );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-list-deleted',
			static function () use ( $repository, $deletedListId ) {
				$repository->getListEntries( [ $deletedListId ], ReadingListRepository::SORT_BY_NAME,
					ReadingListRepository::SORT_DIR_ASC );
			}
		);
	}

	/**
	 * Set up test data for getAllListEntries with no duplicate articles across lists.
	 *
	 * Data setup:
	 * - Default list (id=1): foo:Foo (updated 2026-03)
	 * - Custom list (id=2): foo:Bar (updated 2024), foo2:Bar2 (updated 2022),
	 *   foo3:Bar2 (updated 2025), foo4:Bar4 (updated 2026, DELETED)
	 *
	 * @return ReadingListRepository
	 */
	private function setUpNonDuplicateEntries(): ReadingListRepository {
		$this->addProjects( [ 'dummy' ] );
		$repository = $this->getReadingListRepository( 1 );
		$repository->setupForUser();
		$defaultId = 1;

		$this->addListEntries( $defaultId, 1, [
			[
				'rle_user_id' => 1,
				'rlp_project' => 'foo',
				'rle_title' => 'Foo',
				'rle_date_created' => '20260301000000',
				'rle_date_updated' => '20260301000000',
				'rle_deleted' => 0,
			],
		] );

		$this->addLists( 1, [
			[
				'rl_is_default' => 0,
				'rl_name' => 'test',
				'entries' => [
					[
						'rlp_project' => 'foo',
						'rle_title' => 'Bar',
						'rle_date_created' => '20200101000000',
						'rle_date_updated' => '20240101000000',
						'rle_deleted' => 0,
					],
					[
						'rlp_project' => 'foo2',
						'rle_title' => 'Bar2',
						'rle_date_created' => '20200101000000',
						'rle_date_updated' => '20220101000000',
						'rle_deleted' => 0,
					],
					[
						'rlp_project' => 'foo3',
						'rle_title' => 'Bar2',
						'rle_date_created' => '20200101000000',
						'rle_date_updated' => '20250101000000',
						'rle_deleted' => 0,
					],
					[
						'rlp_project' => 'foo4',
						'rle_title' => 'Bar4',
						'rle_date_created' => '20200101000000',
						'rle_date_updated' => '20260201000000',
						'rle_deleted' => 1,
					],
				],
			],
		] );

		return $repository;
	}

	private function getTitles( array $data ): array {
		return array_map( static function ( $row ) {
			return $row['rlp_project'] . ':' . $row['rle_title'];
		}, $data );
	}

	public function testGetAllListEntriesNoDuplicatesSortByNameAsc() {
		$repository = $this->setUpNonDuplicateEntries();

		$res = $repository->getAllListEntries(
			ReadingListRepository::SORT_BY_NAME,
			ReadingListRepository::SORT_DIR_ASC
		);
		$this->assertSame(
			[ 'foo:Bar', 'foo2:Bar2', 'foo3:Bar2', 'foo:Foo' ],
			$this->getTitles( $this->resultWrapperToArray( $res ) ),
			'Deleted entry (foo4:Bar4) should be excluded'
		);
	}

	public function testGetAllListEntriesNoDuplicatesSortByNameDesc() {
		$repository = $this->setUpNonDuplicateEntries();

		$res = $repository->getAllListEntries(
			ReadingListRepository::SORT_BY_NAME,
			ReadingListRepository::SORT_DIR_DESC
		);
		$this->assertSame(
			[ 'foo:Foo', 'foo3:Bar2', 'foo2:Bar2', 'foo:Bar' ],
			$this->getTitles( $this->resultWrapperToArray( $res ) )
		);
	}

	public function testGetAllListEntriesNoDuplicatesSortByNamePagination() {
		$repository = $this->setUpNonDuplicateEntries();

		$res = $repository->getAllListEntries(
			ReadingListRepository::SORT_BY_NAME,
			ReadingListRepository::SORT_DIR_ASC,
			2,
			[ 'Bar2', 1 ]
		);
		$this->assertSame(
			[ 'foo2:Bar2', 'foo3:Bar2' ],
			$this->getTitles( $this->resultWrapperToArray( $res ) ),
			'Pagination from Bar2 with limit 2'
		);
	}

	public function testGetAllListEntriesNoDuplicatesSortByUpdatedAsc() {
		$repository = $this->setUpNonDuplicateEntries();

		$res = $repository->getAllListEntries(
			ReadingListRepository::SORT_BY_UPDATED,
			ReadingListRepository::SORT_DIR_ASC
		);
		$this->assertSame(
			[ 'foo2:Bar2', 'foo:Bar', 'foo3:Bar2', 'foo:Foo' ],
			$this->getTitles( $this->resultWrapperToArray( $res ) )
		);
	}

	public function testGetAllListEntriesNoDuplicatesSortByUpdatedDesc() {
		$repository = $this->setUpNonDuplicateEntries();

		$res = $repository->getAllListEntries(
			ReadingListRepository::SORT_BY_UPDATED,
			ReadingListRepository::SORT_DIR_DESC
		);
		$this->assertSame(
			[ 'foo:Foo', 'foo3:Bar2', 'foo:Bar', 'foo2:Bar2' ],
			$this->getTitles( $this->resultWrapperToArray( $res ) )
		);
	}

	/**
	 * Set up test data for getAllListEntries with duplicate articles across lists.
	 *
	 * Data setup:
	 * - Default list (id=1): foo:Alpha (updated 2024-01), foo:Beta (updated 2024-02),
	 *   foo:Foo (updated 2024-06), foo:Baz (updated 2024-10)
	 * - Custom list (id=2): foo:Alpha (updated 2024-05), foo:Bar (updated 2024-07),
	 *   foo:Foo (updated 2024-09), foo:Gamma (updated 2024-05, DELETED), foo:Delta (updated 2024-05)
	 *
	 * @return ReadingListRepository
	 */
	private function setUpDuplicateEntries(): ReadingListRepository {
		$this->addProjects( [ 'dummy' ] );
		$repository = $this->getReadingListRepository( 1 );
		$repository->setupForUser();
		$defaultId = 1;

		// add default list entries
		$this->addListEntries( $defaultId, 1, [
			[
				'rlp_project' => 'foo',
				'rle_title' => 'Alpha',
				'rle_date_created' => '20240101000000',
				'rle_date_updated' => '20240101000000',
				'rle_deleted' => 0,
			],
			[
				'rlp_project' => 'foo',
				'rle_title' => 'Beta',
				'rle_date_created' => '20240201000000',
				'rle_date_updated' => '20240201000000',
				'rle_deleted' => 0,
			],
			[
				'rlp_project' => 'foo',
				'rle_title' => 'Foo',
				'rle_date_created' => '20240101000000',
				'rle_date_updated' => '20240601000000',
				'rle_deleted' => 0,
			],
			[
				'rlp_project' => 'foo',
				'rle_title' => 'Baz',
				'rle_date_created' => '20240101000000',
				'rle_date_updated' => '20241001000000',
				'rle_deleted' => 0,
			],
		] );

		$this->addLists( 1, [
			[
				'rl_is_default' => 0,
				'rl_name' => 'custom',
				'entries' => [
					[
						'rlp_project' => 'foo',
						'rle_title' => 'Alpha',
						'rle_date_created' => '20240101000000',
						'rle_date_updated' => '20240501000000',
						'rle_deleted' => 0,
					],
					[
						'rlp_project' => 'foo',
						'rle_title' => 'Bar',
						'rle_date_created' => '20240201000000',
						'rle_date_updated' => '20240701000000',
						'rle_deleted' => 0,
					],
					[
						'rlp_project' => 'foo',
						'rle_title' => 'Foo',
						'rle_date_created' => '20240301000000',
						'rle_date_updated' => '20240901000000',
						'rle_deleted' => 0,
					],
					[
						'rlp_project' => 'foo',
						'rle_title' => 'Gamma',
						'rle_date_created' => '20240101000000',
						'rle_date_updated' => '20240501000000',
						'rle_deleted' => 1,
					],
					[
						'rlp_project' => 'foo',
						'rle_title' => 'Delta',
						'rle_date_created' => '20240101000000',
						'rle_date_updated' => '20240501000000',
						'rle_deleted' => 0,
					],
				],
			],
		] );

		return $repository;
	}

	public function testGetAllListEntriesWithDuplicatesSortByName() {
		$repository = $this->setUpDuplicateEntries();

		// Deduplicated: Alpha and Foo each appear only once, Gamma excluded (deleted)
		$res = $repository->getAllListEntries(
			ReadingListRepository::SORT_BY_NAME,
			ReadingListRepository::SORT_DIR_ASC
		);
		$data = $this->resultWrapperToArray( $res );
		$this->assertSame(
			[ 'foo:Alpha', 'foo:Bar', 'foo:Baz', 'foo:Beta', 'foo:Delta', 'foo:Foo' ],
			$this->getTitles( $data ),
			'Deduplicated: Alpha and Foo each appear only once, deleted entry Gamma excluded'
		);
		// For name sort ASC, the entry with the smallest rle_id wins as representative
		$this->assertSame(
			[
				[ 'id' => 1, 'listId' => 1 ],
				[ 'id' => 6, 'listId' => 2 ],
				[ 'id' => 4, 'listId' => 1 ],
				[ 'id' => 2, 'listId' => 1 ],
				[ 'id' => 9, 'listId' => 2 ],
				[ 'id' => 3, 'listId' => 1 ],
			],
			array_map( static function ( $row ) {
				return [
					'id' => (int)$row['rle_id'],
					'listId' => (int)$row['rle_rl_id'],
				];
			}, $data ),
			'Deduplicated rows should return a real representative entry'
		);

		// Not deduplicated: Alpha and Foo each appear twice, Gamma excluded
		$res = $repository->getAllListEntries(
			ReadingListRepository::SORT_BY_NAME,
			ReadingListRepository::SORT_DIR_ASC,
			1000,
			null,
			false
		);
		$this->assertSame(
			[ 'foo:Alpha', 'foo:Alpha', 'foo:Bar', 'foo:Baz', 'foo:Beta',
				'foo:Delta', 'foo:Foo', 'foo:Foo' ],
			$this->getTitles( $this->resultWrapperToArray( $res ) ),
			'Not deduplicated: Alpha and Foo each appear twice, deleted entry Gamma excluded'
		);
	}

	public function testGetAllListEntriesWithDuplicatesSortByUpdated() {
		$repository = $this->setUpDuplicateEntries();

		// Deduplicated: representative is the entry with the newest updated date
		// Alpha: custom entry (2024-05) wins over default (2024-01)
		// Foo: custom entry (2024-09) wins over default (2024-06)
		$res = $repository->getAllListEntries(
			ReadingListRepository::SORT_BY_UPDATED,
			ReadingListRepository::SORT_DIR_DESC
		);
		$data = $this->resultWrapperToArray( $res );
		$this->assertSame(
			[ 'foo:Baz', 'foo:Foo', 'foo:Bar', 'foo:Delta', 'foo:Alpha', 'foo:Beta' ],
			$this->getTitles( $data ),
			'Deduplicated: sorted by updated date desc, deleted entry Gamma excluded'
		);
		// Foo representative is custom entry (id=7), Alpha representative is custom entry (id=5)
		$this->assertSame(
			[
				[ 'id' => 4, 'listId' => 1 ],
				[ 'id' => 7, 'listId' => 2 ],
				[ 'id' => 6, 'listId' => 2 ],
				[ 'id' => 9, 'listId' => 2 ],
				[ 'id' => 5, 'listId' => 2 ],
				[ 'id' => 2, 'listId' => 1 ],
			],
			array_map( static function ( $row ) {
				return [
					'id' => (int)$row['rle_id'],
					'listId' => (int)$row['rle_rl_id'],
				];
			}, $data )
		);

		$res = $repository->getAllListEntries(
			ReadingListRepository::SORT_BY_UPDATED,
			ReadingListRepository::SORT_DIR_DESC,
			1000,
			null,
			false
		);
		$this->assertSame(
			[ 'foo:Baz', 'foo:Foo', 'foo:Bar', 'foo:Foo', 'foo:Delta',
				'foo:Alpha', 'foo:Beta', 'foo:Alpha' ],
			$this->getTitles( $this->resultWrapperToArray( $res ) ),
			'Not deduplicated: Alpha and Foo each appear twice, deleted entry Gamma excluded'
		);
	}

	public function testGetAllListEntriesDuplicatesWithLimit() {
		$repository = $this->setUpDuplicateEntries();

		// Deduplicated with limit 2: Alpha appears once, so Alpha + Bar
		$res = $repository->getAllListEntries(
			ReadingListRepository::SORT_BY_NAME,
			ReadingListRepository::SORT_DIR_ASC,
			2
		);
		$this->assertSame(
			[ 'foo:Alpha', 'foo:Bar' ],
			$this->getTitles( $this->resultWrapperToArray( $res ) ),
			'Deduplicated: limit 2 returns Alpha and Bar'
		);

		// Not deduplicated with limit 2: Alpha from each list fills the limit
		$res = $repository->getAllListEntries(
			ReadingListRepository::SORT_BY_NAME,
			ReadingListRepository::SORT_DIR_ASC,
			2,
			null,
			false
		);
		$this->assertSame(
			[ 'foo:Alpha', 'foo:Alpha' ],
			$this->getTitles( $this->resultWrapperToArray( $res ) ),
			'Not deduplicated: limit 2 returns both Alpha entries'
		);
	}

	public function testGetAllListEntriesDuplicatesWithPagination() {
		$repository = $this->setUpDuplicateEntries();

		// Deduplicated: paginating from Beta with limit 2
		$res = $repository->getAllListEntries(
			ReadingListRepository::SORT_BY_NAME,
			ReadingListRepository::SORT_DIR_ASC,
			2,
			[ 'Beta', 2 ]
		);
		$this->assertSame(
			[ 'foo:Beta', 'foo:Delta' ],
			$this->getTitles( $this->resultWrapperToArray( $res ) ),
			'Deduplicated: paginating from Beta returns Beta and Delta'
		);

		// Not deduplicated: same pagination, same result (no duplicates in this range)
		$res = $repository->getAllListEntries(
			ReadingListRepository::SORT_BY_NAME,
			ReadingListRepository::SORT_DIR_ASC,
			2,
			[ 'Beta', 2 ],
			false
		);
		$this->assertSame(
			[ 'foo:Beta', 'foo:Delta' ],
			$this->getTitles( $this->resultWrapperToArray( $res ) ),
			'Not deduplicated: paginating from Beta returns Beta and Delta'
		);
	}

	public function testDeleteListEntry() {
		$this->addProjects( [ 'dummy' ] );
		$repository = $this->getReadingListRepository( 1 );
		$repository->setupForUser();
		[ $fooProjectId ] = $this->addProjects( [ 'foo' ] );
		[ $listId, $deletedListId, $outOfSyncId ] = $this->addLists( 1, [
			[
				'rl_is_default' => 0,
				'rl_name' => 'test',
				'rl_date_created' => wfTimestampNow(),
				'rl_date_updated' => wfTimestampNow(),
				'rl_deleted' => 0,
			],
			[
				'rl_name' => 'deleted-123',
				'rl_description' => 'deleted',
				'rl_deleted' => '1',
			],
			[
				'rl_is_default' => 0,
				'rl_name' => 'outOfSync',
				'rl_date_created' => wfTimestampNow(),
				'rl_date_updated' => wfTimestampNow(),
				'rl_deleted' => 0,
			],
		] );
		[ $fooId, $foo2Id, $foo3Id ] = $this->addListEntries( $listId, 1, [
			[
				'rlp_project' => 'foo',
				'rle_title' => 'bar',
				'rle_date_created' => wfTimestampNow(),
				'rle_date_updated' => wfTimestampNow(),
				'rle_deleted' => 0,
			],
			[
				'rlp_project' => 'foo2',
				'rle_title' => 'bar2',
				'rle_date_created' => wfTimestampNow(),
				'rle_date_updated' => wfTimestampNow(),
				'rle_deleted' => 0,
			],
			[
				'rlp_project' => 'foo3',
				'rle_title' => 'bar3',
				'rle_date_created' => wfTimestampNow(),
				'rle_date_updated' => wfTimestampNow(),
				'rle_deleted' => 0,
			],
		] );
		[ $parentDeletedId ] = $this->addListEntries( $deletedListId, 1, [
			[
				'rlp_project' => 'foo4',
				'rle_title' => 'bar4',
				'rle_date_created' => wfTimestampNow(),
				'rle_date_updated' => wfTimestampNow(),
				'rle_deleted' => 0,
			],
		] );
		[ $parentOutOfSyncId ] = $this->addListEntries( $outOfSyncId, 1, [
			[
				'rlp_project' => 'foo5',
				'rle_title' => 'bar5',
				'rle_date_created' => wfTimestampNow(),
				'rle_date_updated' => wfTimestampNow(),
				'rle_deleted' => 0,
			]
		] );

		$repository->deleteListEntry( $fooId );
		$this->assertSame(
			2,
			$this->getDb()->newSelectQueryBuilder()
				->select( '1' )
				->from( 'reading_list_entry' )
				->where( [ 'rle_rl_id' => $listId, 'rle_deleted' => 0 ] )
				->fetchRowCount()
		);
		/** @var ReadingListEntryRow $row */
		$row = $this->getDb()->newSelectQueryBuilder()
			->select( '*' )
			->from( 'reading_list_entry' )
			->where( [ 'rle_rl_id' => $listId, 'rle_deleted' => 1 ] )
			->caller( __METHOD__ )->fetchRow();
		$this->assertEquals( $fooProjectId, $row->rle_rlp_id );
		$this->assertTimestampEquals( wfTimestampNow(), $row->rle_date_updated );
		$newListSize = $this->getDb()->newSelectQueryBuilder()
			->select( 'rl_size' )
			->from( 'reading_list' )
			->where( [ 'rl_id' => $listId ] )
			->caller( __METHOD__ )->fetchField();
		$this->assertSame( 2, intval( $newListSize ) );

		// Manually set size to 0, and test that rl_size does not go negative on list entry delete
		$this->getDb()->newUpdateQueryBuilder()
			->update( 'reading_list' )
			->set( [ 'rl_size' => 0 ] )
			->where( [ 'rl_id' => $outOfSyncId ] )
			->caller( __METHOD__ )
			->execute();
		$repository->deleteListEntry( $parentOutOfSyncId );
		$outOfSyncSize = $this->getDb()->newSelectQueryBuilder()
			->select( 'rl_size' )
			->from( 'reading_list' )
			->where( [ 'rl_id' => $outOfSyncId ] )
			->caller( __METHOD__ )->fetchField();
		$this->assertSame( 0, intval( $outOfSyncSize ) );

		$this->assertFailsWith( 'readinglists-db-error-no-such-list-entry',
			static function () use ( $repository ) {
				$repository->deleteListEntry( 123 );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-not-own-list-entry',
			function () use ( $foo2Id ) {
				$repository = $this->getReadingListRepository( 123 );
				$repository->deleteListEntry( $foo2Id );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-list-entry-deleted',
			static function () use ( $repository, $fooId ) {
				$repository->deleteListEntry( $fooId );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-list-deleted',
			static function () use ( $repository, $parentDeletedId ) {
				$repository->deleteListEntry( $parentDeletedId );
			}
		);
	}

	/**
	 * @dataProvider provideGetListsByDateUpdated
	 */
	public function testGetListsByDateUpdated( array $args, array $expected ) {
		$this->addProjects( [ 'dummy' ] );
		$repository = $this->getReadingListRepository( 1 );
		$this->addLists( 1, [
			[
				'rl_name' => 'foo-1',
				'rl_description' => 'list1',
				'rl_date_updated' => '20150101000000',
			],
			[
				'rl_name' => 'foo-2',
				'rl_description' => 'list2',
				'rl_date_updated' => '20120101000000',
			],
			[
				'rl_name' => 'foo2',
				'rl_description' => 'list3',
				'rl_date_updated' => '20150101000000',
			],
			[
				'rl_name' => 'foo3',
				'rl_description' => 'list4',
				'rl_date_updated' => '20170101000000',
			],
			[
				'rl_name' => 'foo',
				'rl_description' => 'too-old',
				'rl_date_updated' => '20080101000000',
			],
			[
				'rl_name' => 'deleted-123',
				'rl_description' => 'deleted',
				'rl_deleted' => 1,
				'rl_date_updated' => '20150102000000',
			],
		] );

		$res = call_user_func_array( [ $repository, 'getListsByDateUpdated' ], $args );
		$data = $this->resultWrapperToArray( $res, 'rl_description' );
		$this->assertArrayEquals( $expected, $data );
	}

	public static function provideGetListsByDateUpdated() {
		return [
			'basic' => [
				[ '20100101000000' ],
				[ 'list2', 'list1', 'list3', 'deleted', 'list4' ],
			],
			'desc' => [
				[ '20100101000000', ReadingListRepository::SORT_BY_UPDATED,
					ReadingListRepository::SORT_DIR_DESC ],
				[ 'list4', 'deleted', 'list3', 'list1', 'list2' ],
			],
			'limit + offset' => [
				[ '20100101000000', ReadingListRepository::SORT_BY_UPDATED,
					ReadingListRepository::SORT_DIR_ASC, 2, [ '20150101000000', 3 ] ],
				[ 'list3', 'deleted' ],
			],
			'name' => [
				[ '20100101000000', ReadingListRepository::SORT_BY_NAME,
					ReadingListRepository::SORT_DIR_ASC ],
				[ 'list1', 'list2', 'deleted', 'list3', 'list4' ],
			]
		];
	}

	/**
	 * @dataProvider provideGetListEntriesByDateUpdated
	 */
	public function testGetListEntriesByDateUpdated( array $args, array $expected ) {
		$this->addProjects( [ 'dummy' ] );
		$repository = $this->getReadingListRepository( 1 );
		$this->addLists( 1, [
			[
				'rl_name' => 'one',
				'entries' => [
					[
						'rlp_project' => 'foo',
						'rle_title' => 'foo',
						'rle_date_updated' => '20150101000000',
					],
					[
						'rlp_project' => 'foo-deleted',
						'rle_title' => 'foo',
						'rle_deleted' => 1,
						'rle_date_updated' => '20150101000000',
					],
					[
						'rlp_project' => 'foo2',
						'rle_title' => 'foo',
						'rle_date_updated' => '20170101000000',
					],
					[
						'rlp_project' => 'too-old',
						'rle_title' => 'foo',
						'rle_date_updated' => '20080101000000',
					],
				],
			],
			[
				'rl_name' => 'two',
				'entries' => [
					[
						'rlp_project' => 'bar',
						'rle_title' => 'bar',
						'rle_date_updated' => '20150101000000',
					],
				],
			],
			[
				'rl_name' => 'deleted-123',
				'rl_description' => 'deleted',
				'rl_deleted' => 1,
				'entries' => [
					[
						'rlp_project' => 'parent deleted',
						'rle_title' => 'bar',
						'rle_date_updated' => '20150101000000',
					],
				],
			],
		] );
		$res = call_user_func_array( [ $repository, 'getListEntriesByDateUpdated' ], $args );
		$data = $this->resultWrapperToArray( $res, 'rlp_project' );
		$this->assertArrayEquals( $expected, $data );
	}

	public static function provideGetListEntriesByDateUpdated() {
		return [
			'basic' => [
				[ '20100101000000' ],
				[ 'foo', 'foo-deleted', 'bar', 'foo2' ],
			],
			'desc' => [
				[ '20100101000000', ReadingListRepository::SORT_DIR_DESC ],
				[ 'foo2', 'bar', 'foo-deleted', 'foo' ],
			],
			'limit + offset' => [
				[ '20100101000000', ReadingListRepository::SORT_DIR_ASC, 2, [ '20150101000000', 2 ] ],
				[ 'foo-deleted', 'bar' ],
			],
			'limit + offset 2' => [
				[ '20100101000000', ReadingListRepository::SORT_DIR_ASC, 2, [ '20150101000000', 5 ] ],
				[ 'bar', 'foo2' ],
			],
			'limit + offset 3' => [
				[ '20100101000000', ReadingListRepository::SORT_DIR_ASC, 2, [ '20170101000000', 3 ] ],
				[ 'foo2' ],
			],
		];
	}

	public function testPurgeOldDeleted() {
		$this->addProjects( [ 'dummy' ] );
		$repository = $this->getReadingListRepository();

		// User ID to associate the lists and entries with.
		$userID = 1;

		// Soft-deleted lists and entries will be purged if
		// their last date updated is before $cutoff.
		$cutoff = '20010101000000';
		$after = '20020101000000';
		$before = '20000101000000';

		// Add test lists and entries for all 4*4=16 configurations
		//
		// CODING
		// OO: {rl,rle}_deleted = 0, {rl,rle}_date_updated > $cutoff
		// OX: {rl,rle}_deleted = 1, {rl,rle}_date_updated > $cutoff
		// XO: {rl,rle}_deleted = 0, {rl,rle}_date_updated <= $cutoff
		// XX: {rl,rle}_deleted = 1, {rl,rle}_date_updated <= $cutoff
		//
		// It's easy to see the only items marked 'XX' get purged.
		$this->addLists( $userID, [
			[
				'rl_name' => 'OO',
				'rl_description' => 'OO',
				'rl_date_updated' => $after,
				'rl_deleted' => 0,
				'entries' => [
					[
						'rlp_project' => '-',
						'rle_title' => 'OO-OO',
						'rle_date_updated' => $after,
						'rle_deleted' => 0,
					],
					[
						'rlp_project' => '-',
						'rle_title' => 'OO-OX',
						'rle_date_updated' => $after,
						'rle_deleted' => 1,
					],
					[
						'rlp_project' => '-',
						'rle_title' => 'OO-XO',
						'rle_date_updated' => $before,
						'rle_deleted' => 0,
					],
					[
						'rlp_project' => '-',
						'rle_title' => 'OO-XX',
						'rle_date_updated' => $before,
						'rle_deleted' => 1,
					],
				],
			],
			[
				'rl_name' => 'OX',
				'rl_description' => 'OX',
				'rl_date_updated' => $after,
				'rl_deleted' => 1,
				'entries' => [
					[
						'rlp_project' => '-',
						'rle_title' => 'OX-OO',
						'rle_date_updated' => $after,
						'rle_deleted' => 0,
					],
					[
						'rlp_project' => '-',
						'rle_title' => 'OX-OX',
						'rle_date_updated' => $after,
						'rle_deleted' => 1,
					],
					[
						'rlp_project' => '-',
						'rle_title' => 'OX-XO',
						'rle_date_updated' => $before,
						'rle_deleted' => 0,
					],
					[
						'rlp_project' => '-',
						'rle_title' => 'OX-XX',
						'rle_date_updated' => $before,
						'rle_deleted' => 1,
					],
				],
			],
			[
				'rl_name' => 'XO',
				'rl_description' => 'XO',
				'rl_date_updated' => $before,
				'rl_deleted' => 0,
				'entries' => [
					[
						'rlp_project' => '-',
						'rle_title' => 'XO-OO',
						'rle_date_updated' => $after,
						'rle_deleted' => 0,
					],
					[
						'rlp_project' => '-',
						'rle_title' => 'XO-OX',
						'rle_date_updated' => $after,
						'rle_deleted' => 1,
					],
					[
						'rlp_project' => '-',
						'rle_title' => 'XO-XO',
						'rle_date_updated' => $before,
						'rle_deleted' => 0,
					],
					[
						'rlp_project' => '-',
						'rle_title' => 'XO-XX',
						'rle_date_updated' => $before,
						'rle_deleted' => 1,
					],
				],
			],
			[
				'rl_name' => 'XX',
				'rl_description' => 'XX',
				'rl_date_updated' => $before,
				'rl_deleted' => 1,
				'entries' => [
					[
						'rlp_project' => '-',
						'rle_title' => 'XX-OO',
						'rle_date_updated' => $after,
						'rle_deleted' => 0,
					],
					[
						'rlp_project' => '-',
						'rle_title' => 'XX-OX',
						'rle_date_updated' => $after,
						'rle_deleted' => 1,
					],
					[
						'rlp_project' => '-',
						'rle_title' => 'XX-XO',
						'rle_date_updated' => $before,
						'rle_deleted' => 0,
					],
					[
						'rlp_project' => '-',
						'rle_title' => 'XX-XX',
						'rle_date_updated' => $before,
						'rle_deleted' => 1,
					],
				],
			],
		] );

		// Run the function
		$repository->purgeOldDeleted( $cutoff );

		$lists = $this->getDb()->newSelectQueryBuilder()
			->select( 'rl_name' )
			->from( 'reading_list' )
			->caller( __METHOD__ )->fetchFieldValues();
		$this->assertArrayEquals( $lists, [ 'OO', 'OX', 'XO' ] );

		$entries = $this->getDb()->newSelectQueryBuilder()
			->select( 'rle_title' )
			->from( 'reading_list_entry' )
			->caller( __METHOD__ )->fetchFieldValues();
		$this->assertArrayEquals( $entries, [ 'OO-OO', 'OO-OX', 'OO-XO',
			'OX-OO', 'OX-OX', 'OX-XO',
			'XO-OO', 'XO-OX', 'XO-XO',
		] );
	}

	/**
	 * @dataProvider provideGetListsByPage
	 */
	public function testGetListsByPage( array $args, array $expected ) {
		$this->addProjects( [ 'dummy' ] );
		$repository = $this->getReadingListRepository( 1 );
		$this->addLists( 1, [
			[
				// no match
				'rl_name' => 'first',
				'entries' => [
					[
						'rlp_project' => '-',
						'rle_title' => 'o',
					],
				],
			],
			[
				// entry deleted, no match
				'rl_name' => 'second',
				'entries' => [
					[
						'rlp_project' => '-',
						'rle_title' => 'x',
						'rle_deleted' => 1,
					],
				],
			],
			[
				// list deleted, no match
				'rl_name' => 'deleted-123',
				'rl_description' => 'third',
				'rl_deleted' => 1,
				'entries' => [
					[
						'rlp_project' => '-',
						'rle_title' => 'x',
					],
				],
			],
			[
				// match
				'rl_name' => 'fourth',
				'entries' => [
					[
						'rlp_project' => '-',
						'rle_title' => 'x',
					],
					[
						'rlp_project' => '-',
						'rle_title' => 'o',
					],
				],
			],
			[
				// another match
				'rl_name' => 'fifth',
				'entries' => [
					[
						'rlp_project' => '-',
						'rle_title' => 'x',
					],
					[
						'rlp_project' => '-',
						'rle_title' => 'o',
					],
				],
			],
		] );

		$res = call_user_func_array( [ $repository, 'getListsByPage' ], $args );
		$data = $this->resultWrapperToArray( $res, 'rl_name' );
		$this->assertArrayEquals( $expected, $data );
	}

	public static function provideGetListsByPage() {
		return [
			'basic' => [
				[ '-', 'x' ],
				[ 'fourth', 'fifth' ],
			],
			'limit' => [
				[ '-', 'x', 1 ],
				[ 'fourth' ],
			],
			'limit + offset' => [
				[ '-', 'x', 1, 4 ],
				[ 'fourth' ],
			],
			'limit + offset 2' => [
				[ '-', 'x', 1, 5 ],
				[ 'fifth' ],
			],
		];
	}

	// -------------------------------------------

	/**
	 * @param string $message
	 * @param callable $callable
	 */
	private function assertFailsWith( $message, $callable ) {
		try {
			$callable();
		} catch ( ReadingListRepositoryException $e ) {
			$this->assertEquals( $message, $e->getMessageObject()->getKey() );
			return;
		}
		$this->assertThat( null, new Exception( ReadingListRepositoryException::class ) );
	}

	/**
	 * @param string $expectedTimestamp
	 * @param string $actualTimestamp
	 * @param string $msg
	 */
	private function assertTimestampEquals( $expectedTimestamp, $actualTimestamp, $msg = '' ) {
		if ( strlen( $msg ) ) {
			$msg .= "\n";
		}
		$delta = abs( wfTimestamp( TS_UNIX, $expectedTimestamp )
			- wfTimestamp( TS_UNIX, $actualTimestamp ) );
		$this->assertLessThanOrEqual( 800, $delta,
			"{$msg}Difference between expected timestamp ($expectedTimestamp) "
			. "and actual timetamp ($actualTimestamp) is too large" );
	}

	private function addDataForAnotherUser() {
		$this->addLists( 10, [
			[
				'rl_is_default' => 1,
				'rl_name' => 'default',
				'rl_date_created' => wfTimestampNow(),
				'rl_date_updated' => wfTimestampNow(),
				'rl_deleted' => 0,
				'entries' => [
					[
						'rlp_project' => 'foo',
						'rle_title' => 'bar',
						'rle_date_created' => wfTimestampNow(),
						'rle_date_updated' => wfTimestampNow(),
						'rle_deleted' => 0,
					],
					[
						'rlp_project' => 'foo2',
						'rle_title' => 'bar2',
						'rle_date_created' => wfTimestampNow(),
						'rle_date_updated' => wfTimestampNow(),
						'rle_deleted' => 0,
					],
				],
			],
		] );
	}

	/**
	 * Converts a result wrapper into a two-dimensional array, potentially filtering
	 * what fields to keep. Alternatively (when $filter is a string) return them in a flat array,
	 * like selectFieldValues().
	 * @param IResultWrapper $res
	 * @param array|string|null $filter A column name or list of column names.
	 * @return array
	 */
	private function resultWrapperToArray( IResultWrapper $res, $filter = null ) {
		return array_values( array_map( static function ( $row ) use ( $filter ) {
			$row = (array)$row;
			if ( is_array( $filter ) ) {
				$row = array_intersect_key( $row, array_fill_keys( $filter, true ) );
			} elseif ( is_string( $filter ) ) {
				$row = $row[$filter];
			}
			return $row;
		}, iterator_to_array( $res ) ) );
	}

}
