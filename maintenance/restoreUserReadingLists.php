<?php

namespace MediaWiki\Extension\ReadingLists\Maintenance;

use MediaWiki\Extension\ReadingLists\Doc\ReadingListRow;
use MediaWiki\Extension\ReadingLists\ReadingListRepository;
use MediaWiki\Extension\ReadingLists\Utils;
use MediaWiki\Maintenance\Maintenance;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IDBAccessObject;

// @codeCoverageIgnoreStart
require_once getenv( 'MW_INSTALL_PATH' ) !== false
	? getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php'
	: __DIR__ . '/../../../maintenance/Maintenance.php';
// @codeCoverageIgnoreEnd

/**
 * Script to allow restoring reading lists affected by user teardown.
 *
 * Background: a "teardown" (ReadingListRepository::teardownForUser) soft-deletes
 * all of a user's reading lists: each row gets rl_deleted = 1 and is renamed to
 * "deleted-{original name}-{32 random hex chars}".
 *
 * Due to T400297, a user's lists may be torn down multiple times, so a list
 * might be renamed multiple times.
 * (e.g. "deleted-deleted-{original name}-{32 random hex chars}-{32 random hex chars}").
 *
 * NOTE: Soft-deleted rows are purged via a daily cron job after a retention period.
 *
 * All restored lists come back as custom (non-default) lists, renamed with a
 * date suffix if their original name is already taken. The script makes sure
 * the user has a default list, creating an empty one if needed.
 *
 * Typical workflow:
 *   1. --central-id=N --dry-run           lists the restorable deleted lists
 *   2. --central-id=N --list-ids=1,2,3 --dry-run   previews the selected restore
 *   3. --central-id=N --list-ids=1,2,3    performs the restore
 *
 * @ingroup Maintenance
 */
class RestoreUserReadingLists extends Maintenance {

	/**
	 * Matches the rename applied on deletion ("deleted-{name}-{32 hex chars}"),
	 * capturing the original name so the restore can undo it.
	 */
	private const DELETED_LIST_NAME_PATTERN = '/\Adeleted-(.*)-[0-9a-f]{32}\z/sD';

	/** Primary connection for the reading lists virtual domain */
	private IDatabase $dbw;

	/** Central ID of the user whose lists are being restored */
	private int $centralId;

	/**
	 * Restore plan for the selected lists, keyed by list ID.
	 * @var array<int,array{name:string,was_default:bool}>
	 */
	private array $plan;

	public function __construct() {
		parent::__construct();
		$this->addDescription( "Restore a user's reading lists after a teardown." );
		$this->addOption(
			'central-id',
			'Central user ID whose reading lists should be restored',
			true,
			true
		);
		$this->addOption(
			'list-ids',
			'Comma-separated IDs of the deleted lists to restore',
			false,
			true
		);
		$this->addOption(
			'dry-run',
			'Report recoverable or selected lists without changing the database',
			false,
			false
		);
		$this->requireExtension( 'ReadingLists' );
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$this->centralId = $this->getValidatedCentralId();
		$listIds = $this->getValidatedListIds();
		$dryRun = $this->hasOption( 'dry-run' );
		if ( !$listIds && !$dryRun ) {
			$this->fatalError(
				'At least one list ID is required in --list-ids unless --dry-run is used.'
			);
		}

		$this->dbw = $this->getServiceContainer()
			->getDBLoadBalancerFactory()
			->getPrimaryDatabase( Utils::VIRTUAL_DOMAIN );

		if ( !$listIds ) {
			$this->showCandidates();
			return;
		}

		if ( $dryRun ) {
			$rows = $this->getListRows( false );
			$this->plan = $this->buildRestorePlan( $rows, $listIds );
		} else {
			// Wrap the whole restore in one transaction with the user's rows locked
			// (via forUpdate() in getListRows), so it is all-or-nothing and
			// concurrent writes for this user (e.g. a sync client) wait until done.
			$this->beginTransactionRound( __METHOD__ );
			try {
				$rows = $this->getListRows( true );
				$this->plan = $this->buildRestorePlan( $rows, $listIds );
				if ( $this->plan ) {
					$this->ensureDefaultList();
					$this->applyRestore();
				}
			} catch ( \Throwable $e ) {
				$this->rollbackTransactionRound( __METHOD__ );
				if ( $e instanceof \UnexpectedValueException ) {
					$this->fatalError( $e->getMessage() );
				}
				throw $e;
			}
			$this->commitTransactionRound( __METHOD__ );
		}

		$this->showPlan( $dryRun, $rows );
		if ( $dryRun ) {
			$this->output( "Dry run: no changes made.\n" );
		} elseif ( $this->plan ) {
			$this->output( "Reading lists restored.\n" );
		} else {
			$this->output( "Nothing to restore.\n" );
		}
	}

	private function getValidatedCentralId(): int {
		$centralId = $this->getOption( 'central-id' );
		if ( !ctype_digit( (string)$centralId ) || (int)$centralId <= 0 ) {
			$this->fatalError( 'Central ID must be a positive integer.' );
		}
		return (int)$centralId;
	}

	/**
	 * @return int[] List IDs from the comma-separated --list-ids option
	 */
	private function getValidatedListIds(): array {
		$listIds = array_map( 'trim', explode( ',', (string)$this->getOption( 'list-ids', '' ) ) );
		$listIds = array_filter( $listIds, static fn ( $listId ) => $listId !== '' );
		foreach ( $listIds as $listId ) {
			if ( !ctype_digit( $listId ) || (int)$listId <= 0 ) {
				$this->fatalError( 'List IDs must be positive integers.' );
			}
		}
		return array_values( array_unique( array_map( 'intval', $listIds ) ) );
	}

	/**
	 * Make sure the user has a default list, creating an empty one if needed,
	 * so that restored lists can always be plain custom lists.
	 */
	private function ensureDefaultList(): void {
		$repository = new ReadingListRepository(
			$this->centralId,
			$this->getServiceContainer()->getDBLoadBalancerFactory()
		);
		if ( $repository->getDefaultListIdForUser( IDBAccessObject::READ_LOCKING ) ) {
			return;
		}
		$repository->setupForUser( true );
		$this->output( "Created a new empty default list.\n" );
	}

	private function showCandidates(): void {
		$rows = $this->getListRows( false );
		$this->output( "Recoverable deleted lists for central ID {$this->centralId}:\n" );
		$count = 0;
		foreach ( $rows as $row ) {
			if ( !$row->rl_deleted ) {
				continue;
			}
			$decodedName = self::decodeDeletedListName( (string)$row->rl_name );
			if ( $decodedName['name'] === null ) {
				continue;
			}
			$type = $row->rl_is_default ? 'default' : 'custom';
			// Identify lists by metadata only; list names are private user data.
			$this->output(
				"  {$row->rl_id} ($type, deletion depth {$decodedName['depth']}, "
				. "{$row->rl_size} entries, created {$row->rl_date_created})\n"
			);
			$count++;
		}
		if ( !$count ) {
			$this->output( "  none\n" );
		}
		$this->output( "Run again with --list-ids=<id>[,<id>...] to restore lists.\n" );
		$this->output( "Dry run: no changes made.\n" );
	}

	/**
	 * @param bool $lock Whether to lock the rows until the transaction ends
	 * @return ReadingListRow[] All of the user's lists, deleted and active
	 */
	private function getListRows( bool $lock ): array {
		$query = $this->dbw->newSelectQueryBuilder()
			->select( [
				'rl_id',
				'rl_is_default',
				'rl_name',
				'rl_deleted',
				'rl_size',
				'rl_date_created',
			] )
			->from( 'reading_list' )
			->where( [ 'rl_user_id' => $this->centralId ] )
			->orderBy( 'rl_id' )
			->caller( __METHOD__ );
		if ( $lock ) {
			$query->forUpdate();
		}
		$rows = [];
		foreach ( $query->fetchResultSet() as $row ) {
			/** @var ReadingListRow $row */
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * @param ReadingListRow[] $rows
	 */
	private static function hasActiveDefaultList( array $rows ): bool {
		foreach ( $rows as $row ) {
			if ( !$row->rl_deleted && $row->rl_is_default ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param ReadingListRow[] $rows
	 * @param int[] $listIds
	 * @return array<int,array{name:string,was_default:bool}>
	 */
	private function buildRestorePlan( array $rows, array $listIds ): array {
		$rowsById = [];
		$activeRows = [];
		foreach ( $rows as $row ) {
			$rowsById[(int)$row->rl_id] = $row;
			if ( !$row->rl_deleted ) {
				$activeRows[] = $row;
			}
		}
		$lists = $this->selectListsToRestore( $rowsById, $listIds );

		return $this->ensureUniqueNames( $lists, $activeRows );
	}

	/**
	 * Validate the selected list IDs and decode their original names.
	 *
	 * @param array<int,ReadingListRow> $rowsById
	 * @param int[] $listIds
	 * @return array<int,array{name:string,was_default:bool}>
	 */
	private function selectListsToRestore( array $rowsById, array $listIds ): array {
		$lists = [];
		foreach ( $listIds as $listId ) {
			$row = $rowsById[$listId] ?? null;
			if ( !$row ) {
				$this->output(
					"List $listId was not found for central ID {$this->centralId}; skipping.\n"
				);
				continue;
			}
			if ( !$row->rl_deleted ) {
				$this->output( "List $listId is already active; skipping.\n" );
				continue;
			}

			$decodedName = self::decodeDeletedListName( (string)$row->rl_name );
			if ( $decodedName['name'] === null ) {
				throw new \UnexpectedValueException(
					"Cannot restore list $listId because its original name is not recoverable."
				);
			}
			$lists[$listId] = [
				'name' => $decodedName['name'],
				'was_default' => (bool)$row->rl_is_default,
			];
		}

		return $lists;
	}

	/**
	 * Give each restored list a name that collides neither with an active list
	 * nor with another restored list.
	 *
	 * @param array<int,array{name:string,was_default:bool}> $lists
	 * @param ReadingListRow[] $activeRows
	 * @return array<int,array{name:string,was_default:bool}>
	 */
	private function ensureUniqueNames( array $lists, array $activeRows ): array {
		$names = [];
		foreach ( $activeRows as $row ) {
			$names[(string)$row->rl_name] = true;
		}
		if ( $lists && !self::hasActiveDefaultList( $activeRows ) ) {
			// Reserve the name the created default list will take.
			$names['default'] = true;
		}
		$date = substr( $this->dbw->timestamp(), 0, 8 );
		foreach ( $lists as $listId => $list ) {
			$lists[$listId]['name'] = $this->makeUniqueName(
				$list['name'],
				$date,
				$listId,
				$names
			);
		}

		return $lists;
	}

	/**
	 * @param string $name The decoded original name
	 * @param string $date Restore date as YYYYMMDD, used as a rename suffix
	 * @param int $listId
	 * @param array<array-key,bool> &$usedNames Names already taken; the chosen name is added
	 * @return string
	 */
	private function makeUniqueName(
		string $name,
		string $date,
		int $listId,
		array &$usedNames
	): string {
		if ( !isset( $usedNames[$name] ) ) {
			$usedNames[$name] = true;
			return $name;
		}

		for ( $attempt = 0; ; $attempt++ ) {
			$suffix = "-$date";
			if ( $attempt > 0 ) {
				$suffix .= "-$listId";
			}
			if ( $attempt > 1 ) {
				$suffix .= "-$attempt";
			}
			$maxNameBytes = ReadingListRepository::$fieldLength['rl_name'] - strlen( $suffix );
			$candidate = mb_strcut( $name, 0, $maxNameBytes, 'UTF-8' ) . $suffix;
			if ( !isset( $usedNames[$candidate] ) ) {
				$usedNames[$candidate] = true;
				return $candidate;
			}
		}
	}

	/**
	 * @param string $name The mangled rl_name of a deleted list
	 * @return array{name:?string,depth:int} Original name (null if not recoverable)
	 *   and the number of deletion renames that were unwrapped
	 */
	private static function decodeDeletedListName( string $name ): array {
		$depth = 0;
		while ( preg_match( self::DELETED_LIST_NAME_PATTERN, $name, $matches ) === 1 ) {
			$name = $matches[1];
			$depth++;
		}
		return $depth
			? [ 'name' => $name, 'depth' => $depth ]
			: [ 'name' => null, 'depth' => 0 ];
	}

	private function applyRestore(): void {
		$now = $this->dbw->timestamp();
		foreach ( $this->plan as $listId => $list ) {
			$this->dbw->newUpdateQueryBuilder()
				->update( 'reading_list' )
				->set( [
					'rl_name' => $list['name'],
					'rl_is_default' => 0,
					'rl_deleted' => 0,
					'rl_date_updated' => $now,
				] )
				->where( [
					'rl_id' => $listId,
					'rl_deleted' => 1,
				] )
				->caller( __METHOD__ )
				->execute();
			if ( $this->dbw->affectedRows() !== 1 ) {
				throw new \UnexpectedValueException( "List $listId changed during restoration." );
			}
		}

		// Touch the surviving entries of the restored lists so sync clients,
		// which fetch changes by timestamp, re-download them.
		$this->dbw->newUpdateQueryBuilder()
			->update( 'reading_list_entry' )
			->set( [ 'rle_date_updated' => $now ] )
			->where( [
				'rle_rl_id' => array_keys( $this->plan ),
				'rle_deleted' => 0,
			] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * @param bool $dryRun
	 * @param ReadingListRow[] $rows
	 */
	private function showPlan( bool $dryRun, array $rows ): void {
		$this->output( "Central ID: {$this->centralId}\n" );
		if ( $dryRun && $this->plan && !self::hasActiveDefaultList( $rows ) ) {
			$this->output( "An empty default list will be created.\n" );
		}
		$this->output( 'Lists to restore: ' . count( $this->plan ) . "\n" );
		foreach ( $this->plan as $listId => $list ) {
			$type = $list['was_default'] ? 'former default, restored as custom' : 'custom';
			$this->output( "  $listId ($type)\n" );
		}
	}
}

// @codeCoverageIgnoreStart
$maintClass = RestoreUserReadingLists::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
