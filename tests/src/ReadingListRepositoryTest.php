<?php

namespace MediaWiki\Extensions\ReadingLists;

use MediaWiki\Extensions\ReadingLists\Doc\ReadingListEntryRow;
use MediaWiki\Extensions\ReadingLists\Doc\ReadingListRow;
use MediaWiki\MediaWikiServices;
use MediaWikiTestCase;
use PHPUnit_Framework_Constraint_Exception;
use SebastianBergmann\Exporter\Exporter;
use Wikimedia\Rdbms\IResultWrapper;
use Wikimedia\Rdbms\LBFactory;

/**
 * @group Database
 */
class ReadingListRepositoryTest extends MediaWikiTestCase {

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
			[ 'getAllLists' ],
			[ 'updateList', 1, 'foo' ],
			[ 'deleteList', 1 ],
			[ 'addListEntry', 1, 'foo', 'bar' ],
			[ 'getListEntries', [ 1 ] ],
			[ 'deleteListEntry', 1 ],
			[ 'getListOrder' ],
			[ 'setListOrder', [ 1 ] ],
			[ 'getListEntryOrder', 1 ],
			[ 'setListEntryOrder', 1, [] ],
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
			[ 'getAllLists' ],
			[ 'getListOrder' ],
			[ 'setListOrder', [ 1 ] ],
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
		$repository->setupForUser();
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

		// no rows after teardown; isSetupForUser() is false
		$repository->teardownForUser();
		$this->assertFalse( $repository->isSetupForUser() );
		$res = $this->db->select( 'reading_list', '*', [ 'rl_user_id' => 1 ] );
		$this->assertEquals( 0, $res->numRows() );
	}

	public function testAddList() {
		$repository = new ReadingListRepository( 1, $this->db, $this->db, $this->lbFactory );
		$repository->setupForUser();

		$listId = $repository->addList( 'foo' );
		/** @var ReadingListRow $row */
		$row = $this->db->selectRow( 'reading_list', '*', [ 'rl_id' => $listId ] );
		$this->assertTimestampEquals( wfTimestampNow(), $row->rl_date_created );
		$this->assertTimestampEquals( wfTimestampNow(), $row->rl_date_updated );
		unset( $row->rl_id, $row->rl_date_created, $row->rl_date_updated );
		$data = (array)$row;
		$this->assertArrayEquals( [
			'rl_user_id' => '1',
			'rl_name' => 'foo',
			'rl_description' => '',
			'rl_color' => '',
			'rl_image' => '',
			'rl_icon' => '',
			'rl_is_default' => '0',
			'rl_deleted' => '0',
		], $data );

		$listId = $repository->addList( 'bar', 'here is some bar', 'blue',
			'fake://example.png', 'fake://example_icon.png' );
		/** @var ReadingListRow $row */
		$row = $this->db->selectRow( 'reading_list', '*', [ 'rl_id' => $listId ] );
		$this->assertTimestampEquals( wfTimestampNow(), $row->rl_date_created );
		$this->assertTimestampEquals( wfTimestampNow(), $row->rl_date_updated );
		unset( $row->rl_id, $row->rl_date_created, $row->rl_date_updated );
		$data = (array)$row;
		$this->assertArrayEquals( [
			'rl_user_id' => '1',
			'rl_name' => 'bar',
			'rl_description' => 'here is some bar',
			'rl_color' => 'blue',
			'rl_image' => 'fake://example.png',
			'rl_icon' => 'fake://example_icon.png',
			'rl_is_default' => '0',
			'rl_deleted' => '0',
		], $data );

		$this->assertFailsWith( 'readinglists-db-error-too-long', function () use ( $repository ) {
			$repository->addList( 'boom',  str_pad( '', 1000, 'x' ) );
		} );
	}

	// @codingStandardsIgnoreLine MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName
	public function testAddList_count() {
		$repository = new ReadingListRepository( 1, $this->db, $this->db, $this->lbFactory );
		$repository->setLimits( 2, null );
		$repository->setupForUser();

		$listId = $repository->addList( 'foo' );
		$repository->deleteList( $listId );
		$repository->addList( 'bar' );
		$this->assertFailsWith( 'readinglists-db-error-list-limit', function () use ( $repository ) {
			$repository->addList( 'baz' );
		} );
	}

	public function testGetAllLists() {
		$this->addDataForAnotherUser();
		$repository = new ReadingListRepository( 1, $this->db, $this->db, $this->lbFactory );
		$repository->setupForUser();
		$this->addLists( 1, [
			[
				'rl_name' => 'foo',
				'rl_date_created' => '20100101000000',
				'rl_date_updated' => '20120101000000',
				'rl_deleted' => '0',
				'rls_index' => 1,
			],
			[
				'rl_name' => 'foo',
				'rl_description' => 'this is the second foo',
				'rl_date_created' => wfTimestampNow(),
				'rl_date_updated' => wfTimestampNow(),
				'rl_deleted' => '0',
				'rls_index' => 4,
			],
			[
				'rl_name' => 'bar',
				'rl_date_created' => '20010101000000',
				'rl_date_updated' => '20020101000000',
				'rl_deleted' => '0',
				'rls_index' => 3,
			],
			[
				'rl_date_created' => wfTimestampNow(),
				'rl_date_updated' => wfTimestampNow(),
				'rl_name' => 'baz',
				'rl_deleted' => '1',
				'rls_index' => 2,
			],
		] );
		$compareResultItems = function ( $expected, $actual ) {
			$this->assertTimestampEquals( $expected['rl_date_created'], $actual['rl_date_created'] );
			$this->assertTimestampEquals( $expected['rl_date_updated'], $actual['rl_date_updated'] );
			unset( $expected['rl_date_created'], $expected['rl_date_updated'] );
			unset( $actual['rl_id'], $actual['rl_date_created'], $actual['rl_date_updated'] );
			$this->assertArrayEquals( $expected, $actual, false, true );
		};
		$compare = function ( $expected, $res ) use ( $compareResultItems ) {
			$data = $this->resultWrapperToArray( $res );
			$this->assertEquals( count( $expected ), count( $data ), 'result length is different!' );
			array_map( $compareResultItems, $expected, $data );
		};

		$res = $repository->getAllLists();
		$expectedData = [
			[
				'rl_name' => 'default',
				'rl_description' => '',
				'rl_color' => '',
				'rl_image' => '',
				'rl_icon' => '',
				'rl_is_default' => '1',
				'rl_date_created' => wfTimestampNow(),
				'rl_date_updated' => wfTimestampNow(),
				'rl_deleted' => '0',
			],
			[
				'rl_name' => 'foo',
				'rl_description' => '',
				'rl_color' => '',
				'rl_image' => '',
				'rl_icon' => '',
				'rl_is_default' => '0',
				'rl_date_created' => '20100101000000',
				'rl_date_updated' => '20120101000000',
				'rl_deleted' => '0',
			],
			[
				'rl_name' => 'bar',
				'rl_description' => '',
				'rl_color' => '',
				'rl_image' => '',
				'rl_icon' => '',
				'rl_is_default' => '0',
				'rl_date_created' => '20010101000000',
				'rl_date_updated' => '20020101000000',
				'rl_deleted' => '0',
			],
			[
				'rl_name' => 'foo',
				'rl_description' => 'this is the second foo',
				'rl_color' => '',
				'rl_image' => '',
				'rl_icon' => '',
				'rl_is_default' => '0',
				'rl_date_created' => wfTimestampNow(),
				'rl_date_updated' => wfTimestampNow(),
				'rl_deleted' => '0',
			],
		];
		$compare( $expectedData, $res );

		$res = $repository->getAllLists( 1 );
		$compare( array_slice( $expectedData, 0, 1 ), $res );

		$res = $repository->getAllLists( 1, 1 );
		$compare( array_slice( $expectedData, 1, 1 ), $res );

		$res = $repository->getAllLists( 1, 10 );
		$this->assertSame( 0, iterator_count( $res ) );
	}

	public function testUpdateList() {
		$repository = new ReadingListRepository( 1, $this->db, $this->db, $this->lbFactory );
		$repository->setupForUser();
		list( $listId, $deletedListId ) = $this->addLists( 1, [
			[
				'rl_name' => 'foo',
				'rl_description' => 'xxx',
				'rl_date_created' => '20100101000000',
				'rl_date_updated' => '20120101000000',
				'rl_color' => 'blue',
				'rl_image' => 'image',
				'rl_icon' => 'ICON',
				'rl_deleted' => '0',
				'rls_index' => 1,
			],
			[
				'rl_name' => 'bar',
				'rl_description' => 'yyy',
				'rl_date_created' => '20100101000000',
				'rl_date_updated' => '20120101000000',
				'rl_color' => 'blue',
				'rl_image' => 'image',
				'rl_icon' => 'ICON',
				'rl_deleted' => '1',
				'rls_index' => 2,
			],
		] );

		$repository->updateList( $listId, 'bar' );
		/** @var ReadingListRow $row */
		$row = $this->db->selectRow( 'reading_list', '*', [ 'rl_id' => $listId ] );
		$this->assertEquals( 'bar', $row->rl_name );
		$this->assertEquals( 'xxx', $row->rl_description );
		$this->assertEquals( 'blue', $row->rl_color );
		$this->assertEquals( 'image', $row->rl_image );
		$this->assertEquals( 'ICON', $row->rl_icon );
		$this->assertTimestampEquals( '20100101000000', $row->rl_date_created );
		$this->assertTimestampEquals( wfTimestampNow(), $row->rl_date_updated );

		$repository->updateList( $listId, 'bar', 'yyy', 'red', 'img', 'ico' );
		/** @var ReadingListRow $row */
		$row = $this->db->selectRow( 'reading_list', '*', [ 'rl_id' => $listId ] );
		$this->assertEquals( 'bar', $row->rl_name );
		$this->assertEquals( 'yyy', $row->rl_description );
		$this->assertEquals( 'red', $row->rl_color );
		$this->assertEquals( 'img', $row->rl_image );
		$this->assertEquals( 'ico', $row->rl_icon );

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
				'rls_index' => 1,
			],
			[
				'rl_name' => 'bar',
				'rl_deleted' => '1',
				'rls_index' => 2,
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
		list( $listId, $deletedListId ) = $this->addLists( 1, [
			[
				'rl_name' => 'foo',
				'rl_deleted' => '0',
				'rls_index' => 1,
			],
			[
				'rl_name' => 'bar',
				'rl_deleted' => '1',
				'rls_index' => 2,
			],
		] );

		$entryId = $repository->addListEntry( $listId, 'en.wikipedia.org', 'Foo' );
		/** @var ReadingListEntryRow $row */
		$row = $this->db->selectRow( 'reading_list_entry', '*', [ 'rle_id' => $entryId ] );
		$this->assertEquals( 1, $row->rle_user_id );
		$this->assertEquals( 'en.wikipedia.org', $row->rle_project );
		$this->assertEquals( 'Foo', $row->rle_title );
		$this->assertTimestampEquals( wfTimestampNow(), $row->rle_date_created );
		$this->assertTimestampEquals( wfTimestampNow(), $row->rle_date_updated );
		$this->assertEquals( 0, $row->rle_deleted );

		// test that deletion + recreation does not trip the unique contstraint
		$repository->deleteListEntry( $entryId );
		$entryId2 = $repository->addListEntry( $listId, 'en.wikipedia.org', 'Foo' );
		/** @var ReadingListEntryRow $row */
		$row = $this->db->selectRow( 'reading_list_entry', '*', [ 'rle_id' => $entryId2 ] );
		$this->assertEquals( 1, $row->rle_user_id );
		$this->assertEquals( 'en.wikipedia.org', $row->rle_project );
		$this->assertEquals( 'Foo', $row->rle_title );
		$this->assertTimestampEquals( wfTimestampNow(), $row->rle_date_created );
		$this->assertTimestampEquals( wfTimestampNow(), $row->rle_date_updated );
		$this->assertEquals( 0, $row->rle_deleted );

		$this->assertFailsWith( 'readinglists-db-error-no-such-list',
			function () use ( $repository ) {
				$repository->addListEntry( 123, 'en.wikipedia.org', 'A' );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-not-own-list',
			function () use ( $listId ) {
				$repository = new ReadingListRepository( 123, $this->db, $this->db, $this->lbFactory );
				$repository->addListEntry( $listId, 'en.wikipedia.org', 'B' );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-list-deleted',
			function () use ( $repository, $deletedListId ) {
				$repository->addListEntry( $deletedListId, 'en.wikipedia.org', 'C' );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-duplicate-page',
			function () use ( $repository, $listId ) {
				$repository->addListEntry( $listId, 'en.wikipedia.org', 'Foo' );
			}
		);
	}

	// @codingStandardsIgnoreLine MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName
	public function testAddListEntry_count() {
		$repository = new ReadingListRepository( 1, $this->db, $this->db, $this->lbFactory );
		$repository->setLimits( null, 1 );
		$repository->setupForUser();

		list( $listId ) = $this->addLists( 1, [
			[
				'rl_name' => 'foo',
				'rl_deleted' => '0',
				'rls_index' => 2,
			],
		] );
		$entryId = $repository->addListEntry( $listId, 'en.wikipedia.org', 'Foo' );
		$repository->deleteListEntry( $entryId );
		$repository->addListEntry( $listId, 'en.wikipedia.org', 'Bar' );
		$this->assertFailsWith( 'readinglists-db-error-entry-limit',
			function () use ( $repository, $listId ) {
				$repository->addListEntry( $listId, 'en.wikipedia.org', 'Baz' );
			}
		);
	}

	public function testGetListEntries() {
		$repository = new ReadingListRepository( 1, $this->db, $this->db, $this->lbFactory );
		$repository->setupForUser();
		$defaultId = $this->db->selectField( 'reading_list', 'rl_id',
			[ 'rl_user_id' => 1, 'rl_is_default' => 1 ] );
		$this->addListEntries( $defaultId, 1, [
			[
				'rle_user_id' => 1,
				'rle_project' => 'foo',
				'rle_title' => 'Foo',
				'rle_date_created' => wfTimestampNow(),
				'rle_date_updated' => wfTimestampNow(),
				'rle_deleted' => 0,
				'rles_index' => 1,
			],
		] );
		list( $listId, $deletedListId ) = $this->addLists( 1, [
			[
				'rl_is_default' => 0,
				'rl_name' => 'test',
				'rls_index' => 1,
				'entries' => [
					[
						'rle_project' => 'foo',
						'rle_title' => 'bar',
						'rle_date_created' => wfTimestampNow(),
						'rle_date_updated' => wfTimestampNow(),
						'rle_deleted' => 0,
						'rles_index' => 1,
					],
					[
						'rle_project' => 'foo2',
						'rle_title' => 'bar2',
						'rle_date_created' => '20100101000000',
						'rle_date_updated' => '20120101000000',
						'rle_deleted' => 0,
						'rles_index' => 3,
					],
					[
						'rle_project' => 'foo3',
						'rle_title' => 'bar3',
						'rle_date_created' => wfTimestampNow(),
						'rle_date_updated' => wfTimestampNow(),
						'rle_deleted' => 0,
						'rles_index' => 2,
					],
					[
						'rle_project' => 'foo4',
						'rle_title' => 'bar4',
						'rle_date_created' => wfTimestampNow(),
						'rle_date_updated' => wfTimestampNow(),
						'rle_deleted' => 1,
						'rles_index' => 4,
					],
				],
			],
			[
				'rl_is_default' => 0,
				'rl_name' => 'test-deleted',
				'rl_deleted' => 1,
				'rls_index' => 2,
			],
		] );
		$compareResultItems = function ( $expected, $actual, $n ) {
			$this->assertTimestampEquals( $expected['rle_date_created'], $actual['rle_date_created'],
				"Mismatch in item $n" );
			$this->assertTimestampEquals( $expected['rle_date_updated'], $actual['rle_date_updated'],
				"Mismatch in item $n" );
			unset( $expected['rle_date_created'], $expected['rle_date_updated'] );
			unset( $actual['rle_id'],  $actual['rle_date_created'],
				$actual['rle_date_updated'] );
			$this->assertArrayEquals( $expected, $actual, false, true );
		};
		$compare = function ( $expected, $res ) use ( $compareResultItems ) {
			$data = $this->resultWrapperToArray( $res );
			$this->assertEquals( count( $expected ), count( $data ), 'result length is different!' );
			array_map( $compareResultItems, $expected, $data, range( 1, count( $expected ) ) );
		};

		$res = $repository->getListEntries( [ $defaultId, $listId ] );
		$expectedData = [
			[
				'rle_rl_id' => $defaultId,
				'rle_project' => 'foo',
				'rle_title' => 'Foo',
				'rle_date_created' => wfTimestampNow(),
				'rle_date_updated' => wfTimestampNow(),
				'rle_deleted' => 0,
			],
			[
				'rle_rl_id' => $listId,
				'rle_project' => 'foo',
				'rle_title' => 'bar',
				'rle_date_created' => wfTimestampNow(),
				'rle_date_updated' => wfTimestampNow(),
				'rle_deleted' => 0,
			],
			[
				'rle_rl_id' => $listId,
				'rle_project' => 'foo3',
				'rle_title' => 'bar3',
				'rle_date_created' => wfTimestampNow(),
				'rle_date_updated' => wfTimestampNow(),
				'rle_deleted' => 0,
			],
			[
				'rle_rl_id' => $listId,
				'rle_project' => 'foo2',
				'rle_title' => 'bar2',
				'rle_date_created' => '20100101000000',
				'rle_date_updated' => '20120101000000',
				'rle_deleted' => 0,
			],
		];
		$compare( $expectedData, $res );

		$res = $repository->getListEntries( [ $listId ] );
		$compare( array_slice( $expectedData, 1 ), $res );

		$res = $repository->getListEntries( [ $defaultId, $listId ], 2 );
		$compare( array_slice( $expectedData, 0, 2 ), $res );

		$res = $repository->getListEntries( [ $defaultId, $listId ], 2, 2 );
		$compare( array_slice( $expectedData, 2, 2 ), $res );

		$this->assertFailsWith( 'readinglists-db-error-empty-list-ids',
			function () use ( $repository ) {
				$repository->getListEntries( [] );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-no-such-list',
			function () use ( $repository ) {
				$repository->getListEntries( [ 123 ] );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-not-own-list',
			function () use ( $defaultId ) {
				$repository = new ReadingListRepository( 123, $this->db, $this->db, $this->lbFactory );
				$repository->getListEntries( [ $defaultId ] );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-list-deleted',
			function () use ( $repository, $deletedListId ) {
				$repository->getListEntries( [ $deletedListId ] );
			}
		);
	}

	public function testDeleteListEntry() {
		$repository = new ReadingListRepository( 1, $this->db, $this->db, $this->lbFactory );
		$repository->setupForUser();
		list( $listId, $deletedListId ) = $this->addLists( 1, [
			[
				'rl_is_default' => 0,
				'rl_name' => 'test',
				'rl_date_created' => wfTimestampNow(),
				'rl_date_updated' => wfTimestampNow(),
				'rl_deleted' => 0,
				'rls_index' => 1,
			],
			[
				'rl_name' => 'bar',
				'rl_deleted' => '1',
				'rls_index' => 2,
			],
		] );
		list( $fooId, $foo2Id, $deletedId ) = $this->addListEntries( $listId, 1, [
			[
				'rle_project' => 'foo',
				'rle_title' => 'bar',
				'rle_date_created' => wfTimestampNow(),
				'rle_date_updated' => wfTimestampNow(),
				'rle_deleted' => 0,
				'rles_index' => 1,
			],
			[
				'rle_project' => 'foo2',
				'rle_title' => 'bar2',
				'rle_date_created' => wfTimestampNow(),
				'rle_date_updated' => wfTimestampNow(),
				'rle_deleted' => 0,
				'rles_index' => 2,
			],
			[
				'rle_project' => 'foo3',
				'rle_title' => 'bar3',
				'rle_date_created' => wfTimestampNow(),
				'rle_date_updated' => wfTimestampNow(),
				'rle_deleted' => 1,
				'rles_index' => 3,
			],
		] );
		list( $parentDeletedId ) = $this->addListEntries( $deletedListId, 1, [
			[
				'rle_project' => 'foo4',
				'rle_title' => 'bar4',
				'rle_date_created' => wfTimestampNow(),
				'rle_date_updated' => wfTimestampNow(),
				'rle_deleted' => 0,
				'rles_index' => 1,
			],
		] );

		$repository->deleteListEntry( $fooId );
		$this->assertEquals( 1, $this->db->selectRowCount( 'reading_list_entry',
			'1', [ 'rle_rl_id' => $listId, 'rle_deleted' => 0 ] ) );
		/** @var ReadingListEntryRow $row */
		$row = $this->db->selectRow( 'reading_list_entry', '*',
			[ 'rle_rl_id' => $listId, 'rle_deleted' => 1 ] );
		$this->assertEquals( 'foo', $row->rle_project );
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

	public function testListOrder() {
		$repository = new ReadingListRepository( 1, $this->db, $this->db, $this->lbFactory );
		$repository->setupForUser();
		$defaultId = $this->db->selectField( 'reading_list', 'rl_id',
			[ 'rl_user_id' => 1, 'rl_is_default' => 1 ] );
		$this->db->update( 'reading_list',
			[ 'rl_date_updated' => $this->db->timestamp( '20100101000000' ) ],
			[ 'rl_id' => $defaultId ] );
		list( $foreignId, $fooId, $foo2Id, $foo3Id, $deletedId ) = $this->addLists( 1, [
			[
				'rl_user_id' => 100,
				'rl_name' => 'foo',
				'rls_index' => 1,
			],
			[
				'rl_name' => 'foo',
				'rls_index' => 1,
			],
			[
				'rl_name' => 'foo2',
				'rls_index' => 4,
			],
			[
				'rl_name' => 'foo3',
				'rls_index' => 3,
			],
			[
				'rl_name' => 'deleted',
				'rl_deleted' => 1,
				'rls_index' => 5,
			],
		] );

		$order = $repository->getListOrder();
		$this->assertArrayEquals( [ $defaultId, $fooId, $foo3Id, $foo2Id ], $order, true );

		$newOrder = [ $fooId, $foo3Id, $foo2Id, $defaultId ];
		$repository->setListOrder( $newOrder );
		$order = $repository->getListOrder();
		$this->assertArrayEquals( $newOrder, $order, true );
		$defaultListTimestamp = $this->db->selectField( 'reading_list', 'rl_date_updated',
			[ 'rl_id' => $defaultId ] );
		$this->assertTimestampEquals( wfTimestampNow(), $defaultListTimestamp );

		$this->assertFailsWith( 'readinglists-db-error-empty-order',
			function () use ( $repository ) {
				$repository->setListOrder( [] );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-missing-list',
			function () use ( $repository, $fooId, $foo2Id ) {
				$repository->setListOrder( [ $fooId, $foo2Id ] );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-not-own-list',
			function () use ( $repository, $fooId, $foo2Id, $foreignId ) {
				$repository->setListOrder( [ $fooId, $foo2Id, $foreignId ] );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-list-deleted',
			function () use ( $repository, $fooId, $foo2Id, $deletedId ) {
				$repository->setListOrder( [ $fooId, $foo2Id, $deletedId ] );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-no-such-list',
			function () use ( $repository, $fooId, $foo2Id ) {
				$repository->setListOrder( [ $fooId, $foo2Id, 1234 ] );
			}
		);
	}

	public function testListEntryOrder() {
		$repository = new ReadingListRepository( 1, $this->db, $this->db, $this->lbFactory );
		$repository->setupForUser();
		list( $emptyListId, $listId, $deletedListId ) = $this->addLists( 1, [
			[
				'rl_name' => 'empty',
				'rls_index' => 10,
			],
			[
				'rl_name' => 'foo',
				'rls_index' => 1,
				'rl_date_updated' => '20100101000000',
			],
			[
				'rl_name' => 'deleted',
				'rl_deleted' => '1',
				'rls_index' => 2,
			],
		] );
		list( $entry1, $entry2, $entry3, $deletedEntry ) = $this->addListEntries( $listId, 1, [
			[
				'rle_project' => 'foo',
				'rle_title' => 'bar',
				'rles_index' => 1,
			],
			[
				'rle_project' => 'foo2',
				'rle_title' => 'bar2',
				'rles_index' => 3,
			],
			[
				'rle_project' => 'foo3',
				'rle_title' => 'bar3',
				'rles_index' => 2,
			],
			[
				'rle_project' => 'foo4',
				'rle_title' => 'bar4',
				'rle_deleted' => 1,
				'rles_index' => 4,
			],
		] );
		list( $parentDeletedEntry ) = $this->addListEntries( $deletedListId, 1,
			[ [ 'rle_project' => 'foo5', 'rle_title' => 'bar5', 'rles_index' => 1 ] ] );
		list( $foreignListId ) = $this->addLists( 100, [ [ 'rl_name' => 'foo', 'rls_index' => 1 ] ] );
		list( $foreignEntry ) = $this->addListEntries( $foreignListId, 100,
			[ [ 'rle_project' => 'foo', 'rle_title' => 'bar' ] ] );

		$order = $repository->getListEntryOrder( $emptyListId );
		$this->assertSame( [], $order );
		$order = $repository->getListEntryOrder( $listId );
		$this->assertArrayEquals( [ $entry1, $entry3, $entry2 ], $order, true );

		$newOrder = [ $entry3, $entry1, $entry2 ];
		$repository->setListEntryOrder( $listId, $newOrder );
		$order = $repository->getListEntryOrder( $listId );
		$this->assertArrayEquals( $newOrder, $order, true );
		$listTimestamp = $this->db->selectField( 'reading_list', 'rl_date_updated',
			[ 'rl_id' => $listId ] );
		$this->assertTimestampEquals( wfTimestampNow(), $listTimestamp );

		$this->assertFailsWith( 'readinglists-db-error-not-own-list',
			function () use ( $repository, $foreignListId ) {
				$repository->getListEntryOrder( $foreignListId );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-list-deleted',
			function () use ( $repository, $deletedListId ) {
				$repository->getListEntryOrder( $deletedListId );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-no-such-list',
			function () use ( $repository ) {
				$repository->getListEntryOrder( 123 );
			}
		);

		$this->assertFailsWith( 'readinglists-db-error-empty-order',
			function () use ( $repository, $listId ) {
				$repository->setListEntryOrder( $listId, [] );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-missing-list-entry',
			function () use ( $repository, $listId, $entry1, $entry2 ) {
				$repository->setListEntryOrder( $listId, [ $entry1, $entry2 ] );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-not-own-list-entry',
			function () use ( $repository, $listId, $foreignEntry ) {
				$repository->setListEntryOrder( $listId, [ $foreignEntry ] );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-entry-not-in-list',
			function () use ( $repository, $emptyListId, $entry1 ) {
				$repository->setListEntryOrder( $emptyListId, [ $entry1 ] );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-list-deleted',
			function () use ( $repository, $deletedListId, $parentDeletedEntry ) {
				$repository->setListEntryOrder( $deletedListId, [ $parentDeletedEntry ] );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-list-entry-deleted',
			function () use ( $repository, $listId, $entry1, $entry2, $entry3, $deletedEntry ) {
				$repository->setListEntryOrder( $listId, [ $entry1, $entry2, $entry3, $deletedEntry ] );
			}
		);
		$this->assertFailsWith( 'readinglists-db-error-no-such-list-entry',
			function () use ( $repository, $listId, $entry1, $entry2, $entry3 ) {
				$repository->setListEntryOrder( $listId, [ $entry1, $entry2, $entry3, 1234 ] );
			}
		);
	}

	public function testPurgeSortkeys() {
		$repository = new ReadingListRepository( null, $this->db, $this->db, $this->lbFactory );
		$this->addLists( 1, [ [
			'rl_name' => 'list',
			'rls_index' => 1,
			'entries' => [
				[
					'rle_project' => 'foo',
					'rle_title' => 'bar',
					'rles_index' => 1,
				],
			],
		] ] );
		$this->db->insert( 'reading_list_sortkey', [ [ 'rls_rl_id' => 99, 'rls_index' => 2 ] ] );
		$this->db->insert( 'reading_list_entry_sortkey', [ [ 'rles_rle_id' => 99, 'rles_index' => 2 ] ] );

		$listSortkeys = $this->db->selectFieldValues( 'reading_list_sortkey', 'rls_index' );
		$entrySortkeys = $this->db->selectFieldValues( 'reading_list_entry_sortkey', 'rles_index' );
		$this->assertEquals( [ 1, 2 ], $listSortkeys );
		$this->assertEquals( [ 1, 2 ], $entrySortkeys );
		$repository->purgeSortkeys();
		$listSortkeys = $this->db->selectFieldValues( 'reading_list_sortkey', 'rls_index' );
		$entrySortkeys = $this->db->selectFieldValues( 'reading_list_entry_sortkey', 'rles_index' );
		$this->assertEquals( [ 1 ], $listSortkeys );
		$this->assertEquals( [ 1 ], $entrySortkeys );
	}

	public function testGetListsByDateUpdated() {
		$repository = new ReadingListRepository( 1, $this->db, $this->db, $this->lbFactory );
		$this->addLists( 1, [
			[
				'rl_name' => 'new',
				'rl_date_updated' => '20150101000000',
			],
			[
				'rl_name' => 'deleted',
				'rl_deleted' => 1,
				'rl_date_updated' => '20150102000000',
			],
			[
				'rl_name' => 'old',
				'rl_date_updated' => '20080101000000',
			],
		] );

		$expected = [ 'new', 'deleted' ];
		$res = $repository->getListsByDateUpdated( '20100101000000' );
		$data = $this->resultWrapperToArray( $res, 'rl_name' );
		$this->assertArrayEquals( $expected, $data );

		$res = $repository->getListsByDateUpdated( '20100101000000', 1 );
		$data = $this->resultWrapperToArray( $res, 'rl_name' );
		$this->assertCount( 1, $data );
		$this->assertSubset( $data, $expected );

		$res = $repository->getListsByDateUpdated( '20100101000000', 1, 1 );
		$data2 = $this->resultWrapperToArray( $res, 'rl_name' );
		$this->assertCount( 1, $data2 );
		$this->assertSubset( $data2, $expected );
		$this->assertNotEquals( $data, $data2 );
	}

	public function testGetListEntriesByDateUpdated() {
		$repository = new ReadingListRepository( 1, $this->db, $this->db, $this->lbFactory );
		$this->addLists( 1, [
			[
				'rl_name' => 'one',
				'entries' => [
					[
						'rle_project' => 'new',
						'rle_title' => 'new',
						'rle_date_updated' => '20150101000000',
					],
					[
						'rle_project' => 'deleted',
						'rle_title' => 'deleted',
						'rle_deleted' => 1,
						'rle_date_updated' => '20150101000000',
					],
					[
						'rle_project' => 'old',
						'rle_title' => 'old',
						'rle_date_updated' => '20080101000000',
					],
				],
			],
			[
				'rl_name' => 'two',
				'entries' => [
					[
						'rle_project' => 'other',
						'rle_title' => 'other',
						'rle_date_updated' => '20150101000000',
					],
				],
			],
			[
				'rl_name' => 'deleted',
				'rl_deleted' => 1,
				'entries' => [
					[
						'rle_project' => 'parent deleted',
						'rle_title' => 'parent deleted',
						'rle_date_updated' => '20150101000000',
					],
				],
			],
		] );

		$expected = [ 'new', 'deleted', 'other' ];
		$res = $repository->getListEntriesByDateUpdated( '20100101000000' );
		$data = $this->resultWrapperToArray( $res, 'rle_title' );
		$this->assertArrayEquals( $expected, $data );

		$res = $repository->getListEntriesByDateUpdated( '20100101000000', 1 );
		$data = $this->resultWrapperToArray( $res, 'rle_title' );
		$this->assertCount( 1, $data );
		$this->assertSubset( $data, $expected );

		$res = $repository->getListEntriesByDateUpdated( '20100101000000', 1, 1 );
		$data2 = $this->resultWrapperToArray( $res, 'rle_title' );
		$this->assertCount( 1, $data2 );
		$this->assertSubset( $data2, $expected );
		$this->assertNotEquals( $data, $data2 );
	}

	public function testPurgeOldDeleted() {
		$repository = new ReadingListRepository( null, $this->db, $this->db, $this->lbFactory );
		$entries = [
			[
				'rle_project' => '-',
				'rle_title' => 'kept',
				'rle_date_updated' => wfTimestampNow(),
			],
			[
				'rle_project' => '-',
				'rle_title' => 'deleted-new',
				'rle_date_updated' => '20150101000000',
				'rle_deleted' => 1,
			],
			[
				'rle_project' => '-',
				'rle_title' => 'deleted-old',
				'rle_date_updated' => '20080101000000',
				'rle_deleted' => 1,
			],
		];
		// transform title depending on parent, e.g. 'kept' => 'kept-parent-deleted'
		$appendName = function ( $name ) use ( $entries ) {
			return array_map( function ( $entry ) use ( $name ) {
				$entry['rle_title'] .= '-' . $name;
				return $entry;
			}, $entries );
		};
		$this->addLists( 1, [
			[
				'rl_name' => 'kept',
				'rl_date_updated' => wfTimestampNow(),
				'entries' => $appendName( 'parent-kept' ),
			],
			[
				'rl_name' => 'deleted-new',
				'rl_date_updated' => '20150101000000',
				'rl_deleted' => 1,
				'entries' => $appendName( 'parent-deleted-new' ),
			],
			[
				'rl_name' => 'deleted-old',
				'rl_date_updated' => '20080101000000',
				'rl_deleted' => 1,
				'entries' => $appendName( 'parent-deleted-old' ),
			],
		] );

		$repository->purgeOldDeleted( '20100101000000' );
		$keptLists = $this->db->selectFieldValues( 'reading_list', 'rl_name' );
		$keptEntries = $this->db->selectFieldValues( 'reading_list_entry', 'rle_title' );
		$this->assertArrayEquals( [ 'kept', 'deleted-new' ], $keptLists );
		$this->assertArrayEquals( [ 'kept-parent-kept', 'deleted-new-parent-kept',
			'kept-parent-deleted-new', 'deleted-new-parent-deleted-new' ], $keptEntries );
	}

	public function testGetListsByPage() {
		$repository = new ReadingListRepository( 1, $this->db, $this->db, $this->lbFactory );
		$this->addLists( 1, [
			[
				// no match
				'rl_name' => 'first',
				'entries' => [
					[
						'rle_project' => '-',
						'rle_title' => 'o',
					],
				],
			],
			[
				// entry deleted, no match
				'rl_name' => 'second',
				'entries' => [
					[
						'rle_project' => '-',
						'rle_title' => 'x',
						'rle_deleted' => 1,
					],
				],
			],
			[
				// list deleted, no match
				'rl_name' => 'third',
				'rl_deleted' => 1,
				'entries' => [
					[
						'rle_project' => '-',
						'rle_title' => 'x',
					],
				],
			],
			[
				// match
				'rl_name' => 'fourth',
				'entries' => [
					[
						'rle_project' => '-',
						'rle_title' => 'x',
					],
					[
						'rle_project' => '-',
						'rle_title' => 'o',
					],
				],
			],
			[
				// another match
				'rl_name' => 'fifth',
				'entries' => [
					[
						'rle_project' => '-',
						'rle_title' => 'x',
					],
					[
						'rle_project' => '-',
						'rle_title' => 'o',
					],
				],
			],
		] );

		$expected = [ 'fourth', 'fifth' ];
		$res = $repository->getListsByPage( '-', 'x' );
		$data = $this->resultWrapperToArray( $res, 'rl_name' );
		$this->assertArrayEquals( $expected, $data );

		$res = $repository->getListsByPage( '-', 'x', 1 );
		$data = $this->resultWrapperToArray( $res, 'rl_name' );
		$this->assertCount( 1, $data );
		$this->assertSubset( $data, $expected );

		$res = $repository->getListsByPage( '-', 'x', 1, 1 );
		$data2 = $this->resultWrapperToArray( $res, 'rl_name' );
		$this->assertCount( 1, $data2 );
		$this->assertSubset( $data2, $expected );
		$this->assertNotEquals( $data, $data2 );
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
		$this->assertLessThanOrEqual( 3, $delta,
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

	/**
	 * Creates reading_list rows from the given data, with some magic fields:
	 * - missing user ids will be added automatically
	 * - 'rls_index' will be converted into an index row
	 * - 'entries' (array of rows for reading_list_entry) willbe converted into their own rows
	 * - 'entries' items can have an 'rles_index' field which is treated like 'rls_index'
	 * @param int $userId Th central ID of the list owner
	 * @param array[] $lists Array of rows for reading_list, with some magic fields
	 * @return array The list IDs
	 */
	private function addLists( $userId, array $lists ) {
		$listIds = [];
		foreach ( $lists as $list ) {
			if ( !isset( $list['rl_user_id'] ) ) {
				$list['rl_user_id'] = $userId;
			}
			$entries = $index = null;
			if ( isset( $list['entries'] ) ) {
				$entries = $list['entries'];
				unset( $list['entries'] );
			}
			if ( isset( $list['rls_index'] ) ) {
				$index = $list['rls_index'];
				unset( $list['rls_index'] );
			}
			$this->db->insert( 'reading_list', $list );
			$listId = $this->db->insertId();
			if ( $entries !== null ) {
				$this->addListEntries( $listId, $list['rl_user_id'], $entries );
			}
			if ( $index !== null ) {
				$this->db->insert( 'reading_list_sortkey',
					[ 'rls_rl_id' => $listId, 'rls_index' => $index ] );
			}
			$listIds[] = $listId;
		}
		return $listIds;
	}

	/**
	 * Creates reading_list_entry rows from the given data, with some magic fields:
	 * - missing list ids will be filled automatically
	 * - 'rles_index' will be converted into an index row
	 * @param int $listId The list to add entries to
	 * @param int $userId Th central ID of the list owner
	 * @param array[] $entries Array of rows for reading_list_entry, with some magic fields
	 * @return array The list entry IDs
	 */
	private function addListEntries( $listId, $userId, array $entries ) {
		$entryIds = [];
		foreach ( $entries as $entry ) {
			if ( !isset( $entry['rle_rl_id'] ) ) {
				$entry['rle_rl_id'] = $listId;
			}
			if ( !isset( $entry['rle_user_id'] ) ) {
				$entry['rle_user_id'] = $userId;
			}
			$index = null;
			if ( isset( $entry['rles_index'] ) ) {
				$index = $entry['rles_index'];
				unset( $entry['rles_index'] );
			}
			$this->db->insert( 'reading_list_entry', $entry );
			$entryId = $this->db->insertId();
			if ( $index !== null ) {
				$this->db->insert( 'reading_list_entry_sortkey',
					[ 'rles_rle_id' => $entryId, 'rles_index' => $index ] );
			}
			$entryIds[] = $entryId;
		}
		return $entryIds;
	}

	private function addDataForAnotherUser() {
		$this->addLists( 10, [
			[
				'rl_is_default' => 1,
				'rl_name' => 'default',
				'rl_date_created' => wfTimestampNow(),
				'rl_date_updated' => wfTimestampNow(),
				'rl_deleted' => 0,
				'rls_index' => 0,
				'entries' => [
					[
						'rle_project' => 'foo',
						'rle_title' => 'bar',
						'rle_date_created' => wfTimestampNow(),
						'rle_date_updated' => wfTimestampNow(),
						'rle_deleted' => 0,
						'rles_index' => 1,
					],
					[
						'rle_project' => 'foo2',
						'rle_title' => 'bar2',
						'rle_date_created' => wfTimestampNow(),
						'rle_date_updated' => wfTimestampNow(),
						'rle_deleted' => 0,
						'rles_index' => 2,
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
