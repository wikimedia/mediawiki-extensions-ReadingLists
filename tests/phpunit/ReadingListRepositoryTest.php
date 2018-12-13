<?php

namespace MediaWiki\Extensions\ReadingLists\Tests;

use MediaWiki\Extensions\ReadingLists\Doc\ReadingListEntryRow;
use MediaWiki\Extensions\ReadingLists\Doc\ReadingListRow;
use MediaWiki\Extensions\ReadingLists\ReadingListRepository;
use MediaWiki\Extensions\ReadingLists\ReadingListRepositoryException;
use MediaWiki\Extensions\ReadingLists\HookHandler;
use MediaWiki\MediaWikiServices;
use MediaWikiTestCase;
use PHPUnit_Framework_Constraint_Exception;
use SebastianBergmann\Exporter\Exporter;
use Wikimedia\Rdbms\IResultWrapper;
use Wikimedia\Rdbms\LBFactory;

/**
 * @group Database
 * @covers \MediaWiki\Extensions\ReadingLists\ReadingListRepository
 * @covers \MediaWiki\Extensions\ReadingLists\ReadingListRepositoryException
 */
class ReadingListRepositoryTest extends MediaWikiTestCase {

	use ReadingListsTestHelperTrait;

	/** @var LBFactory $lbFactory */
	private $lbFactory;

	public function setUp() {
		parent::setUp();
		$this->tablesUsed = array_merge( $this->tablesUsed, HookHandler::$testTables );
		$this->lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
	}

	/**
	 * @dataProvider provideAssertUser
	 * @param string $method ReadingListRepository method name
	 * @param mixed $param... Method parameters
	 */
	public function testAssertUser( $method ) {
		$repository = new ReadingListRepository( null, $this->db, $this->db, $this->lbFactory );
		$call = func_get_args();
		$this->assertFailsWith( 'readinglists-db-error-user-required',
			function () use ( $repository, $call ) {
				$method = array_shift( $call );
				$params = $call;
				call_user_func_array( [ $repository, $method ], $params );
			}
		);
	}

	/**
	 * @return array
	 */
	public function provideAssertUser() {
		return [
			[ 'setupForUser' ],
			[ 'teardownForUser' ],
			[ 'isSetupForUser' ],
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
	 * @param string $method ReadingListRepository method name
	 * @param mixed $param... Method parameters
	 */
	public function testUninitializedErrors( $method ) {
		$this->addDataForAnotherUser();
		$repository = new ReadingListRepository( 1, $this->db, $this->db, $this->lbFactory );
		$call = func_get_args();
		$this->assertFailsWith( 'readinglists-db-error-not-set-up',
			function () use ( $repository, $call ) {
				$method = array_shift( $call );
				$params = $call;
				call_user_func_array( [ $repository, $method ], $params );
			}
		);
	}

	/**
	 * @return array
	 */
	public function provideUninitializedErrors() {
		return [
			[ 'teardownForUser' ],
			[ 'addList', 'foo' ],
			[ 'getAllLists', ReadingListRepository::SORT_BY_NAME,
				ReadingListRepository::SORT_DIR_ASC ],
			[ 'getListsByDateUpdated', wfTimestampNow() ],
			[ 'getListsByPage', 'foo', 'bar' ],
		];
	}

	public function testSetupAndTeardown() {
		$this->addDataForAnotherUser();
		$repository = new ReadingListRepository( 1, $this->db, $this->db, $this->lbFactory );

		// no rows initially; isSetupForUser() is false
		$this->assertFalse( $repository->isSetupForUser() );
		$res = $this->db->select( 'reading_list', '*', [ 'rl_user_id' => 1 ] );
		$this->assertEquals( 0, $res->numRows() );

		// one row after setup; isSetupForUser() is true
		$list = $repository->setupForUser();
		$this->assertFailsWith( 'readinglists-db-error-already-set-up',
			function () use ( $repository ) {
				$repository->setupForUser();
			}
		);
		$this->assertTrue( $repository->isSetupForUser() );
		$res = $this->db->select( 'reading_list', '*', [ 'rl_user_id' => 1 ] );
		$this->assertEquals( 1, $res->numRows() );
		/** @var ReadingListRow $row */
		$row = $res->fetchObject();
		$this->assertEquals( 1, $row->rl_is_default );
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

		$this->assertFalse( $repository->isSetupForUser(),
			"teardownForUser failed to reset isSetupForUser value"
		);

		$res = $this->db->select( 'reading_list', '*', [ 'rl_user_id' => 1, 'rl_deleted' => 0 ] );
		$this->assertEquals( 0, $res->numRows(),
			"teardownForUser failed to soft-delete all lists"
		);

		$list = (array)$repository->setupForUser();
		$this->assertNotEquals( $list['rl_id'], $oldDefaultListId,
			"new default list has same id as old default list"
		);
	}

	public function testAddList() {
		$repository = new ReadingListRepository( 1, $this->db, $this->db, $this->lbFactory );
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
			'merged' => false,
		], $data, false, true );
		/** @var ReadingListRow $row */
		$row = $this->db->selectRow( 'reading_list', '*', [ 'rl_id' => $list->rl_id ] );
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
			'merged' => false,
		], $data, false, true );

		$mergedList = $repository->addList( 'bar', 'more bar' );
		$this->assertEquals( $list->rl_id, $mergedList->rl_id );
		$this->assertEquals( 'more bar', $mergedList->rl_description );
		$this->assertEquals( true, $mergedList->merged );

		$mergedList = $repository->addList( 'bar', 'more bar' );
		$this->assertEquals( $list->rl_id, $mergedList->rl_id );
		$this->assertEquals( true, $mergedList->merged );

		$this->assertFailsWith( 'readinglists-db-error-too-long', function () use ( $repository ) {
			$repository->addList( 'boom',  str_pad( '', 1000, 'x' ) );
		} );
	}

	public function testAddList_count() {
		$repository = new ReadingListRepository( 1, $this->db, $this->db, $this->lbFactory );
		$repository->setLimits( 2, null );
		$repository->setupForUser();

		$list = $repository->addList( 'foo' );
		$repository->deleteList( $list->rl_id );
		$list2 = $repository->addList( 'bar' );
		$this->assertFailsWith( 'readinglists-db-error-list-limit', function () use ( $repository ) {
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
	 * @param array $args
	 * @param array $expected
	 */
	public function testGetAllLists( array $args, array $expected ) {
		$this->addDataForAnotherUser();
		$repository = new ReadingListRepository( 1, $this->db, $this->db, $this->lbFactory );
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
			$this->assertEquals( count( $expected ), count( $data ), 'result length is different!' );
			array_map( $compareResultItems, $expected, $data );
		};

		$res = call_user_func_array( [ $repository, 'getAllLists' ], $args );
		$compare( $expected, $res );
	}

	public function provideGetAllLists() {
		$lists = [
			'default' => [
				'rl_name' => 'default',
				'rl_description' => '',
				'rl_is_default' => '1',
				'rl_date_created' => wfTimestampNow(),
				'rl_date_updated' => wfTimestampNow(),
				'rl_deleted' => '0',
			],
			'foo' => [
				'rl_name' => 'foo1',
				'rl_description' => '',
				'rl_is_default' => '0',
				'rl_date_created' => '20100101000000',
				'rl_date_updated' => '20120101000000',
				'rl_deleted' => '0',
			],
			'foo_2' => [
				'rl_name' => 'foo2',
				'rl_description' => 'this is the second foo',
				'rl_is_default' => '0',
				'rl_date_created' => '20170101000000',
				'rl_date_updated' => '20170101000000',
				'rl_deleted' => '0',
			],
			'bar' => [
				'rl_name' => 'bar',
				'rl_description' => '',
				'rl_is_default' => '0',
				'rl_date_created' => '20010101000000',
				'rl_date_updated' => '20120101000000',
				'rl_deleted' => '0',
			],
		];
		// 1 list from addDataForAnotherUser, 1 from setupForUser, plus 1-based index in addLists()
		$fooId = 3;
		$foo2Id = 4;
		$barId = 5;

		return [
			'name, basic' => [
				[ ReadingListRepository::SORT_BY_NAME, ReadingListRepository::SORT_DIR_ASC ],
				[ $lists['bar'], $lists['default'], $lists['foo'], $lists['foo_2'] ],
			],
			'name, reverse' => [
				[ ReadingListRepository::SORT_BY_NAME, ReadingListRepository::SORT_DIR_DESC ],
				[ $lists['foo_2'], $lists['foo'], $lists['default'], $lists['bar'] ],
			],
			'name, limit' => [
				[ ReadingListRepository::SORT_BY_NAME, ReadingListRepository::SORT_DIR_ASC, 1 ],
				[ $lists['bar'] ],
			],
			'name, limit + offset' => [
				[ ReadingListRepository::SORT_BY_NAME, ReadingListRepository::SORT_DIR_ASC,
					1, [ 'default', 1 ] ],
				[ $lists['default'] ],
			],
			'updated, basic' => [
				[ ReadingListRepository::SORT_BY_UPDATED, ReadingListRepository::SORT_DIR_ASC ],
				[ $lists['foo'], $lists['bar'], $lists['foo_2'], $lists['default'] ],
			],
			'updated, limit' => [
				[ ReadingListRepository::SORT_BY_UPDATED, ReadingListRepository::SORT_DIR_ASC, 1 ],
				[ $lists['foo'] ],
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
		$repository = new ReadingListRepository( 1, $this->db, $this->db, $this->lbFactory );
		$repository->setupForUser();
		list( $listId, $listId2, $deletedListId ) = $this->addLists( 1, [
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
		$row = $this->db->selectRow( 'reading_list', '*', [ 'rl_id' => $listId ] );
		$this->assertEquals( $list->rl_name, $row->rl_name );
		$this->assertEquals( $list->rl_description, $row->rl_description );
		$this->assertTimestampEquals( $list->rl_date_created, $row->rl_date_created );
		$this->assertTimestampEquals( $list->rl_date_updated, $row->rl_date_updated );

		$list = $repository->updateList( $listId, 'bar', 'yyy' );
		$this->assertEquals( 'bar', $list->rl_name );
		$this->assertEquals( 'yyy', $list->rl_description );

		$this->assertFailsWith( 'readinglists-db-error-no-such-list',
			function () use ( $repository ) {
				$repository->updateList( 123, 'foo' );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-not-own-list',
			function () use ( $listId ) {
				$repository = new ReadingListRepository( 123, $this->db, $this->db, $this->lbFactory );
				$repository->updateList( $listId, 'foo' );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-list-deleted',
			function () use ( $repository, $deletedListId ) {
				$repository->updateList( $deletedListId, 'bar' );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-duplicate-list',
			function () use ( $repository, $listId ) {
				$repository->updateList( $listId, 'foo2' );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-cannot-update-default-list',
			function () use ( $repository ) {
				$defaultId = $this->db->selectField( 'reading_list', 'rl_id',
					[ 'rl_user_id' => 1, 'rl_is_default' => 1 ] );
				$this->assertNotSame( false, $defaultId );
				$repository->updateList( $defaultId, 'not default' );
			}
		);
	}

	public function testDeleteList() {
		$repository = new ReadingListRepository( 1, $this->db, $this->db, $this->lbFactory );
		$repository->setupForUser();
		list( $listId, $deletedListId ) = $this->addLists( 1, [
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
		$this->assertEquals( 0, $this->db->selectRowCount( 'reading_list',
			'1', [ 'rl_user_id' => 1, 'rl_name' => 'foo', 'rl_deleted' => 0 ] ) );
		$this->assertTimestampEquals( wfTimestampNow(), $this->db->selectField( 'reading_list',
			'rl_date_updated', [ 'rl_id' => $listId ] ) );

		$this->assertFailsWith( 'readinglists-db-error-no-such-list',
			function () use ( $repository ) {
				$repository->deleteList( 123 );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-not-own-list',
			function () use ( $listId ) {
				$repository = new ReadingListRepository( 123, $this->db, $this->db, $this->lbFactory );
				$repository->deleteList( $listId );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-list-deleted',
			function () use ( $repository, $deletedListId ) {
				$repository->deleteList( $deletedListId );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-cannot-delete-default-list',
			function () use ( $repository ) {
				$defaultId = $this->db->selectField( 'reading_list', 'rl_id',
					[ 'rl_user_id' => 1, 'rl_is_default' => 1 ] );
				$this->assertNotSame( false, $defaultId );
				$repository->deleteList( $defaultId );
			}
		);
	}

	public function testAddListEntry() {
		$repository = new ReadingListRepository( 1, $this->db, $this->db, $this->lbFactory );
		$repository->setupForUser();
		list( $projectId ) = $this->addProjects( [ 'https://en.wikipedia.org',
			'https://de.wikipedia.org' ] );
		list( $listId, $deletedListId ) = $this->addLists( 1, [
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
		$this->assertEquals( 0, $entry->rle_deleted );
		$this->assertEquals( false, $entry->merged );
		/** @var ReadingListEntryRow $row */
		$row = $this->db->selectRow( 'reading_list_entry', '*', [ 'rle_id' => $entry->rle_id ] );
		$this->assertEquals( 1, $row->rle_user_id );
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
		$this->assertEquals( 0, $entry2->rle_deleted );
		$this->assertEquals( false, $entry->merged );

		$repository->addListEntry( $listId, 'https://en.wikipedia.org', 'Bar' );
		$repository->addListEntry( $listId, 'https://de.wikipedia.org', 'Foo' );

		// test that adding a duplicate is a no-op
		$dupeEntry = $repository->addListEntry( $listId, 'https://en.wikipedia.org', 'Foo' );
		$this->assertEquals( $dupeEntry->rle_id, $entry2->rle_id );
		$this->assertEquals( true, $dupeEntry->merged );

		$this->assertFailsWith( 'readinglists-db-error-no-such-list',
			function () use ( $repository ) {
				$repository->addListEntry( 123, 'https://en.wikipedia.org', 'A' );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-not-own-list',
			function () use ( $listId ) {
				$repository = new ReadingListRepository( 123, $this->db, $this->db, $this->lbFactory );
				$repository->addListEntry( $listId, 'https://en.wikipedia.org', 'B' );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-list-deleted',
			function () use ( $repository, $deletedListId ) {
				$repository->addListEntry( $deletedListId, 'https://en.wikipedia.org', 'C' );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-no-such-project',
			function () use ( $repository, $listId ) {
				$repository->addListEntry( $listId, 'https://nosuch.project.org', 'Foo' );
			}
		);
	}

	public function testAddListEntry_count() {
		$repository = new ReadingListRepository( 1, $this->db, $this->db, $this->lbFactory );
		$repository->setLimits( null, 1 );
		$repository->setupForUser();

		$this->addProjects( [ 'https://en.wikipedia.org' ] );
		list( $listId ) = $this->addLists( 1, [
			[
				'rl_name' => 'foo',
				'rl_deleted' => '0',
			],
		] );
		// assert that limits work
		$entry = $repository->addListEntry( $listId, 'https://en.wikipedia.org', 'Foo' );
		$this->assertFailsWith( 'readinglists-db-error-entry-limit',
			function () use ( $repository, $listId ) {
				$repository->addListEntry( $listId, 'https://en.wikipedia.org', 'Baz' );
			}
		);
		// assert that deleting frees up space on the list
		$repository->deleteListEntry( $entry->rle_id );
		$entry2 = $repository->addListEntry( $listId, 'https://en.wikipedia.org', 'Bar' );
		$this->assertFailsWith( 'readinglists-db-error-entry-limit',
			function () use ( $repository, $listId ) {
				$repository->addListEntry( $listId, 'https://en.wikipedia.org', 'Baz' );
			}
		);
		// assert that recreating a deleted entry is counted normally
		$repository->deleteListEntry( $entry2->rle_id );
		$repository->addListEntry( $listId, 'https://en.wikipedia.org', 'Bar' );
		$this->assertFailsWith( 'readinglists-db-error-entry-limit',
			function () use ( $repository, $listId ) {
				$repository->addListEntry( $listId, 'https://en.wikipedia.org', 'Baz' );
			}
		);

		// assert that duplicates do not take up space; we must do a deletion first since inserting
		// into a full list would be rejected even if it's a duplicate
		$repository->setLimits( null, 2 );
		list( $listId2 ) = $this->addLists( 1, [
			[
				'rl_name' => 'bar',
				'rl_deleted' => '0',
			],
		] );
		$repository->addListEntry( $listId2, 'https://en.wikipedia.org', 'Foo' );
		$repository->addListEntry( $listId2, 'https://en.wikipedia.org', 'Foo' );
		$repository->addListEntry( $listId2, 'https://en.wikipedia.org', 'Bar' );
		$this->assertFailsWith( 'readinglists-db-error-entry-limit',
			function () use ( $repository, $listId2 ) {
				$repository->addListEntry( $listId2, 'https://en.wikipedia.org', 'Baz' );
			}
		);
	}

	/**
	 * @dataProvider provideGetListEntries
	 * @param array $args
	 * @param array $expected
	 */
	public function testGetListEntries( array $args, array $expected ) {
		$repository = new ReadingListRepository( 1, $this->db, $this->db, $this->lbFactory );
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
			$this->assertEquals( count( $expected ), count( $data ), 'result length is different!' );
			array_map( $compareResultItems, $expected, $data, range( 1, count( $expected ) ) );
		};

		$res = call_user_func_array( [ $repository, 'getListEntries' ], $args );
		$compare( $expected, $res );
	}

	public function provideGetListEntries() {
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
		$repository = new ReadingListRepository( 1, $this->db, $this->db, $this->lbFactory );
		$repository->setupForUser();
		$defaultId = 1;
		list( $deletedListId ) = $this->addLists( 1, [
			[
				'rl_is_default' => 0,
				'rl_name' => 'deleted-123',
				'rl_description' => 'test-deleted',
				'rl_deleted' => 1,
			],
		] );

		$this->assertFailsWith( 'readinglists-db-error-empty-list-ids',
			function () use ( $repository ) {
				$repository->getListEntries( [], ReadingListRepository::SORT_BY_NAME,
					ReadingListRepository::SORT_DIR_ASC );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-no-such-list',
			function () use ( $repository ) {
				$repository->getListEntries( [ 123 ], ReadingListRepository::SORT_BY_NAME,
					ReadingListRepository::SORT_DIR_ASC );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-not-own-list',
			function () use ( $defaultId ) {
				$repository = new ReadingListRepository( 123, $this->db, $this->db, $this->lbFactory );
				$repository->getListEntries( [ $defaultId ], ReadingListRepository::SORT_BY_NAME,
					ReadingListRepository::SORT_DIR_ASC );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-list-deleted',
			function () use ( $repository, $deletedListId ) {
				$repository->getListEntries( [ $deletedListId ], ReadingListRepository::SORT_BY_NAME,
					ReadingListRepository::SORT_DIR_ASC );
			}
		);
	}

	public function testDeleteListEntry() {
		$repository = new ReadingListRepository( 1, $this->db, $this->db, $this->lbFactory );
		$repository->setupForUser();
		list( $fooProjectId ) = $this->addProjects( [ 'foo' ] );
		list( $listId, $deletedListId ) = $this->addLists( 1, [
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
		] );
		list( $fooId, $foo2Id, $deletedId ) = $this->addListEntries( $listId, 1, [
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
				'rle_deleted' => 1,
			],
		] );
		list( $parentDeletedId ) = $this->addListEntries( $deletedListId, 1, [
			[
				'rlp_project' => 'foo4',
				'rle_title' => 'bar4',
				'rle_date_created' => wfTimestampNow(),
				'rle_date_updated' => wfTimestampNow(),
				'rle_deleted' => 0,
			],
		] );

		$repository->deleteListEntry( $fooId );
		$this->assertEquals( 1, $this->db->selectRowCount( 'reading_list_entry',
			'1', [ 'rle_rl_id' => $listId, 'rle_deleted' => 0 ] ) );
		/** @var ReadingListEntryRow $row */
		$row = $this->db->selectRow( 'reading_list_entry', '*',
			[ 'rle_rl_id' => $listId, 'rle_deleted' => 1 ] );
		$this->assertEquals( $fooProjectId, $row->rle_rlp_id );
		$this->assertTimestampEquals( wfTimestampNow(), $row->rle_date_updated );

		$this->assertFailsWith( 'readinglists-db-error-no-such-list-entry',
			function () use ( $repository ) {
				$repository->deleteListEntry( 123 );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-not-own-list-entry',
			function () use ( $foo2Id ) {
				$repository = new ReadingListRepository( 123, $this->db, $this->db, $this->lbFactory );
				$repository->deleteListEntry( $foo2Id );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-list-entry-deleted',
			function () use ( $repository, $deletedId ) {
				$repository->deleteListEntry( $deletedId );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-list-deleted',
			function () use ( $repository, $parentDeletedId ) {
				$repository->deleteListEntry( $parentDeletedId );
			}
		);
	}

	/**
	 * @dataProvider provideGetListsByDateUpdated
	 * @param array $args
	 * @param array $expected
	 */
	public function testGetListsByDateUpdated( array $args, array $expected ) {
		$repository = new ReadingListRepository( 1, $this->db, $this->db, $this->lbFactory );
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

	public function provideGetListsByDateUpdated() {
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
	 * @param array $args
	 * @param array $expected
	 */
	public function testGetListEntriesByDateUpdated( array $args, array $expected ) {
		$repository = new ReadingListRepository( 1, $this->db, $this->db, $this->lbFactory );
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

	public function provideGetListEntriesByDateUpdated() {
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
		$repository = new ReadingListRepository( null, $this->db, $this->db, $this->lbFactory );

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

		$lists = $this->db->selectFieldValues( 'reading_list', 'rl_name' );
		$this->assertArrayEquals( $lists, [ 'OO', 'OX', 'XO'
 ] );

		$entries = $this->db->selectFieldValues( 'reading_list_entry', 'rle_title' );
		$this->assertArrayEquals( $entries, [ 'OO-OO', 'OO-OX', 'OO-XO',
			'OX-OO', 'OX-OX', 'OX-XO',
			'XO-OO', 'XO-OX', 'XO-XO',
		] );
	}

	/**
	 * @dataProvider provideGetListsByPage
	 * @param array $args
	 * @param array $expected
	 */
	public function testGetListsByPage( array $args, array $expected ) {
		$repository = new ReadingListRepository( 1, $this->db, $this->db, $this->lbFactory );
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

	public function provideGetListsByPage() {
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
		$this->assertThat( null, new PHPUnit_Framework_Constraint_Exception(
			ReadingListRepositoryException::class ) );
	}

	/**
	 * @param string $expectedTimestamp
	 * @param $actualTimestamp
	 * @param string $msg
	 */
	private function assertTimestampEquals( $expectedTimestamp, $actualTimestamp, $msg = '' ) {
		if ( strlen( $msg ) ) {
			$msg .= "\n";
		}
		$delta = abs( wfTimestamp( TS_UNIX, $expectedTimestamp )
			- wfTimestamp( TS_UNIX, $actualTimestamp ) );
		$this->assertLessThanOrEqual( 800, $delta,
			"${msg}Difference between expected timestamp ($expectedTimestamp) "
			. "and actual timetamp ($actualTimestamp) is too large" );
	}

	/**
	 * Non-crappy implementation of assertArraySubset.
	 * @param array $subset
	 * @param array $array
	 */
	private function assertSubset( array $subset, array $array ) {
		foreach ( $array as $key => $value ) {
			if ( is_numeric( $key ) ) {
				$pos = array_search( $value, $subset, true );
				if ( $pos !== false && is_numeric( $pos ) ) {
					unset( $subset[$pos] );
				}
			} else {
				unset( $subset[$key] );
			}
		}
		if ( $subset ) {
			$exporter = new Exporter();
			$this->fail( 'Failed asserting that ' . $exporter->export( $subset ) . ' is a subset of '
				. $exporter->export( $array ) );
		}
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
		return array_values( array_map( function ( $row ) use ( $filter ) {
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
