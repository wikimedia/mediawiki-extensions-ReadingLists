<?php

namespace MediaWiki\Extensions\ReadingLists;

use DBAccessObjectUtils;
use IDBAccessObject;
use LogicException;
use MediaWiki\Extensions\ReadingLists\Doc\ReadingListEntryRow;
use MediaWiki\Extensions\ReadingLists\Doc\ReadingListRow;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Wikimedia\Rdbms\IDatabase;
// @codingStandardsIgnoreStart MediaWiki.Classes.UnusedUseStatement.UnusedUse
use Wikimedia\Rdbms\IResultWrapper;
// @codingStandardsIgnoreEnd
use Wikimedia\Rdbms\LBFactory;

/**
 * A DAO class for reading lists.
 *
 * Reading lists are private and only ever visible to the owner. The class is constructed with a
 * user ID; unless otherwise noted, all operations are limited to lists/entries belonging to that
 * user. Calling with parameters inconsistent with that will result in an error.
 *
 * Methods which query data will usually return a result set (as if Database::select was called
 * directly). Methods which modify data don't return anything. A ReadingListRepositoryException
 * will be thrown if the operation failed or was invalid. A lock on the list (for list entry
 * changes) or on the default list (for list changes) is used to avoid race conditions, so most
 * write commands can fail with lock timeouts as well. Since lists are private and conflict can
 * only happen between devices of the same user, this should be exceedingly rare.
 */
class ReadingListRepository implements IDBAccessObject, LoggerAwareInterface {

	/** @var LoggerInterface */
	private $logger;

	/** @var IDatabase */
	private $dbw;

	/** @var IDatabase */
	private $dbr;

	/** @var int|null */
	private $userId;

	/** @var LBFactory */
	private $lbFactory;

	/**
	 * @param int $userId Central ID of the user.
	 * @param IDatabase $dbw Database connection for writing.
	 * @param IDatabase $dbr Database connection for reading.
	 * @param LBFactory $lbFactory
	 */
	public function __construct( $userId, IDatabase $dbw, IDatabase $dbr, LBFactory $lbFactory ) {
		$this->userId = $userId;
		$this->dbw = $dbw;
		$this->dbr = $dbr;
		$this->lbFactory = $lbFactory;
		$this->logger = new NullLogger();
	}

	/**
	 * @param LoggerInterface $logger
	 * @return void
	 */
	public function setLogger( LoggerInterface $logger ) {
		$this->logger = $logger;
	}

	// setup / teardown

	/**
	 * Set up the service for the given user.
	 * This is a pre-requisite for doing anything else. It will create a default list.
	 * @return void
	 * @throws ReadingListRepositoryException
	 */
	public function setupForUser() {
		$this->assertUser();
		if ( $this->isSetupForUser( self::READ_LOCKING ) ) {
			throw new ReadingListRepositoryException( 'readinglists-db-error-already-set-up' );
		}
		$this->dbw->insert(
			'reading_list',
			[
				'rl_user_id' => $this->userId,
				'rl_is_default' => 1,
				'rl_name' => 'default',
				'rl_description' => '',
				'rl_color' => '',
				'rl_image' => '',
				'rl_icon' => '',
				'rl_date_created' => $this->dbw->timestamp(),
				'rl_date_updated' => $this->dbw->timestamp(),
				'rl_deleted' => 0,
			],
			__METHOD__
		);
		$this->dbw->insert(
			'reading_list_sortkey',
			[
				'rls_rl_id' => $this->dbw->insertId(),
				'rls_index' => 0,
			]
		);
		$this->logger->info( 'Set up for user {user}', [ 'user' => $this->userId ] );
	}

	/**
	 * Remove all data for the given user.
	 * No other operation can be performed for the user except setup.
	 * @return void
	 * @throws ReadingListRepositoryException
	 */
	public function teardownForUser() {
		$this->assertUser();
		if ( !$this->isSetupForUser() ) {
			throw new ReadingListRepositoryException( 'readinglists-db-error-not-set-up' );
		}
		$this->dbw->delete(
			'reading_list',
			[ 'rl_user_id' => $this->userId ],
			__METHOD__
		);
		$this->dbw->delete(
			'reading_list_entry',
			[ 'rle_user_id' => $this->userId ],
			__METHOD__
		);
		$this->logger->info( 'Tore down for user {user}', [ 'user' => $this->userId ] );
	}

	/**
	 * Check whether reading lists have been set up for the given user (ie. setupForUser() was
	 * called with $userId and teardownForUser() was not called with the same id afterwards).
	 * Optionally also lock the DB row for the default list of the user (will be used as a
	 * semaphore).
	 * @param int $flags IDBAccessObject flags
	 * @throws ReadingListRepositoryException
	 * @return bool
	 */
	public function isSetupForUser( $flags = 0 ) {
		$this->assertUser();
		list( $index, $options ) = DBAccessObjectUtils::getDBOptions( $flags );
		$db = ( $index === DB_MASTER ) ? $this->dbw : $this->dbr;
		$options = array_merge( $options, [ 'LIMIT' => 1 ] );
		$res = $db->select(
			'reading_list',
			'1',
			[
				'rl_user_id' => $this->userId,
				// It would probably be fine to just check if the user has lists at all,
				// but this way is extra safe against races as setup is the only operation that
				// creates a default list.
				'rl_is_default' => 1,
			],
			__METHOD__,
			$options
		);
		return (bool)$res->numRows();
	}

	// list CRUD

	/**
	 * Create a new list.
	 * @param string $name
	 * @param string $description
	 * @param string $color
	 * @param string $image
	 * @param string $icon
	 * @return int The ID of the new list
	 * @throws ReadingListRepositoryException
	 */
	public function addList( $name, $description = '', $color = '', $image = '', $icon = '' ) {
		$this->assertUser();
		if ( !$this->isSetupForUser( self::READ_LOCKING ) ) {
			throw new ReadingListRepositoryException( 'readinglists-db-error-not-set-up' );
		}
		$this->dbw->insert(
			'reading_list',
			[
				'rl_user_id' => $this->userId,
				'rl_is_default' => 0,
				'rl_name' => $name,
				'rl_description' => $description,
				'rl_color' => $color,
				'rl_image' => $image,
				'rl_icon' => $icon,
				'rl_date_created' => $this->dbw->timestamp(),
				'rl_date_updated' => $this->dbw->timestamp(),
				'rl_deleted' => 0,
			],
			__METHOD__
		);
		$this->logger->info( 'Added list {list} for user {user}', [
			'list' => $this->dbw->insertId(),
			'user' => $this->userId,
		] );
		return $this->dbw->insertId();
	}

	/**
	 * Get all lists of the user.
	 * @param int $limit
	 * @param int $offset
	 * @return IResultWrapper<ReadingListRow>
	 * @throws ReadingListRepositoryException
	 */
	public function getAllLists( $limit = 1000, $offset = 0 ) {
		// TODO sortkeys?
		$this->assertUser();

		$res = $this->dbr->select(
			[ 'reading_list', 'reading_list_sortkey' ],
			$this->getListFields(),
			[
				'rl_user_id' => $this->userId,
				'rl_deleted' => 0,
			],
			__METHOD__,
			[
				'LIMIT' => $limit,
				'OFFSET' => $offset,
				'ORDER BY' => 'rls_index',
			],
			[
				'reading_list_sortkey' => [ 'LEFT JOIN', 'rl_id = rls_rl_id' ],
			]
		);

		if ( $res->numRows() === 0 && !$this->isSetupForUser() ) {
			throw new ReadingListRepositoryException( 'readinglists-db-error-not-set-up' );
		}
		return $res;
	}

	/**
	 * Update a list.
	 * Fields for which the parameter was set to null will preserve their original value.
	 * @param int $id
	 * @param string|null $name
	 * @param string|null $description
	 * @param string|null $color
	 * @param string|null $image
	 * @param string|null $icon
	 * @return void
	 * @throws ReadingListRepositoryException
	 * @throws LogicException
	 */
	public function updateList(
		$id, $name = null, $description = null, $color = null, $image = null, $icon = null
	) {
		$this->assertUser();
		$this->selectValidList( $id, self::READ_LOCKING );

		$data = array_filter( [
			'rl_name' => $name,
			'rl_description' => $description,
			'rl_color' => $color,
			'rl_image' => $image,
			'rl_icon' => $icon,
			'rl_date_updated' => $this->dbw->timestamp(),
		], function ( $field ) {
			return $field !== null;
		} );

		$this->dbw->update(
			'reading_list',
			$data,
			[ 'rl_id' => $id ]
		);
		if ( !$this->dbw->affectedRows() ) {
			throw new LogicException( 'updateList failed for unknown reason' );
		}
	}

	/**
	 * Delete a list.
	 * @param int $id
	 * @return void
	 * @throws ReadingListRepositoryException
	 */
	public function deleteList( $id ) {
		$this->assertUser();
		$row = $this->selectValidList( $id, self::READ_LOCKING );
		if ( $row->rl_is_default ) {
			throw new ReadingListRepositoryException( 'readinglists-db-error-cannot-delete-default-list' );
		}

		$this->dbw->update(
			'reading_list',
			[
				'rl_deleted' => 1,
				'rl_date_updated' => $this->dbw->timestamp(),
			],
			[ 'rl_id' => $id ]
		);
		if ( !$this->dbw->affectedRows() ) {
			throw new LogicException( 'deleteList failed for unknown reason' );
		}

		$this->logger->info( 'Deleted list {list} for user {user}', [
			'list' => $id,
			'user' => $this->userId,
		] );
	}

	// list entry CRUD

	/**
	 * Add a new page to a list.
	 * @param int $id List ID
	 * @param string $project Project identifier (typically a domain name)
	 * @param string $title Page title (in localized prefixed DBkey format)
	 * @return int The ID of the new list entry
	 * @throws ReadingListRepositoryException
	 */
	public function addListEntry( $id, $project, $title ) {
		$this->assertUser();
		$this->selectValidList( $id, self::READ_LOCKING );

		// due to the combination of soft deletion + unique constraint on
		// rle_rl_id + rle_project + rle_title, recreation needs special handling
		/** @var ReadingListEntryRow $row */
		$row = $this->dbw->selectRow(
			'reading_list_entry',
			[ 'rle_id', 'rle_deleted' ],
			[
				'rle_rl_id' => $id,
				'rle_project' => $project,
				'rle_title' => $title,
			],
			__METHOD__,
			// lock the row to avoid race conditions with purgeOldDeleted() in the update case
			[ 'FOR UPDATE' ]
		);
		if ( $row === false ) {
			$this->dbw->insert(
				'reading_list_entry',
				[
					'rle_rl_id' => $id,
					'rle_user_id' => $this->userId,
					'rle_project' => $project,
					'rle_title' => $title,
					'rle_date_created' => $this->dbw->timestamp(),
					'rle_date_updated' => $this->dbw->timestamp(),
					'rle_deleted' => 0,
				]
			);
		} elseif ( $row->rle_deleted ) {
			$this->dbw->update(
				'reading_list_entry',
				[
					'rle_date_created' => $this->dbw->timestamp(),
					'rle_date_updated' => $this->dbw->timestamp(),
					'rle_deleted' => 0,
				],
				[
					'rle_id' => $row->rle_id,
				]
			);
		} else {
			throw new ReadingListRepositoryException( 'readinglists-db-error-duplicate-page' );
		}
		if ( !$this->dbw->affectedRows() ) {
			throw new LogicException( 'addListEntry failed for unknown reason' );
		}

		$insertId = $row ? (int)$row->rle_id : $this->dbw->insertId();
		$this->logger->info( 'Added entry {entry} for user {user}', [
			'entry' => $insertId,
			'user' => $this->userId,
			'recreated' => (bool)$row,
		] );
		return $insertId;
	}

	/**
	 * Get the entries of one or more lists.
	 * @param array $ids List ids
	 * @param int $limit
	 * @param int $offset
	 * @return IResultWrapper<ReadingListEntryRow>
	 * @throws ReadingListRepositoryException
	 */
	public function getListEntries( array $ids, $limit = 1000, $offset = 0 ) {
		// TODO sortkeys?
		$this->assertUser();
		if ( !$ids ) {
			throw new ReadingListRepositoryException( 'readinglists-db-error-empty-list-ids' );
		}

		// sanity check for nice error messages
		$res = $this->dbr->select(
			'reading_list',
			[ 'rl_id', 'rl_user_id', 'rl_deleted' ],
			[ 'rl_id' => $ids ]
		);
		$filtered = [];
		foreach ( $res as $row ) {
			/** @var ReadingListRow $row */
			if ( $row->rl_user_id != $this->userId ) {
				throw new ReadingListRepositoryException(
					'readinglists-db-error-not-own-list', [ $row->rl_id ] );
			} elseif ( $row->rl_deleted ) {
				throw new ReadingListRepositoryException(
					'readinglists-db-error-list-deleted', [ $row->rl_id ] );
			}
			$filtered[] = $row->rl_id;
		}
		$missing = array_diff( $ids, $filtered );
		if ( $missing ) {
			throw new ReadingListRepositoryException(
				'readinglists-db-error-no-such-list', [ reset( $missing ) ] );
		}

		$res = $this->dbr->select(
			[ 'reading_list_entry', 'reading_list_entry_sortkey' ],
			$this->getListEntryFields(),
			[
				'rle_rl_id' => $ids,
				'rle_user_id' => $this->userId,
				'rle_deleted' => 0,
			],
			__METHOD__,
			[
				'LIMIT' => $limit,
				'OFFSET' => $offset,
				'ORDER BY' => [ 'rle_rl_id', 'rles_index' ],
			],
			[
				'reading_list_entry_sortkey' => [ 'LEFT JOIN', 'rle_id = rles_rle_id' ],
			]
		);

		return $res;
	}

	/**
	 * Delete a page from a list.
	 * @param int $id
	 * @return void
	 * @throws ReadingListRepositoryException
	 */
	public function deleteListEntry( $id ) {
		$this->assertUser();

		/** @var ReadingListRow|ReadingListEntryRow $row */
		$row = $this->dbw->selectRow(
			[ 'reading_list', 'reading_list_entry' ],
			[ 'rl_id', 'rl_user_id', 'rl_deleted', 'rle_deleted' ],
			[
				'rle_id' => $id,
				'rl_id = rle_rl_id',
			],
			__METHOD__,
			[ 'FOR UPDATE' ]
		);
		if ( !$row ) {
			throw new ReadingListRepositoryException( 'readinglists-db-error-no-such-list-entry', [ $id ] );
		} elseif ( $row->rl_user_id != $this->userId ) {
			throw new ReadingListRepositoryException( 'readinglists-db-error-not-own-list-entry', [ $id ] );
		} elseif ( $row->rl_deleted ) {
			throw new ReadingListRepositoryException(
				'readinglists-db-error-list-deleted', [ $row->rl_id ] );
		} elseif ( $row->rle_deleted ) {
			throw new ReadingListRepositoryException( 'readinglists-db-error-list-entry-deleted', [ $id ] );
		}

		$this->dbw->update(
			'reading_list_entry',
			[
				'rle_deleted' => 1,
				'rle_date_updated' => $this->dbw->timestamp(),
			],
			[ 'rle_id' => $id ]
		);
		if ( !$this->dbw->affectedRows() ) {
			throw new LogicException( 'deleteListEntry failed for unknown reason' );
		}

		$this->logger->info( 'Deleted entry {entry} for user {user}', [
			'entry' => $id,
			'user' => $this->userId,
		] );
	}

	// sorting

	/**
	 * Return the ids of all lists in order.
	 * @return int[]
	 * @throws ReadingListRepositoryException
	 */
	public function getListOrder() {
		$this->assertUser();

		$ids = $this->dbr->selectFieldValues(
			[ 'reading_list', 'reading_list_sortkey' ],
			'rl_id',
			[
				'rl_user_id' => $this->userId,
				'rl_deleted' => 0,
			],
			__METHOD__,
			[
				'ORDER BY' => 'rls_index',
			],
			[
				'reading_list_sortkey' => [ 'LEFT JOIN', 'rl_id = rls_rl_id' ],
			]
		);
		if ( !$ids ) {
			throw new ReadingListRepositoryException( 'readinglists-db-error-not-set-up' );
		}
		return array_map( 'intval', $ids );
	}

	/**
	 * Update the order of lists.
	 * @param array $order A list of all reading list ids, in the desired order.
	 * @return void
	 * @throws ReadingListRepositoryException
	 */
	public function setListOrder( array $order ) {
		$this->assertUser();
		if ( !$order ) {
			throw new ReadingListRepositoryException( 'readinglists-db-error-empty-order' );
		}
		if ( !$this->isSetupForUser( self::READ_LOCKING ) ) {
			throw new ReadingListRepositoryException( 'readinglists-db-error-not-set-up' );
		}

		// Make sure the set of IDs match the actual lists.
		$ids = $this->dbw->selectFieldValues(
			'reading_list',
			'rl_id',
			[
				'rl_user_id' => $this->userId,
				'rl_deleted' => 0,
			]
		) ?: [];
		$nonExistent = array_diff( $order, $ids );
		if ( $nonExistent ) {
			/** @var ReadingListRow $row */
			$row = $this->dbw->selectRow(
				'reading_list',
				[ 'rl_id', 'rl_user_id', 'rl_deleted' ],
				[ 'rl_id' => reset( $nonExistent ) ]
			);
			if ( !$row ) {
				throw new ReadingListRepositoryException( 'readinglists-db-error-no-such-list',
					[ reset( $nonExistent ) ] );
			} elseif ( $row->rl_user_id != $this->userId ) {
				throw new ReadingListRepositoryException(
					'readinglists-db-error-not-own-list', [ $row->rl_id ] );
			} elseif ( $row->rl_deleted ) {
				throw new ReadingListRepositoryException(
					'readinglists-db-error-list-deleted', [ $row->rl_id ] );
			} else {
				throw new LogicException( 'setListOrder failed for unknown reason' );
			}
		}
		$missing = array_diff( $ids, $order );
		if ( $missing ) {
			throw new ReadingListRepositoryException(
				'readinglists-db-error-missing-list', [ reset( $missing ) ] );
		}

		$this->dbw->deleteJoin(
			'reading_list_sortkey',
			'reading_list',
			'rls_rl_id',
			'rl_id',
			[ 'rl_user_id' => $this->userId ]
		);
		$this->dbw->insert(
			'reading_list_sortkey',
			array_map( function ( $id, $index ) {
				return [
						'rls_rl_id' => $id,
						'rls_index' => $index,
				];
			}, array_values( $order ), array_keys( $order ) )
		);

		// Touch timestamp of default list so that syncing devices know to update the list order.
		$this->dbw->update( 'reading_list',
			[ 'rl_date_updated' => $this->dbw->timestamp() ],
			[
				'rl_user_id' => $this->userId,
				'rl_is_default' => 1,
			]
		);
	}

	/**
	 * Return the ids of all entries of the list in order.
	 * @param int $id List ID
	 * @return int[]
	 * @throws ReadingListRepositoryException
	 */
	public function getListEntryOrder( $id ) {
		$this->assertUser();
		$this->selectValidList( $id );

		$ids = $this->dbr->selectFieldValues(
			[ 'reading_list_entry', 'reading_list_entry_sortkey' ],
			'rle_id',
			[
				'rle_rl_id' => $id,
				'rle_deleted' => 0,
			],
			__METHOD__,
			[
				'ORDER BY' => 'rles_index',
			],
			[
				'reading_list_entry_sortkey' => [ 'LEFT JOIN', 'rle_id = rles_rle_id' ],
			]
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Update the order of the entries of a list.
	 * @param int $id List ID
	 * @param array $order A list of IDs for all entries of the list, in the desired order.
	 * @return void
	 * @throws ReadingListRepositoryException
	 */
	public function setListEntryOrder( $id, array $order ) {
		$this->assertUser();
		$this->selectValidList( $id, self::READ_LOCKING );
		if ( !$order ) {
			throw new ReadingListRepositoryException( 'readinglists-db-error-empty-order' );
		}

		// Make sure the set of IDs match the actual list entries.
		$ids = $this->dbw->selectFieldValues(
			'reading_list_entry',
			'rle_id',
			[
				'rle_rl_id' => $id,
				'rle_deleted' => 0,
			]
		) ?: [];
		$nonExistent = array_diff( $order, $ids );
		if ( $nonExistent ) {
			/** @var ReadingListEntryRow $row */
			$row = $this->dbw->selectRow(
				'reading_list_entry',
				[ 'rle_id', 'rle_rl_id', 'rle_user_id', 'rle_deleted' ],
				[ 'rle_id' => reset( $nonExistent ) ]
			);
			if ( !$row ) {
				throw new ReadingListRepositoryException( 'readinglists-db-error-no-such-list-entry',
					[ reset( $nonExistent ) ] );
			} elseif ( $row->rle_user_id != $this->userId ) {
				throw new ReadingListRepositoryException(
					'readinglists-db-error-not-own-list-entry', [ $row->rle_id ] );
			} elseif ( $row->rle_rl_id != $id ) {
				throw new ReadingListRepositoryException(
					'readinglists-db-error-entry-not-in-list', [ $row->rle_id ] );
			} elseif ( $row->rle_deleted ) {
				throw new ReadingListRepositoryException(
					'readinglists-db-error-list-entry-deleted', [ $row->rle_id ] );
			} else {
				throw new LogicException( 'setListEntryOrder failed for unknown reason' );
			}
		}
		$missing = array_diff( $ids, $order );
		if ( $missing ) {
			throw new ReadingListRepositoryException(
				'readinglists-db-error-missing-list-entry', [ reset( $missing ) ] );
		}

		$this->dbw->deleteJoin(
			'reading_list_entry_sortkey',
			'reading_list_entry',
			'rles_rle_id',
			'rle_id',
			[ 'rle_rl_id' => $id ]
		);
		$this->dbw->insert(
			'reading_list_entry_sortkey',
			array_map( function ( $id, $index ) {
				return [
					'rles_rle_id' => $id,
					'rles_index' => $index,
				];
			}, array_values( $order ), array_keys( $order ) )
		);

		// Touch timestamp of the list so that syncing devices know to update the list order.
		$this->dbw->update( 'reading_list',
			[ 'rl_date_updated' => $this->dbw->timestamp() ],
			[ 'rl_id' => $id ]
		);
	}

	/**
	 * Purge sortkeys whose lists have been deleted.
	 * Unlike most other methods in the class, this one ignores user IDs.
	 * @return void
	 */
	public function purgeSortkeys() {
		// purge list sortkeys
		while ( true ) {
			$ids = $this->dbw->selectFieldValues(
				[ 'reading_list_sortkey', 'reading_list' ],
				'rls_rl_id',
				[
					'rl_id' => null,
				],
				__METHOD__,
				[
					'GROUP BY' => 'rls_rl_id',
					'LIMIT' => 1000,
				],
				[
					'reading_list' => [ 'LEFT JOIN', 'rl_id = rls_rl_id' ],
				]
			);
			if ( !$ids ) {
				break;
			}
			$this->dbw->delete(
				'reading_list_sortkey',
				[
					'rls_rl_id' => $ids,
				]
			);
			$this->logger->debug( 'Purged {num} list sortkeys', [ 'num' => $this->dbw->affectedRows() ] );
			$this->lbFactory->waitForReplication();
		}

		// purge entry sortkeys
		while ( true ) {
			$ids = $this->dbw->selectFieldValues(
				[ 'reading_list_entry_sortkey', 'reading_list_entry' ],
				'rles_rle_id',
				[
					'rle_id' => null,
				],
				__METHOD__,
				[
					'GROUP BY' => 'rles_rle_id',
					'LIMIT' => 1000,
				],
				[
					'reading_list_entry' => [ 'LEFT JOIN', 'rle_id = rles_rle_id' ],
				]
			);
			if ( !$ids ) {
				break;
			}
			$this->dbw->delete(
				'reading_list_entry_sortkey',
				[
					'rles_rle_id' => $ids,
				]
			);
			$this->logger->debug( 'Purged {num} entry sortkeys', [ 'num' => $this->dbw->affectedRows() ] );
			$this->lbFactory->waitForReplication();
		}
	}

	// sync

	/**
	 * Get lists that have changed since a given date.
	 * Unlike other methods this returns deleted lists as well. Only changes to list metadata
	 * (including deletion) are considered, not changes to list entries.
	 * @param string $date The cutoff date in TS_MW format
	 * @param int $limit
	 * @param int $offset
	 * @throws ReadingListRepositoryException
	 * @return IResultWrapper<ReadingListRow>
	 */
	public function getListsByDateUpdated( $date, $limit = 1000, $offset = 0 ) {
		$this->assertUser();
		$res = $this->dbr->select(
			'reading_list',
			$this->getListFields(),
			[
				'rl_user_id' => $this->userId,
				'rl_date_updated > ' . $this->dbr->addQuotes( $this->dbr->timestamp( $date ) ),
			],
			__METHOD__,
			[
				'LIMIT' => $limit,
				'OFFSET' => $offset,
				'ORDER BY' => 'rl_id',
			]
		);
		return $res;
	}

	/**
	 * Get list entries that have changed since a given date.
	 * Unlike other methods this returns deleted entries as well (but not entries inside deleted
	 * lists).
	 * @param string $date The cutoff date in TS_MW format
	 * @param int $limit
	 * @param int $offset
	 * @throws ReadingListRepositoryException
	 * @return IResultWrapper<ReadingListEntryRow>
	 */
	public function getListEntriesByDateUpdated( $date, $limit = 1000, $offset = 0 ) {
		$this->assertUser();
		$res = $this->dbr->select(
			[ 'reading_list', 'reading_list_entry' ],
			$this->getListEntryFields(),
			[
				'rl_id = rle_rl_id',
				'rl_user_id' => $this->userId,
				'rl_deleted' => 0,
				'rle_date_updated > ' . $this->dbr->addQuotes( $this->dbr->timestamp( $date ) ),
			],
			__METHOD__,
			[
				'LIMIT' => $limit,
				'OFFSET' => $offset,
				'ORDER BY' => [ 'rle_rl_id', 'rle_id' ],
			]
		);
		return $res;
	}

	/**
	 * Purge all deleted lists/entries older than $before.
	 * Unlike most other methods in the class, this one ignores user IDs.
	 * @param string $before A timestamp in TS_MW format.
	 * @return void
	 */
	public function purgeOldDeleted( $before ) {
		// purge deleted lists and their entries
		while ( true ) {
			$ids = $this->dbw->selectFieldValues(
				'reading_list',
				'rl_id',
				[
					'rl_deleted' => 1,
					'rl_date_updated < ' . $this->dbw->addQuotes( $this->dbw->timestamp( $before ) ),
				],
				__METHOD__,
				[ 'LIMIT' => 1000 ]
			);
			if ( !$ids ) {
				break;
			}
			$this->dbw->delete(
				'reading_list_entry',
				[ 'rle_rl_id' => $ids ]
			);
			$this->dbw->delete(
				'reading_list',
				[ 'rl_id' => $ids ]
			);
			$this->logger->debug( 'Purged {num} deleted lists', [ 'num' => $this->dbw->affectedRows() ] );
			$this->lbFactory->waitForReplication();
		}

		// purge deleted list entries
		while ( true ) {
			$ids = $this->dbw->selectFieldValues(
				'reading_list_entry',
				'rle_id',
				[
					'rle_deleted' => 1,
					'rle_date_updated < ' . $this->dbw->addQuotes( $this->dbw->timestamp( $before ) ),
				],
				__METHOD__,
				[ 'LIMIT' => 1000 ]
			);
			if ( !$ids ) {
				break;
			}
			$this->dbw->delete(
				'reading_list_entry',
				[ 'rle_id' => $ids ]
			);
			$this->logger->debug( 'Purged {num} deleted entries', [ 'num' => $this->dbw->affectedRows() ] );
			$this->lbFactory->waitForReplication();
		}
	}

	// membership

	/**
	 * Return all lists which contain a given page.
	 * @param string $project Project identifier (typically a domain name)
	 * @param string $title Page title (in localized prefixed DBkey format)
	 * @param int $limit
	 * @param int $offset
	 * @throws ReadingListRepositoryException
	 * @return IResultWrapper<ReadingListRow>
	 */
	public function getListsByPage( $project, $title, $limit = 1000, $offset = 0 ) {
		$this->assertUser();
		$res = $this->dbr->select(
			[ 'reading_list', 'reading_list_entry' ],
			$this->getListFields(),
			[
				'rl_id = rle_rl_id',
				'rl_user_id' => $this->userId,
				'rle_project' => $project,
				'rle_title' => $title,
				'rl_deleted' => 0,
				'rle_deleted' => 0,
			],
			__METHOD__,
			[
				'LIMIT' => $limit,
				'OFFSET' => $offset,
				'GROUP BY' => $this->getListFields(),
				'ORDER BY' => 'rl_id',
			]
		);
		return $res;
	}

	// helper methods

	/**
	 * Get this list of reading_list fields that normally need to be selected.
	 * @return array
	 */
	private function getListFields() {
		return [
			'rl_id',
			// returning rl_user_id is pointless as lists are only available to the owner
			'rl_is_default',
			'rl_name',
			'rl_description',
			'rl_color',
			'rl_image',
			'rl_icon',
			'rl_date_created',
			'rl_date_updated',
			'rl_deleted',
		];
	}

	/**
	 * Get this list of reading_list_entry fields that normally need to be selected.
	 * @return array
	 */
	private function getListEntryFields() {
		return [
			'rle_id',
			'rle_rl_id',
			// returning rle_user_id is pointless as lists are only available to the owner
			'rle_project',
			'rle_title',
			'rle_date_created',
			'rle_date_updated',
			'rle_deleted',
		];
	}

	/**
	 * Require the user to be specified.
	 * @throws ReadingListRepositoryException
	 */
	private function assertUser() {
		if ( !is_int( $this->userId ) ) {
			throw new ReadingListRepositoryException( 'readinglists-db-error-user-required' );
		}
	}

	/**
	 * Get list data, and optionally lock the list.
	 * List must exist, belong to the current user and not be deleted.
	 * @param int $id List id
	 * @param int $flags IDBAccessObject flags
	 * @return ReadingListRow
	 * @throws ReadingListRepositoryException
	 */
	private function selectValidList( $id, $flags = 0 ) {
		$this->assertUser();
		list( $index, $options ) = DBAccessObjectUtils::getDBOptions( $flags );
		$db = ( $index === DB_MASTER ) ? $this->dbw : $this->dbr;
		/** @var ReadingListRow $row */
		$row = $db->selectRow(
			'reading_list',
			array_merge( $this->getListFields(), [ 'rl_user_id' ] ),
			[ 'rl_id' => $id ],
			__METHOD__,
			$options
		);
		if ( !$row ) {
			throw new ReadingListRepositoryException( 'readinglists-db-error-no-such-list', [ $id ] );
		} elseif ( $row->rl_user_id != $this->userId ) {
			throw new ReadingListRepositoryException( 'readinglists-db-error-not-own-list', [ $id ] );
		} elseif ( $row->rl_deleted ) {
			throw new ReadingListRepositoryException( 'readinglists-db-error-list-deleted', [ $id ] );
		}
		return $row;
	}

}
