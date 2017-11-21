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
			],
			[
				'rl_name' => 'foo',
				'rl_description' => 'this is the second foo',
				'rl_date_created' => wfTimestampNow(),
				'rl_date_updated' => wfTimestampNow(),
				'rl_deleted' => '0',
			],
			[
				'rl_name' => 'bar',
				'rl_date_created' => '20010101000000',
				'rl_date_updated' => '20020101000000',
				'rl_deleted' => '0',
			],
			[
				'rl_name' => 'baz',
				'rl_date_created' => wfTimestampNow(),
				'rl_date_updated' => wfTimestampNow(),
				'rl_deleted' => '1',
			],
		] );
		$compareResultItems = function ( array $expected, array $actual ) {
			$this->assertTimestampEquals( $expected['rl_date_created'], $actual['rl_date_created'] );
			$this->assertTimestampEquals( $expected['rl_date_updated'], $actual['rl_date_updated'] );
			unset( $expected['rl_date_created'], $expected['rl_date_updated'] );
			unset( $actual['rl_id'], $actual['rl_date_created'], $actual['rl_date_updated'] );
			$this->assertArrayEquals( $expected, $actual, false, true );
		};
		$compare = function ( array $expected, IResultWrapper $res ) use ( $compareResultItems ) {
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
			],
			[
				'rl_name' => 'bar',
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
		list( $projectId ) = $this->addProjects( [ 'en.wikipedia.org' ] );
		list( $listId, $deletedListId ) = $this->addLists( 1, [
			[
				'rl_name' => 'foo',
				'rl_deleted' => '0',
			],
			[
				'rl_name' => 'bar',
				'rl_deleted' => '1',
			],
		] );

		$entryId = $repository->addListEntry( $listId, 'en.wikipedia.org', 'Foo' );
		/** @var ReadingListEntryRow $row */
		$row = $this->db->selectRow( 'reading_list_entry', '*', [ 'rle_id' => $entryId ] );
		$this->assertEquals( 1, $row->rle_user_id );
		$this->assertEquals( $projectId, $row->rle_rlp_id );
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
		$this->assertEquals( $projectId, $row->rle_rlp_id );
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
		$this->assertFailsWith( 'readinglists-db-error-no-such-project',
			function () use ( $repository, $listId ) {
				$repository->addListEntry( $listId, 'nosuch.project.org', 'Foo' );
			}
		);
	}

	// @codingStandardsIgnoreLine MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName
	public function testAddListEntry_count() {
		$repository = new ReadingListRepository( 1, $this->db, $this->db, $this->lbFactory );
		$repository->setLimits( null, 1 );
		$repository->setupForUser();

		$this->addProjects( [ 'en.wikipedia.org' ] );
		list( $listId ) = $this->addLists( 1, [
			[
				'rl_name' => 'foo',
				'rl_deleted' => '0',
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
				'rlp_project' => 'foo',
				'rle_title' => 'Foo',
				'rle_date_created' => wfTimestampNow(),
				'rle_date_updated' => wfTimestampNow(),
				'rle_deleted' => 0,
			],
		] );
		list( $listId, $deletedListId ) = $this->addLists( 1, [
			[
				'rl_is_default' => 0,
				'rl_name' => 'test',
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
						'rle_date_created' => '20100101000000',
						'rle_date_updated' => '20120101000000',
						'rle_deleted' => 0,
					],
					[
						'rlp_project' => 'foo3',
						'rle_title' => 'bar3',
						'rle_date_created' => wfTimestampNow(),
						'rle_date_updated' => wfTimestampNow(),
						'rle_deleted' => 0,
					],
					[
						'rlp_project' => 'foo4',
						'rle_title' => 'bar4',
						'rle_date_created' => wfTimestampNow(),
						'rle_date_updated' => wfTimestampNow(),
						'rle_deleted' => 1,
					],
				],
			],
			[
				'rl_is_default' => 0,
				'rl_name' => 'test-deleted',
				'rl_deleted' => 1,
			],
		] );
		$compareResultItems = function ( $expected, $actual, $n ) {
			$this->assertTimestampEquals( $expected['rle_date_created'], $actual['rle_date_created'],
				"Mismatch in item $n" );
			$this->assertTimestampEquals( $expected['rle_date_updated'], $actual['rle_date_updated'],
				"Mismatch in item $n" );
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

		$res = $repository->getListEntries( [ $defaultId, $listId ] );
		$expectedData = [
			[
				'rle_rl_id' => $defaultId,
				'rlp_project' => 'foo',
				'rle_title' => 'Foo',
				'rle_date_created' => wfTimestampNow(),
				'rle_date_updated' => wfTimestampNow(),
				'rle_deleted' => 0,
			],
			[
				'rle_rl_id' => $listId,
				'rlp_project' => 'foo',
				'rle_title' => 'bar',
				'rle_date_created' => wfTimestampNow(),
				'rle_date_updated' => wfTimestampNow(),
				'rle_deleted' => 0,
			],
			[
				'rle_rl_id' => $listId,
				'rlp_project' => 'foo2',
				'rle_title' => 'bar2',
				'rle_date_created' => '20100101000000',
				'rle_date_updated' => '20120101000000',
				'rle_deleted' => 0,
			],
			[
				'rle_rl_id' => $listId,
				'rlp_project' => 'foo3',
				'rle_title' => 'bar3',
				'rle_date_created' => wfTimestampNow(),
				'rle_date_updated' => wfTimestampNow(),
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
				'rl_name' => 'bar',
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
						'rlp_project' => 'new',
						'rle_title' => 'new',
						'rle_date_updated' => '20150101000000',
					],
					[
						'rlp_project' => 'deleted',
						'rle_title' => 'deleted',
						'rle_deleted' => 1,
						'rle_date_updated' => '20150101000000',
					],
					[
						'rlp_project' => 'old',
						'rle_title' => 'old',
						'rle_date_updated' => '20080101000000',
					],
				],
			],
			[
				'rl_name' => 'two',
				'entries' => [
					[
						'rlp_project' => 'other',
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
						'rlp_project' => 'parent deleted',
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
				'rlp_project' => '-',
				'rle_title' => 'kept',
				'rle_date_updated' => wfTimestampNow(),
			],
			[
				'rlp_project' => '-',
				'rle_title' => 'deleted-new',
				'rle_date_updated' => '20150101000000',
				'rle_deleted' => 1,
			],
			[
				'rlp_project' => '-',
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
				'rl_name' => 'third',
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
	 * - 'entries' (array of rows for reading_list_entry) willbe converted into their own rows
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
			$entries = null;
			if ( isset( $list['entries'] ) ) {
				$entries = $list['entries'];
				unset( $list['entries'] );
			}
			$this->db->insert( 'reading_list', $list );
			$listId = $this->db->insertId();
			if ( $entries !== null ) {
				$this->addListEntries( $listId, $list['rl_user_id'], $entries );
			}
			$listIds[] = $listId;
		}
		return $listIds;
	}

	/**
	 * Creates reading_list_entry rows from the given data, with some magic fields:
	 * - missing list ids will be filled automatically
	 * - 'rlp_project' will be handled appropriately
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
			if ( isset( $entry['rlp_project'] ) ) {
				list( $projectId ) = $this->addProjects( [ $entry['rlp_project'] ] );
				unset( $entry['rlp_project'] );
				$entry['rle_rlp_id'] = $projectId;
			}
			$this->db->insert( 'reading_list_entry', $entry );
			$entryId = $this->db->insertId();
			$entryIds[] = $entryId;
		}
		return $entryIds;
	}

	/**
	 * Creates reading_list_project rows from the given data.
	 * @param string[] $projects
	 * @return int[] Project IDs
	 */
	private function addProjects( array $projects ) {
		$ids = [];
		foreach ( $projects as $project ) {
			$this->db->insert(
				'reading_list_project',
				[ 'rlp_project' => $project ],
				__METHOD__,
				[ 'IGNORE' ]
			);
			$projectId = $this->db->insertId();
			if ( !$projectId ) {
				$projectId = $this->db->selectField(
					'reading_list_project',
					'rlp_id',
					[ 'rlp_project' => $project ]
				);
			}
			$ids[] = $projectId;
		}
		return $ids;
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
