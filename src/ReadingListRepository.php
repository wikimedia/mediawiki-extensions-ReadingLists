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
use Wikimedia\Rdbms\FakeResultWrapper;
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

	/** Sort lists / entries alphabetically by name / title. */
	const SORT_BY_NAME = 'name';
	/** Sort lists / entries chronologically by last updated timestamp. */
	const SORT_BY_UPDATED = 'updated';
	/** Sort ascendingly (first letter / oldest date first). */
	const SORT_DIR_ASC = 'asc';
	/** Sort descendingly (last letter / newest date first). */
	const SORT_DIR_DESC = 'desc';

	/** @var array Database field lengths in bytes (only for the string types). */
	public static $fieldLength = [
		'rl_name' => 255,
		'rl_description' => 767,
		'rlp_project' => 255,
		'rle_title' => 383,
	];

	/** @var int|null Max allowed lists per user */
	private $listLimit;

	/** @var int|null Max allowed entries lists per list */
	private $entryLimit;

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
		$this->userId = (int)$userId ?: null;
		$this->dbw = $dbw;
		$this->dbr = $dbr;
		$this->lbFactory = $lbFactory;
		$this->logger = new NullLogger();
	}

	/**
	 * @param int|null $listLimit
	 * @param int|null $entryLimit
	 */
	public function setLimits( $listLimit, $entryLimit ) {
		$this->listLimit = $listLimit;
		$this->entryLimit = $entryLimit;
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
				'rl_date_created' => $this->dbw->timestamp(),
				'rl_date_updated' => $this->dbw->timestamp(),
				'rl_deleted' => 0,
			],
			__METHOD__
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
	 * @return int The ID of the new list
	 * @throws ReadingListRepositoryException
	 */
	public function addList( $name, $description = '' ) {
		$this->assertUser();
		$this->assertFieldLength( 'rl_name', $name );
		$this->assertFieldLength( 'rl_description', $description );
		if ( !$this->isSetupForUser( self::READ_LOCKING ) ) {
			throw new ReadingListRepositoryException( 'readinglists-db-error-not-set-up' );
		}
		if ( $this->listLimit && $this->getListCount( self::READ_LATEST ) >= $this->listLimit ) {
			throw new ReadingListRepositoryException( 'readinglists-db-error-list-limit',
				[ $this->listLimit ] );
		}

		$this->dbw->insert(
			'reading_list',
			[
				'rl_user_id' => $this->userId,
				'rl_is_default' => 0,
				'rl_name' => $name,
				'rl_description' => $description,
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
	 * @param string $sortBy One of the SORT_BY_* constants.
	 * @param string $sortDir One of the SORT_DIR_* constants.
	 * @param int $limit
	 * @param array|null $from DB position to continue from (or null to start at the beginning/end).
	 *   When sorting by name, this should be the name and id of a list; when sorting by update time,
	 *   the updated timestamp (in some form accepted by MWTimestamp) and the id.
	 * @return IResultWrapper <ReadingListRow>
	 * @throws ReadingListRepositoryException
	 */
	public function getAllLists( $sortBy, $sortDir, $limit = 1000, array $from = null ) {
		$this->assertUser();
		list( $conditions, $options ) = $this->processSort( 'rl', $sortBy, $sortDir, $limit, $from );

		$res = $this->dbr->select(
			'reading_list',
			$this->getListFields(),
			array_merge( [
				'rl_user_id' => $this->userId,
				'rl_deleted' => 0,
			], $conditions ),
			__METHOD__,
			$options
		);

		if (
			$res->numRows() === 0
			&& !$this->isSetupForUser()
			&& !$this->isSetupForUser( self::READ_LATEST )
		) {
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
	 * @return void
	 * @throws ReadingListRepositoryException
	 * @throws LogicException
	 */
	public function updateList( $id, $name = null, $description = null ) {
		$this->assertUser();
		$this->assertFieldLength( 'rl_name', $name );
		$this->assertFieldLength( 'rl_description', $description );
		$row = $this->selectValidList( $id, self::READ_LOCKING );
		if ( $row->rl_is_default ) {
			throw new ReadingListRepositoryException( 'readinglists-db-error-cannot-update-default-list' );
		}

		$data = array_filter( [
			'rl_name' => $name,
			'rl_description' => $description,
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
	 * @param string $title Page title (treated as a plain string with no normalization;
	 *   in localized namespace-prefixed format with spaces is recommended)
	 * @return int The ID of the new list entry
	 * @throws ReadingListRepositoryException
	 */
	public function addListEntry( $id, $project, $title ) {
		$this->assertUser();
		$this->assertFieldLength( 'rlp_project', $project );
		$this->assertFieldLength( 'rle_title', $title );
		$this->selectValidList( $id, self::READ_LOCKING );
		if ( $this->entryLimit && $this->getEntryCount( $id, self::READ_LATEST ) >= $this->entryLimit ) {
			throw new ReadingListRepositoryException( 'readinglists-db-error-entry-limit',
				[ $id, $this->entryLimit ] );
		}

		$projectId = $this->getProjectId( $project );
		if ( !$projectId ) {
			throw new ReadingListRepositoryException( 'readinglists-db-error-no-such-project',
				[ $project ] );
		}

		// due to the combination of soft deletion + unique constraint on
		// rle_rl_id + rle_rlp_id + rle_title, recreation needs special handling
		/** @var ReadingListEntryRow $row */
		$row = $this->dbw->selectRow(
			'reading_list_entry',
			[ 'rle_id', 'rle_deleted' ],
			[
				'rle_rl_id' => $id,
				'rle_rlp_id' => $projectId,
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
					'rle_rlp_id' => $projectId,
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
	 * @param string $sortBy One of the SORT_BY_* constants.
	 * @param string $sortDir One of the SORT_DIR_* constants.
	 * @param int $limit
	 * @param array|null $from DB position to continue from (or null to start at the beginning/end).
	 *   When sorting by name, this should be the name and id of a list; when sorting by update time,
	 *   the updated timestamp (in some form accepted by MWTimestamp) and the id.
	 * @return IResultWrapper<ReadingListEntryRow>
	 * @throws ReadingListRepositoryException
	 */
	public function getListEntries(
		array $ids, $sortBy, $sortDir, $limit = 1000, array $from = null
	) {
		$this->assertUser();
		if ( !$ids ) {
			throw new ReadingListRepositoryException( 'readinglists-db-error-empty-list-ids' );
		}
		list( $conditions, $options ) = $this->processSort( 'rle', $sortBy, $sortDir, $limit, $from );

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
			[ 'reading_list_entry', 'reading_list_project' ],
			$this->getListEntryFields(),
			array_merge( [
				'rle_rlp_id = rlp_id',
				'rle_rl_id' => $ids,
				'rle_user_id' => $this->userId,
				'rle_deleted' => 0,
			], $conditions ),
			__METHOD__,
			$options
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

	// sync

	/**
	 * Get lists that have changed since a given date.
	 * Unlike other methods this returns deleted lists as well. Only changes to list metadata
	 * (including deletion) are considered, not changes to list entries.
	 * @param string $date The cutoff date in TS_MW format
	 * @param string $sortBy One of the SORT_BY_* constants.
	 * @param string $sortDir One of the SORT_DIR_* constants.
	 * @param int $limit
	 * @param array|null $from DB position to continue from (or null to start at the beginning/end).
	 *   When sorting by name, this should be the name and id of a list; when sorting by update time,
	 *   the updated timestamp (in some form accepted by MWTimestamp) and the id.
	 * @throws ReadingListRepositoryException
	 * @return IResultWrapper<ReadingListRow>
	 */
	public function getListsByDateUpdated( $date, $sortBy = self::SORT_BY_UPDATED,
		$sortDir = self::SORT_DIR_ASC, $limit = 1000, array $from = null
	) {
		$this->assertUser();
		list( $conditions, $options ) = $this->processSort( 'rl', $sortBy, $sortDir, $limit, $from );
		$res = $this->dbr->select(
			'reading_list',
			$this->getListFields(),
			array_merge( [
				'rl_user_id' => $this->userId,
				'rl_date_updated > ' . $this->dbr->addQuotes( $this->dbr->timestamp( $date ) ),
			], $conditions ),
			__METHOD__,
			$options
		);

		if (
			$res->numRows() === 0
			&& !$this->isSetupForUser()
			&& !$this->isSetupForUser( self::READ_LATEST )
		) {
			throw new ReadingListRepositoryException( 'readinglists-db-error-not-set-up' );
		}
		return $res;
	}

	/**
	 * Get list entries that have changed since a given date.
	 * Unlike other methods this returns deleted entries as well (but not entries inside deleted
	 * lists).
	 * @param string $date The cutoff date in TS_MW format
	 * @param string $sortDir One of the SORT_DIR_* constants.
	 * @param int $limit
	 * @param array|null $from DB position to continue from (or null to start at the beginning/end).
	 *   Should contain the updated timestamp (in some form accepted by MWTimestamp) and the id.
	 * @throws ReadingListRepositoryException
	 * @return IResultWrapper<ReadingListEntryRow>
	 */
	public function getListEntriesByDateUpdated(
		$date, $sortDir = self::SORT_DIR_ASC, $limit = 1000, array $from = null
	) {
		$this->assertUser();
		// Always sort by last updated; there is no supporting index for sorting by name.
		list( $conditions, $options ) = $this->processSort( 'rle', self::SORT_BY_UPDATED,
			$sortDir, $limit, $from );
		$res = $this->dbr->select(
			[ 'reading_list', 'reading_list_entry', 'reading_list_project' ],
			$this->getListEntryFields(),
			array_merge( [
				'rl_id = rle_rl_id',
				'rle_rlp_id = rlp_id',
				'rle_user_id' => $this->userId,
				'rl_deleted' => 0,
				'rle_date_updated > ' . $this->dbr->addQuotes( $this->dbr->timestamp( $date ) ),
			], $conditions ),
			__METHOD__,
			$options
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
	 * @param int|null $from List ID to continue from (or null to start at the beginning/end).
	 *
	 * @throws ReadingListRepositoryException
	 * @return IResultWrapper<ReadingListRow>
	 */
	public function getListsByPage( $project, $title, $limit = 1000, $from = null ) {
		$this->assertUser();
		$projectId = $this->getProjectId( $project );
		if ( !$projectId ) {
			return new FakeResultWrapper( [] );
		}

		$conditions = [
			'rl_id = rle_rl_id',
			'rle_user_id' => $this->userId,
			'rle_rlp_id' => $projectId,
			'rle_title' => $title,
			'rl_deleted' => 0,
			'rle_deleted' => 0,
		];
		if ( $from !== null ) {
			$conditions[] = 'rle_rl_id >= ' . (int)$from;
		}
		$res = $this->dbr->select(
			[ 'reading_list', 'reading_list_entry' ],
			$this->getListFields(),
			$conditions,
			__METHOD__,
			[
				// Grouping by rle_rl_id can be done efficiently with the same index used for
				// the conditions. All other fields are functionally dependent on it; MySQL 5.7.5+
				// can detect that ( https://dev.mysql.com/doc/refman/5.7/en/group-by-handling.html );
				// MariaDB needs the other fields for ONLY_FULL_GROUP_BY compliance, but they don't
				// seem to negatively affect the query plan.
				'GROUP BY' => array_merge( [ 'rle_rl_id' ], $this->getListFields() ),
				'ORDER BY' => 'rle_rl_id ASC',
				'LIMIT' => (int)$limit,
			]
		);

		if (
			$res->numRows() === 0
			&& !$this->isSetupForUser()
			&& !$this->isSetupForUser( self::READ_LATEST )
		) {
			throw new ReadingListRepositoryException( 'readinglists-db-error-not-set-up' );
		}
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
			'rl_date_created',
			'rl_date_updated',
			'rl_deleted',
		];
	}

	/**
	 * Get this list of reading_list_entry fields that normally need to be selected.
	 * Can only be used with queries that join on reading_list_project.
	 * @return array
	 */
	private function getListEntryFields() {
		return [
			'rle_id',
			'rle_rl_id',
			// returning rle_user_id is pointless as lists are only available to the owner
			// skip rle_rlp_id, it's only needed for the join
			'rlp_project',
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
	 * Ensures that the value to be written to the database does not exceed the DB field length.
	 * @param string $field Field name.
	 * @param string $value Value to write.
	 * @throws ReadingListRepositoryException
	 */
	private function assertFieldLength( $field, $value ) {
		if ( !isset( self::$fieldLength[$field] ) ) {
			throw new LogicException( 'Tried to assert length for invalid field ' . $field );
		}
		if ( strlen( $value ) > self::$fieldLength[$field] ) {
			throw new ReadingListRepositoryException( 'readinglists-db-error-too-long',
				[ $field, self::$fieldLength[$field] ] );
		}
	}

	/**
	 * Validate sort paramters.
	 * @param string $tablePrefix 'rl' or 'rle', depending on whether we are sorting lists or entries.
	 * @param string $sortBy
	 * @param string $sortDir
	 * @param int $limit
	 * @param array|null $from
	 * @return array [ conditions, options ] Merge these into the corresponding IDatabase::select
	 *   parameters.
	 */
	private function processSort( $tablePrefix, $sortBy, $sortDir, $limit, $from ) {
		if ( !in_array( $sortBy, [ self::SORT_BY_NAME, self::SORT_BY_UPDATED ], true ) ) {
			throw new LogicException( 'Invalid $sortBy parameter: ' . $sortBy );
		}
		if ( !in_array( $sortDir, [ self::SORT_DIR_ASC, self::SORT_DIR_DESC ], true ) ) {
			throw new LogicException( 'Invalid $sortDir parameter: ' . $sortDir );
		}
		if ( is_array( $from ) ) {
			if ( count( $from ) !== 2 || !is_string( $from[0] ) || !is_numeric( $from[1] ) ) {
				throw new LogicException( 'Invalid $from parameter' );
			}
		} elseif ( $from !== null ) {
			throw new LogicException( 'Invalid $from parameter type: ' . gettype( $from ) );
		}

		if ( $tablePrefix === 'rl' ) {
			$mainField = ( $sortBy === self::SORT_BY_NAME ) ? 'rl_name' : 'rl_date_updated';
		} else {
			$mainField = ( $sortBy === self::SORT_BY_NAME ) ? 'rle_title' : 'rle_date_updated';
		}
		$idField = "${tablePrefix}_id";
		$conditions = [];
		$options = [
			'ORDER BY' => [ "$mainField $sortDir", "$idField $sortDir" ],
			'LIMIT' => (int)$limit,
		];

		if ( $from !== null ) {
			$op = ( $sortDir === self::SORT_DIR_ASC ) ? '>' : '<';
			$safeFromMain = ( $sortBy === self::SORT_BY_NAME )
				? $this->dbr->addQuotes( $from[0] )
				: $this->dbr->addQuotes( $this->dbr->timestamp( $from[0] ) );
			$safeFromId = (int)$from[1];
			$conditions[] = $this->dbr->makeList( [
				"$mainField $op $safeFromMain",
				$this->dbr->makeList( [
					"$mainField = $safeFromMain",
					"$idField $op= $safeFromId",
				], IDatabase::LIST_AND ),
			], IDatabase::LIST_OR );
		}

		// note: $conditions will be array_merge-d so it should not contain non-numeric keys
		return [ $conditions, $options ];
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

	/**
	 * Returns the number of (non-deleted) lists of the current user.
	 * @param int $flags IDBAccessObject flags
	 * @return int
	 */
	private function getListCount( $flags = 0 ) {
		$this->assertUser();
		list( $index, $options ) = DBAccessObjectUtils::getDBOptions( $flags );
		$db = ( $index === DB_MASTER ) ? $this->dbw : $this->dbr;
		return $db->selectRowCount(
			'reading_list',
			'1',
			[
				'rl_user_id' => $this->userId,
				'rl_deleted' => 0,
			],
			__METHOD__,
			$options
		);
	}

	/**
	 * Look up a project ID.
	 * @param string $project
	 * @return int|null
	 */
	private function getProjectId( $project ) {
		$id = $this->dbr->selectField(
			'reading_list_project',
			'rlp_id',
			[ 'rlp_project' => $project ]
		);
		return $id === false ? null : (int)$id;
	}

	/**
	 * Returns the number of (non-deleted) list entries of the given list.
	 * Verifying that the list is valid is caller's responsibility.
	 * @param int $id List id
	 * @param int $flags IDBAccessObject flags
	 * @return int
	 */
	private function getEntryCount( $id, $flags = 0 ) {
		$this->assertUser();
		list( $index, $options ) = DBAccessObjectUtils::getDBOptions( $flags );
		$db = ( $index === DB_MASTER ) ? $this->dbw : $this->dbr;
		return $db->selectRowCount(
			'reading_list_entry',
			'1',
			[
				'rle_rl_id' => $id,
				'rle_deleted' => 0,
			],
			__METHOD__,
			$options
		);
	}

}
