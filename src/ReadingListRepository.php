<?php

namespace MediaWiki\Extension\ReadingLists;

use LogicException;
use MediaWiki\Extension\ReadingLists\Doc\ReadingListEntryRow;
use MediaWiki\Extension\ReadingLists\Doc\ReadingListEntryRowWithMergeFlag;
use MediaWiki\Extension\ReadingLists\Doc\ReadingListRow;
use MediaWiki\Extension\ReadingLists\Doc\ReadingListRowWithMergeFlag;
use MediaWiki\MediaWikiServices;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Wikimedia\Rdbms\FakeResultWrapper;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IDBAccessObject;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\IResultWrapper;
use Wikimedia\Rdbms\LBFactory;
use Wikimedia\Rdbms\RawSQLValue;

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
class ReadingListRepository implements LoggerAwareInterface {

	/** Sort lists / entries alphabetically by name / title. */
	public const SORT_BY_NAME = 'name';
	/** Sort lists / entries chronologically by last updated timestamp. */
	public const SORT_BY_UPDATED = 'updated';
	/** Sort ascendingly (first letter / oldest date first). */
	public const SORT_DIR_ASC = 'asc';
	/** Sort descendingly (last letter / newest date first). */
	public const SORT_DIR_DESC = 'desc';

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

	/** @var IReadableDatabase */
	private $dbr;

	/** @var int|null */
	private ?int $userId;

	/**
	 * @param ?int $userId Central ID of the user.
	 * @param LBFactory $lbFactory
	 */
	public function __construct(
		?int $userId,
		private readonly LBFactory $lbFactory
	) {
		$this->userId = (int)$userId ?: null;
		$this->dbw = $lbFactory->getPrimaryDatabase( Utils::VIRTUAL_DOMAIN );
		$this->dbr = $lbFactory->getReplicaDatabase( Utils::VIRTUAL_DOMAIN );
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

	public function setLogger( LoggerInterface $logger ): void {
		$this->logger = $logger;
	}

	// setup / teardown

	/**
	 * Set up the service for the given user.
	 * This is a pre-requisite for doing anything else. It will create a default list.
	 * @param bool $silent When true, returns the default list instead of throwing if already set up.
	 * @return ReadingListRow The default list for the user.
	 * @throws ReadingListRepositoryException
	 */
	public function setupForUser( $silent = false ) {
		if ( !$this->hasProjects() ) {
			throw new ReadingListRepositoryException( 'readinglists-db-error-no-projects' );
		}

		$this->assertUser();
		$defaultListId = $this->getDefaultListIdForUser( IDBAccessObject::READ_LOCKING );

		// Bypass the setup process if the default list already exists
		if ( $defaultListId !== false ) {
			// Older code may expect the exception behavior, so only return if $silent = true
			if ( $silent ) {
				return $this->selectValidList( $defaultListId, IDBAccessObject::READ_LATEST );
			}

			throw new ReadingListRepositoryException( 'readinglists-db-error-already-set-up' );
		}

		$this->dbw->newInsertQueryBuilder()
			->insertInto( 'reading_list' )
			->row( [
				'rl_user_id' => $this->userId,
				'rl_is_default' => 1,
				'rl_name' => 'default',
				'rl_description' => '',
				'rl_date_created' => $this->dbw->timestamp(),
				'rl_date_updated' => $this->dbw->timestamp(),
				'rl_size' => 0,
				'rl_deleted' => 0,
			] )
			->caller( __METHOD__ )->execute();
		$this->logger->info( 'Set up for user {user}', [ 'user' => $this->userId ] );
		$list = $this->selectValidList( $this->dbw->insertId(), IDBAccessObject::READ_LATEST );
		return $list;
	}

	/**
	 * Remove all data for the given user.
	 * No other operation can be performed for the user except setup.
	 * @return void
	 * @throws ReadingListRepositoryException
	 */
	public function teardownForUser() {
		$this->assertUser();
		if ( !$this->getDefaultListIdForUser() ) {
			throw new ReadingListRepositoryException( 'readinglists-db-error-not-set-up' );
		}

		// Soft-delete. Note that no reading list entries are updated;
		// they are effectively orphaned by soft-deletion of their lists
		// and will be batch-removed in purgeOldDeleted().
		$this->dbw->newUpdateQueryBuilder()
			->update( 'reading_list' )
			->set( [
				// 'rl_name' is randomized in anticipation of
				// eventually enforcing uniqueness with an
				// index (in which case it can't be limited to
				// non-deleted lists).
				'rl_name' => new RawSQLValue( $this->dbw->buildConcat( [
					$this->dbw->addQuotes( 'deleted-' ),
					'rl_name',
					$this->dbw->addQuotes( '-' . bin2hex( random_bytes( 16 ) ) )
				] ) ),
				'rl_deleted' => 1,
				'rl_date_updated' => $this->dbw->timestamp(),
			] )
			->where( [ 'rl_user_id' => $this->userId ] )
			->caller( __METHOD__ )->execute();
		if ( !$this->dbw->affectedRows() ) {
			$this->logger->error( 'teardownForUser failed for unknown reason', [
				'user_central_id' => $this->userId,
			] );
			throw new LogicException( 'teardownForUser failed for unknown reason' );
		}

		$this->logger->info( 'Tore down for user {user}', [ 'user' => $this->userId ] );
	}

	/**
	 * Check whether reading lists have been set up for the given user (i.e. setupForUser() was
	 * called with $userId and teardownForUser() was not called with the same id afterward).
	 *
	 * Optionally also lock the DB row for the default list of the user (will be used as a
	 * semaphore).
	 *
	 * This will return the default list ID if the user has already set up, otherwise false.
	 * @param int $flags IDBAccessObject flags
	 * @throws ReadingListRepositoryException
	 * @return false|int
	 */
	public function getDefaultListIdForUser( $flags = 0 ) {
		$this->assertUser();
		if ( ( $flags & IDBAccessObject::READ_LATEST ) == IDBAccessObject::READ_LATEST ) {
			$db = $this->dbw;
		} else {
			$db = $this->dbr;
		}
		return $db->newSelectQueryBuilder()
			->select( 'rl_id' )
			->from( 'reading_list' )
			->where(
				[
					'rl_user_id' => $this->userId,
					// It would probably be fine to just check if the user has lists at all,
					// but this way is extra safe against races as setup is the only operation that
					// creates a default list.
					'rl_is_default' => 1,
					'rl_deleted' => 0
				]
			)
			->recency( $flags )
			->limit( 1 )
			->caller( __METHOD__ )->fetchField();
	}

	// list CRUD

	/**
	 * Get list data, and optionally lock the list.
	 * List must exist, belong to the current user and not be deleted.
	 * @param int $id List id
	 * @param int $flags IDBAccessObject flags
	 * @return ReadingListRow
	 * @throws ReadingListRepositoryException
	 * @suppress PhanTypeMismatchReturn Use of doc traits
	 */
	public function selectValidList( $id, $flags = 0 ) {
		$this->assertUser();
		if ( ( $flags & IDBAccessObject::READ_LATEST ) == IDBAccessObject::READ_LATEST ) {
			$db = $this->dbw;
		} else {
			$db = $this->dbr;
		}
		/** @var ReadingListRow $row */
		$row = $db->newSelectQueryBuilder()
			->select( array_merge( $this->getListFields(), [ 'rl_user_id' ] ) )
			->from( 'reading_list' )
			->where( [ 'rl_id' => $id ] )
			->recency( $flags )
			->caller( __METHOD__ )->fetchRow();
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
	 * Create a new list.
	 * List name is unique for a given user; on conflict, update the existing list.
	 * @param string $name
	 * @param string $description
	 * @return ReadingListRowWithMergeFlag The new (or updated) list.
	 * @throws ReadingListRepositoryException
	 */
	public function addList( $name, $description = '' ) {
		$this->assertUser();
		$this->assertFieldLength( 'rl_name', $name );
		$this->assertFieldLength( 'rl_description', $description );
		if ( !$this->getDefaultListIdForUser( IDBAccessObject::READ_LOCKING ) ) {
			throw new ReadingListRepositoryException( 'readinglists-db-error-not-set-up' );
		}
		if ( $this->listLimit && $this->getListCount( IDBAccessObject::READ_LATEST ) >= $this->listLimit ) {
			// We could check whether the list exists already, in which case we could just
			// update the existing list and return success, but that's too much of an edge case
			// to be worth bothering with.
			throw new ReadingListRepositoryException( 'readinglists-db-error-list-limit',
				[ $this->listLimit ] );
		}

		// rl_user_id + rlname is unique for non-deleted lists. On conflict, update the
		// existing page instead. Also enforce that deleted lists cannot have the same name,
		// in anticipation of eventually using a unique index for list names.
		/** @var false|ReadingListRow $row */
		$row = $this->dbw->newSelectQueryBuilder()
			->select( self::getListFields() )
			// lock the row to avoid race conditions with purgeOldDeleted() in the update case
			->forUpdate()
			->from( 'reading_list' )
			->where( [ 'rl_user_id' => $this->userId, 'rl_name' => $name, ] )
			->caller( __METHOD__ )->fetchRow();

		if ( $row === false ) {
			$this->dbw->newInsertQueryBuilder()
				->insertInto( 'reading_list' )
				->row( [
					'rl_user_id' => $this->userId,
					'rl_is_default' => 0,
					'rl_name' => $name,
					'rl_description' => $description,
					'rl_date_created' => $this->dbw->timestamp(),
					'rl_date_updated' => $this->dbw->timestamp(),
					'rl_size' => 0,
					'rl_deleted' => 0,
				] )
				->caller( __METHOD__ )->execute();
			$id = $this->dbw->insertId();
			$merged = false;
		} elseif ( $row->rl_deleted ) {
			$this->logger->error( 'Encountered deleted list with non-unique name on insert', [
				'rl_id' => $row->rl_id,
				'rl_name' => $row->rl_name,
				'user_central_id' => $this->userId,
			] );
			throw new LogicException( 'Encountered deleted list with non-unique name on insert' );
		} elseif ( $row->rl_description === $description ) {
			// List already exists with the same details; nothing to do, just return the ID.
			$id = $row->rl_id;
			$merged = true;
		} else {
			$this->dbw->newUpdateQueryBuilder()
				->update( 'reading_list' )
				->set( [
					'rl_description' => $description,
					'rl_date_updated' => $this->dbw->timestamp(),
				] )
				->where( [ 'rl_id' => $row->rl_id ] )
				->caller( __METHOD__ )->execute();
			$id = $row->rl_id;
			$merged = true;
		}
		$this->logger->info( 'Added list {list} for user {user}', [
			'list' => $id,
			'user' => $this->userId,
			'merged' => $merged,
		] );

		// We could just construct the result ourselves but let's be paranoid and re-query it
		// in case some conversion or corruption happens in MySQL.
		/** @var ReadingListRowWithMergeFlag $list */
		$list = $this->selectValidList( $id, IDBAccessObject::READ_LATEST );
		'@phan-var ReadingListRowWithMergeFlag $list';
		$list->merged = $merged;
		return $list;
	}

	/**
	 * Get all lists of the user.
	 * @param string $sortBy One of the SORT_BY_* constants.
	 * @param string $sortDir One of the SORT_DIR_* constants.
	 * @param int $limit
	 * @param array|null $from DB position to continue from (or null to start at the beginning/end).
	 *   When sorting by name, this should be the name and id of a list; when sorting by update time,
	 *   the updated timestamp (in some form accepted by MWTimestamp) and the id.
	 * @return IResultWrapper<ReadingListRow>
	 * @throws ReadingListRepositoryException
	 */
	public function getAllLists( $sortBy, $sortDir, $limit = 1000, ?array $from = null ) {
		$this->assertUser();
		[ $conditions, $options ] = $this->processSort( 'rl', $sortBy, $sortDir, $limit, $from );

		// Avoid default list showing on pages > 1, so exclude it and skip order by
		if ( $from ) {
			array_unshift( $conditions, 'rl_is_default = 0' );
		} else {
			array_unshift( $options[ 'ORDER BY' ], 'rl_is_default desc' );
		}

		$res = $this->dbr->newSelectQueryBuilder()
			->select( $this->getListFields() )
			->from( 'reading_list' )
			->where( [ 'rl_user_id' => $this->userId, 'rl_deleted' => 0 ] )
			->andWhere( $conditions )
			->options( $options )
			->caller( __METHOD__ )->fetchResultSet();
		if (
			$res->numRows() === 0
			&& !$this->getDefaultListIdForUser( IDBAccessObject::READ_LATEST )
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
	 * @return ReadingListRow The updated list.
	 * @throws ReadingListRepositoryException|LogicException
	 */
	public function updateList( $id, $name = null, $description = null ) {
		$this->assertUser();
		$this->assertFieldLength( 'rl_name', $name );
		$this->assertFieldLength( 'rl_description', $description );
		$row = $this->selectValidList( $id, IDBAccessObject::READ_LOCKING );
		if ( $row->rl_is_default ) {
			throw new ReadingListRepositoryException( 'readinglists-db-error-cannot-update-default-list' );
		}

		if ( $name !== null && $name !== $row->rl_name ) {
			/** @var false|ReadingListRow $row2 */
			$row2 = $this->dbw->newSelectQueryBuilder()
				->select( self::getListFields() )
				// lock the row to avoid race conditions with purgeOldDeleted() in the update case
				->forUpdate()
				->from( 'reading_list' )
				->where( [ 'rl_user_id' => $this->userId, 'rl_name' => $name, ] )
				->caller( __METHOD__ )->fetchRow();

			if ( $row2 !== false && (int)$row2->rl_id !== $id ) {
				if ( $row2->rl_deleted ) {
					$this->logger->error( 'Encountered deleted list with non-unique name on update', [
						'this_rl_id' => $row->rl_id,
						'that_rl_id' => $row2->rl_id,
						'rl_name' => $row2->rl_name,
						'user_central_id' => $this->userId,
					] );
					throw new LogicException( 'Encountered deleted list with non-unique name on update' );
				} else {
					throw new ReadingListRepositoryException( 'readinglists-db-error-duplicate-list' );
				}
			}
		}

		$data = array_filter( [
			'rl_name' => $name,
			'rl_description' => $description,
			'rl_date_updated' => $this->dbw->timestamp(),
		], static function ( $field ) {
			return $field !== null;
		} );
		if ( (array)$row === array_merge( (array)$row, $data ) ) {
			// Besides being pointless, this would hit the LogicException below
			return $row;
		}

		$this->dbw->newUpdateQueryBuilder()
			->update( 'reading_list' )
			->set( $data )
			->where( [ 'rl_id' => $id ] )
			->caller( __METHOD__ )->execute();

		if ( !$this->dbw->affectedRows() ) {
			$this->logger->error( 'updateList failed for unknown reason', [
				'rl_id' => $row->rl_id,
				'user_central_id' => $this->userId,
				'data' => $data,
			] );
			throw new LogicException( 'updateList failed for unknown reason' );
		}

		// We could just construct the result ourselves but let's be paranoid and re-query it
		// in case some conversion or corruption happens in MySQL.
		return $this->selectValidList( $id, IDBAccessObject::READ_LATEST );
	}

	/**
	 * Delete a list.
	 * @param int $id
	 * @return void
	 * @throws ReadingListRepositoryException
	 */
	public function deleteList( $id ) {
		$this->assertUser();
		$row = $this->selectValidList( $id, IDBAccessObject::READ_LOCKING );
		if ( $row->rl_is_default ) {
			throw new ReadingListRepositoryException( 'readinglists-db-error-cannot-delete-default-list' );
		}

		$this->dbw->newUpdateQueryBuilder()
			->update( 'reading_list' )
			->set( [
				// Randomize the name of deleted lists in anticipation of eventually enforcing
				// uniqueness with an index (in which case it can't be limited to non-deleted lists).
				'rl_name' => new RawSQLValue( $this->dbw->buildConcat( [
					$this->dbw->addQuotes( 'deleted-' ),
					'rl_name',
					$this->dbw->addQuotes( '-' . bin2hex( random_bytes( 16 ) ) )
				] ) ),
				'rl_deleted' => 1,
				'rl_date_updated' => $this->dbw->timestamp()
			] )
			->where( [ 'rl_id' => $id ] )
			->caller( __METHOD__ )->execute();
		if ( !$this->dbw->affectedRows() ) {
			$this->logger->error( 'deleteList failed for unknown reason', [
				'rl_id' => $row->rl_id,
				'user_central_id' => $this->userId,
			] );
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
	 * When the given page is already on the list, do nothing, just return it.
	 * @param int $listId List ID
	 * @param string $project Project identifier (typically a domain name)
	 * @param string $title Page title (treated as a plain string with no normalization;
	 *   in localized namespace-prefixed format with spaces is recommended)
	 * @return ReadingListEntryRowWithMergeFlag The new (or existing) list entry.
	 * @throws ReadingListRepositoryException
	 * @suppress PhanTypeMismatchReturn Use of doc traits
	 */
	public function addListEntry( $listId, $project, $title ) {
		$this->assertUser();
		$this->assertFieldLength( 'rlp_project', $project );
		$this->assertFieldLength( 'rle_title', $title );
		$this->selectValidList( $listId, IDBAccessObject::READ_EXCLUSIVE );
		if (
			$this->entryLimit
			&& $this->getEntryCount( $listId, IDBAccessObject::READ_LATEST ) >= $this->entryLimit
		) {
			// We could check whether the entry exists already, in which case we could just
			// return success without modifying the entry, but that's too much of an edge case
			// to be worth bothering with.
			throw new ReadingListRepositoryException( 'readinglists-db-error-entry-limit',
				[ $listId, $this->entryLimit ] );
		}

		$projectId = $this->getProjectId( $project );
		if ( !$projectId ) {
			throw new ReadingListRepositoryException( 'readinglists-db-error-no-such-project',
				[ $project ] );
		}

		// due to the combination of soft deletion + unique constraint on
		// rle_rl_id + rle_rlp_id + rle_title, recreation needs special handling
		/** @var false|ReadingListEntryRowWithMergeFlag $row */
		$row = $this->dbw->newSelectQueryBuilder()
			->select( self::getListEntryFields() )
			// lock the row to avoid race conditions with purgeOldDeleted() in the update case
			->forUpdate()
			->from( 'reading_list_entry' )
			->leftJoin( 'reading_list_project', null, 'rle_rlp_id = rlp_id' )
			->where(
				[
					'rle_rl_id' => $listId,
					'rle_rlp_id' => $projectId,
					'rle_title' => $title,
				]
			)
			->caller( __METHOD__ )->fetchRow();
		if ( $row === false ) {
			$this->dbw->newInsertQueryBuilder()
				->insertInto( 'reading_list_entry' )
				->row( [
					'rle_rl_id' => $listId,
					'rle_user_id' => $this->userId,
					'rle_rlp_id' => $projectId,
					'rle_title' => $title,
					'rle_date_created' => $this->dbw->timestamp(),
					'rle_date_updated' => $this->dbw->timestamp(),
					'rle_deleted' => 0,
				] )
				->caller( __METHOD__ )->execute();
			$entryId = $this->dbw->insertId();
			$type = 'inserted';
		} elseif ( $row->rle_deleted ) {
			$this->dbw->newUpdateQueryBuilder()
				->update( 'reading_list_entry' )
				->set( [
					'rle_date_created' => $this->dbw->timestamp(),
					'rle_date_updated' => $this->dbw->timestamp(),
					'rle_deleted' => 0,
				] )
				->where( [ 'rle_id' => $row->rle_id ] )
				->caller( __METHOD__ )->execute();

			$entryId = (int)$row->rle_id;
			$type = 'recreated';
		} else {
			// The entry already exists, we just need to return its ID.
			$entryId = (int)$row->rle_id;
			$type = 'merged';
		}
		if ( $type !== 'merged' ) {
			$this->dbw->newUpdateQueryBuilder()
				->update( 'reading_list' )
				->set( [ 'rl_size' => new RawSQLValue( 'rl_size + 1' ) ] )
				->where( [ 'rl_id' => $listId ] )
				->caller( __METHOD__ )->execute();
		}

		$this->logger->info( 'Added entry {entry} for user {user}', [
			'entry' => $entryId,
			'user' => $this->userId,
			'type' => $type,
		] );

		/** @var false|ReadingListEntryRowWithMergeFlag $row */
		if ( $type === 'merged' ) {
			$row->merged = true;
			return $row;
		} else {
			$row = $this->dbw->newSelectQueryBuilder()
				->select( self::getListEntryFields() )
				->from( 'reading_list_entry' )
				->leftJoin( 'reading_list_project', null, 'rle_rlp_id = rlp_id' )
				->where( [ 'rle_id' => $entryId ] )
				->caller( __METHOD__ )->fetchRow();

			if ( $row === false ) {
				$this->logger->error( 'Failed to retrieve stored entry', [
					'rle_id' => $entryId,
					'user_central_id' => $this->userId,
				] );
				throw new LogicException( 'Failed to retrieve stored entry' );
			}
			$row->merged = false;
			return $row;
		}
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
		array $ids, $sortBy, $sortDir, $limit = 1000, ?array $from = null
	) {
		$this->assertUser();
		if ( !$ids ) {
			throw new ReadingListRepositoryException( 'readinglists-db-error-empty-list-ids' );
		}
		[ $conditions, $options ] = $this->processSort( 'rle', $sortBy, $sortDir, $limit, $from );

		// sanity check for nice error messages
		$res = $this->dbr->newSelectQueryBuilder()
			->select( [ 'rl_id', 'rl_user_id', 'rl_deleted' ] )
			->from( 'reading_list' )
			->where( [ 'rl_id' => $ids ] )
			->caller( __METHOD__ )->fetchResultSet();
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

		$res = $this->dbr->newSelectQueryBuilder()
			->select( $this->getListEntryFields() )
			->from( 'reading_list_entry' )
			->join( 'reading_list_project', null, 'rle_rlp_id = rlp_id' )
			->where( [ 'rle_rl_id' => $ids, 'rle_user_id' => $this->userId, 'rle_deleted' => 0 ] )
			->andWhere( $conditions )
			->options( $options )
			->caller( __METHOD__ )->fetchResultSet();

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
		$row = $this->dbw->newSelectQueryBuilder()
			->select( [ 'rl_id', 'rl_user_id', 'rl_deleted', 'rle_id', 'rle_deleted' ] )
			// lock the row to avoid race conditions with purgeOldDeleted() in the update case
			->forUpdate()
			->from( 'reading_list' )
			->leftJoin( 'reading_list_entry', null, 'rl_id = rle_rl_id' )
			->where( [ 'rle_id' => $id ] )
			->caller( __METHOD__ )->fetchRow();
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

		$this->dbw->newUpdateQueryBuilder()
			->update( 'reading_list_entry' )
			->set( [
				'rle_deleted' => 1,
				'rle_date_updated' => $this->dbw->timestamp(),
			] )
			->where( [ 'rle_id' => $id ] )
			->caller( __METHOD__ )->execute();

		if ( !$this->dbw->affectedRows() ) {
			$this->logger->error( 'deleteListEntry failed for unknown reason', [
				'rle_id' => $row->rle_id,
				'user_central_id' => $this->userId,
			] );
			throw new LogicException( 'deleteListEntry failed for unknown reason' );
		}
		$this->dbw->newUpdateQueryBuilder()
			->update( 'reading_list' )
			->set( [ 'rl_size' => new RawSQLValue( 'rl_size - 1' ) ] )
			->where( [
				'rl_id' => $row->rl_id,
				$this->dbw->expr( 'rl_size', '>', 0 ),
			] )
			->caller( __METHOD__ )->execute();

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
	public function getListsByDateUpdated(
		$date,
		$sortBy = self::SORT_BY_UPDATED,
		$sortDir = self::SORT_DIR_ASC,
		$limit = 1000,
		?array $from = null
	) {
		$this->assertUser();
		[ $conditions, $options ] = $this->processSort( 'rl', $sortBy, $sortDir, $limit, $from );

		// Avoid default list showing on pages > 1, so exclude it and skip order by
		if ( $from ) {
			array_unshift( $conditions, 'rl_is_default = 0' );
		} else {
			array_unshift( $options[ 'ORDER BY' ], 'rl_is_default desc' );
		}

		$res = $this->dbr->newSelectQueryBuilder()
			->select( $this->getListFields() )
			->from( 'reading_list' )
			->where( [
				'rl_user_id' => $this->userId,
				$this->dbr->expr( 'rl_date_updated', '>', $this->dbr->timestamp( $date ) )
			] )
			->andWhere( $conditions )
			->options( $options )
			->caller( __METHOD__ )->fetchResultSet();
		if (
			$res->numRows() === 0
			&& !$this->getDefaultListIdForUser( IDBAccessObject::READ_LATEST )
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
		$date, $sortDir = self::SORT_DIR_ASC, $limit = 1000, ?array $from = null
	) {
		$this->assertUser();
		// Always sort by last updated; there is no supporting index for sorting by name.
		[ $conditions, $options ] = $this->processSort( 'rle', self::SORT_BY_UPDATED,
			$sortDir, $limit, $from );
		$res = $this->dbr->newSelectQueryBuilder()
			->select( $this->getListEntryFields() )
			->from( 'reading_list' )
			->join( 'reading_list_entry', null, 'rl_id = rle_rl_id' )
			->join( 'reading_list_project', null, 'rle_rlp_id = rlp_id' )
			->where( [
				'rle_user_id' => $this->userId,
				'rl_deleted' => 0,
				$this->dbr->expr( 'rle_date_updated', '>', $this->dbr->timestamp( $date ) )
			] )
			->andWhere( $conditions )
			->options( $options )
			->caller( __METHOD__ )->fetchResultSet();
		return $res;
	}

	/**
	 * Purge all deleted lists/entries older than $before.
	 * Unlike most other methods in the class, this one ignores user IDs.
	 * @param string $before A timestamp in TS_MW format.
	 * @return void
	 */
	public function purgeOldDeleted( $before ) {
		// Purge all soft-deleted, expired entries
		while ( true ) {
			$ids = $this->dbw->newSelectQueryBuilder()
				->select( 'rle_id' )
				->from( 'reading_list_entry' )
				->where( [
					'rle_deleted' => 1,
					$this->dbw->expr( 'rle_date_updated', '<', $this->dbw->timestamp( $before ) )
				] )
				->limit( 1000 )
				->caller( __METHOD__ )->fetchFieldValues();
			if ( !$ids ) {
				break;
			}
			$this->dbw->newDeleteQueryBuilder()
				->deleteFrom( 'reading_list_entry' )
				->where( [ 'rle_id' => $ids ] )
				->caller( __METHOD__ )->execute();
			$this->logger->debug( 'Purged {n} entries', [ 'n' => $this->dbw->affectedRows() ] );
			$this->lbFactory->waitForReplication();
		}

		// Purge all entries on soft-deleted, expired lists
		while ( true ) {
			$ids = $this->dbw->newSelectQueryBuilder()
				->select( 'rle_id' )
				->from( 'reading_list_entry' )
				->leftJoin( 'reading_list', null, 'rle_rl_id = rl_id' )
				->where( [
					'rl_deleted' => 1,
					$this->dbw->expr( 'rl_date_updated', '<', $this->dbw->timestamp( $before ) )
				] )
				->limit( 1000 )
				->caller( __METHOD__ )->fetchFieldValues();
			if ( !$ids ) {
				break;
			}
			$this->dbw->newDeleteQueryBuilder()
				->deleteFrom( 'reading_list_entry' )
				->where( [ 'rle_id' => $ids ] )
				->caller( __METHOD__ )->execute();
			$this->logger->debug( 'Purged {n} entries', [ 'n' => $this->dbw->affectedRows() ] );
			$this->lbFactory->waitForReplication();
		}

		// Purge all soft-deleted, expired lists
		while ( true ) {
			$ids = $this->dbw->newSelectQueryBuilder()
				->select( 'rl_id' )
				->from( 'reading_list' )
				->where( [
					'rl_deleted' => 1,
					$this->dbw->expr( 'rl_date_updated', '<', $this->dbw->timestamp( $before ) )
				] )
				->limit( 1000 )
				->caller( __METHOD__ )->fetchFieldValues();
			if ( !$ids ) {
				break;
			}
			$this->dbw->newDeleteQueryBuilder()
				->deleteFrom( 'reading_list' )
				->where( [ 'rl_id' => $ids ] )
				->caller( __METHOD__ )->execute();
			$this->logger->debug( 'Purged {n} lists', [ 'n' => $this->dbw->affectedRows() ] );
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
			'rle_user_id' => $this->userId,
			'rle_rlp_id' => $projectId,
			'rle_title' => $title,
			'rle_deleted' => 0,
			'rl_deleted' => 0
		];

		$queryBuilder = $this->dbr->newSelectQueryBuilder()
			->select( array_merge( $this->getListFields(), [ 'rle_id' ] ) )
			->from( 'reading_list_entry' )
			->join( 'reading_list', null, 'rl_id = rle_rl_id' )
			->where( $conditions )
			->limit( (int)$limit )
			->caller( __METHOD__ );

		if ( $from !== null ) {
			$queryBuilder->andWhere(
				$this->dbr->expr( 'rle_rl_id', '>=', (int)$from )
			);
		}

		// Avoid default list showing on pages > 1, so exclude it and skip order by
		if ( $from ) {
			$queryBuilder->where( 'rl_is_default = 0' );
		} else {
			$queryBuilder->orderBy( 'rl_is_default', 'DESC' );
		}

		$res = $queryBuilder->orderBy( 'rle_rl_id', 'ASC' )->fetchResultSet();
		if (
			$res->numRows() === 0 &&
			!$this->getDefaultListIdForUser( IDBAccessObject::READ_LATEST )
		) {
			throw new ReadingListRepositoryException( 'readinglists-db-error-not-set-up' );
		}
		return $res;
	}

	/**
	 * Recalculate the size of the given list.
	 * @param int $id
	 * @return bool True if the list needed to be fixed.
	 * @throws ReadingListRepositoryException
	 */
	public function fixListSize( $id ) {
		$this->dbw->startAtomic( __METHOD__ );
		$oldSize = $this->dbw->newSelectQueryBuilder()
			->select( 'rl_size' )
			// lock the row to avoid race conditions with purgeOldDeleted() in the update case
			->forUpdate()
			->from( 'reading_list' )
			->where( [ 'rl_id' => $id ] )
			->caller( __METHOD__ )->fetchField();
		if ( $oldSize === false ) {
			throw new ReadingListRepositoryException( 'readinglists-db-error-no-such-list', [ $id ] );
		}

		$count = $this->dbw->newSelectQueryBuilder()
			->select( 'count(*)' )
			->from( 'reading_list_entry' )
			->where( [ 'rle_rl_id' => $id, 'rle_deleted' => 0, ] )
			->groupBy( 'rle_rl_id' )
			->caller( __METHOD__ )->fetchField();
		$this->dbw->newUpdateQueryBuilder()
			->update( 'reading_list' )
			->set( [ 'rl_size' => $count ] )
			->where( [
				'rl_id' => $id,
				$this->dbw->expr( 'rl_size', '!=', (int)$count )
			] )
			->caller( __METHOD__ )->execute();

		// Release the lock when using explicit transactions (called from a long-running script).
		$this->dbw->endAtomic( __METHOD__ );
		return (bool)$this->dbw->affectedRows();
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
			'rl_size',
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
		if ( strlen( $value ?? '' ) > self::$fieldLength[$field] ) {
			throw new ReadingListRepositoryException( 'readinglists-db-error-too-long',
				[ $field, self::$fieldLength[$field] ] );
		}
	}

	/**
	 * Validate sort parameters.
	 * @param string $tablePrefix 'rl' or 'rle', depending on whether we are sorting lists or entries.
	 * @param string $sortBy
	 * @param string $sortDir
	 * @param int $limit
	 * @param array|null $from [sortby-value, id]
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
			throw new LogicException( 'Invalid $from parameter type: ' . get_debug_type( $from ) );
		}

		if ( $tablePrefix === 'rl' ) {
			$mainField = ( $sortBy === self::SORT_BY_NAME ) ? 'rl_name' : 'rl_date_updated';
		} else {
			$mainField = ( $sortBy === self::SORT_BY_NAME ) ? 'rle_title' : 'rle_date_updated';
		}
		$idField = "{$tablePrefix}_id";
		$conditions = [];
		$options = [
			'ORDER BY' => [ "$mainField $sortDir" ],
			'LIMIT' => (int)$limit,
		];
		// List names are unique and need no tiebreaker.
		if ( $sortBy !== self::SORT_BY_NAME || $tablePrefix !== 'rl' ) {
			$options['ORDER BY'][] = "$idField $sortDir";
		}

		if ( $from !== null ) {
			$op = ( $sortDir === self::SORT_DIR_ASC ) ? '>' : '<';
			$safeFromMain = ( $sortBy === self::SORT_BY_NAME )
				? $from[0]
				: $this->dbr->timestamp( $from[0] );
			$safeFromId = (int)$from[1];
			// List names are unique and need no tiebreaker.
			if ( $sortBy === self::SORT_BY_NAME && $tablePrefix === 'rl' ) {
				$condition = $this->dbw->expr( $mainField, "$op=", $safeFromMain );
			} else {
				$condition = $this->dbr->buildComparison( "$op=", [
					$mainField => $safeFromMain,
					$idField => $safeFromId
				] );
			}
			$conditions[] = $condition;
		}

		// note: $conditions will be array_merge-d so it should not contain non-numeric keys
		return [ $conditions, $options ];
	}

	/**
	 * Returns the number of (non-deleted) lists of the current user.
	 * @param int $flags IDBAccessObject flags
	 * @return int
	 * @throws ReadingListRepositoryException
	 */
	private function getListCount( $flags = 0 ) {
		$this->assertUser();
		if ( ( $flags & IDBAccessObject::READ_LATEST ) == IDBAccessObject::READ_LATEST ) {
			$db = $this->dbw;
		} else {
			$db = $this->dbr;
		}
		return $db->newSelectQueryBuilder()
			->select( '1' )
			->from( 'reading_list' )
			->where( [ 'rl_user_id' => $this->userId, 'rl_deleted' => 0, ] )
			->recency( $flags )
			->caller( __METHOD__ )->fetchRowCount();
	}

	/**
	 * Look up a project ID.
	 * @param string $project
	 * @return int|null
	 */
	private function getProjectId( $project ) {
		if ( $project === '@local' ) {
			// Support for "@local" is provided primarily for the benefit
			// of Mocha tests, so they don't have to know the actual project
			// URL.
			$project = $this->getLocalProject();
		}

		$id = $this->dbr->newSelectQueryBuilder()
			->select( 'rlp_id' )
			->from( 'reading_list_project' )
			->where( [ 'rlp_project' => $project ] )
			->caller( __METHOD__ )->fetchField();
		return $id === false ? null : (int)$id;
	}

	/**
	 * Returns the number of (non-deleted) list entries of the given list.
	 * Verifying that the list is valid is caller's responsibility.
	 * @param int $id List id
	 * @param int $flags IDBAccessObject flags
	 * @return int
	 * @throws ReadingListRepositoryException
	 */
	private function getEntryCount( $id, $flags = 0 ) {
		$this->assertUser();
		if ( ( $flags & IDBAccessObject::READ_LATEST ) == IDBAccessObject::READ_LATEST ) {
			$db = $this->dbw;
		} else {
			$db = $this->dbr;
		}
		return (int)$db->newSelectQueryBuilder()
			->select( 'rl_size' )
			->from( 'reading_list' )
			->where( [ 'rl_id' => $id ] )
			->recency( $flags )
			->caller( __METHOD__ )->fetchField();
	}

	/**
	 * Confirm that at least one project exists. Create one if necessary.
	 * Projects are global to a wiki/wiki farm. Wiki farms using the SiteMatrix
	 * extension should initialize their projects via maintenance script.
	 *
	 * @return bool True if projects were initialized.
	 */
	public function initializeProjectIfNeeded(): bool {
		if ( $this->hasProjects() ) {
			return false;
		}

		$project = $this->getLocalProject();
		if ( !$project ) {
			throw new LogicException( 'Unable to load canonical url for project initialization' );
		}

		$this->dbw->newInsertQueryBuilder()
			->insertInto( 'reading_list_project' )
			->row( [
				'rlp_project' => $project,
			] )
			->caller( __METHOD__ )->execute();

		return true;
	}

	/**
	 * Whether any projects have been registered for use with reading lists.
	 * If no projects have been registered, it will not be possible to
	 * add entries to lists.
	 */
	public function hasProjects(): bool {
		$count = $this->dbr->newSelectQueryBuilder()
			->select( 'COUNT(*)' )->from(
				'reading_list_project'
			)->caller( __METHOD__ )->fetchField();

		return $count > 0;
	}

	/**
	 * @return string
	 */
	private function getLocalProject(): string {
		$url = MediaWikiServices::getInstance()->getUrlUtils()->getCanonicalServer();
		if ( $url === '' ) {
			return '';
		}

		$parts = MediaWikiServices::getInstance()->getUrlUtils()->parse( $url );
		$parts['port'] = null;
		$project = MediaWikiServices::getInstance()->getUrlUtils()->assemble( $parts );

		return $project;
	}
}
