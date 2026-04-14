<?php

namespace MediaWiki\Extension\ReadingLists\Maintenance;

use MediaWiki\Extension\ReadingLists\Utils;
use MediaWiki\Maintenance\Maintenance;

// @codeCoverageIgnoreStart
require_once getenv( 'MW_INSTALL_PATH' ) !== false
	? getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php'
	: __DIR__ . '/../../../maintenance/Maintenance.php';
// @codeCoverageIgnoreEnd

/**
 * One-time migration: normalize reading_list_entry.rle_title (spaces to underscores) and
 * soft-delete space-form duplicates when an underscore twin exists (ADR 0003).
 */
class NormalizeEntryTitles extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'Normalize reading list entry titles: spaces to underscores; soft-delete duplicate space-form rows.'
		);
		$this->addOption( 'list', 'Only process entries in this reading_list.rl_id', false, true );
		$this->addOption( 'batch-size', 'Rows per batch (default: 1000)', false, true );
		$this->addOption( 'dry-run', 'Report what would change without writing to the database', false, false );
		$this->addOption( 'limit', 'Process at most this many rows', false, true );
		$this->requireExtension( 'ReadingLists' );
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$batchSize = (int)$this->getOption( 'batch-size', 1000 );
		$listId = $this->hasOption( 'list' ) ? (int)$this->getOption( 'list' ) : null;
		$dryRun = $this->hasOption( 'dry-run' );
		$limit = $this->hasOption( 'limit' ) ? (int)$this->getOption( 'limit' ) : null;
		if ( $limit !== null && $limit < 1 ) {
			$this->fatalError( 'Invalid --limit: must be a positive integer' );
		}

		$repo = Utils::makeMaintenanceRepository();
		if ( $dryRun ) {
			$this->output( "DRY RUN (no database writes)\n" );
		}
		$stats = $repo->migrateNormalizeEntryTitles( $listId, $batchSize, $dryRun, $limit );

		$this->output(
			"updated: {$stats['updated']}, soft_deleted: {$stats['soft_deleted']}, "
			. "blocked_by_soft_deleted: {$stats['blocked_by_soft_deleted']}, skipped: {$stats['skipped']}\n"
		);
	}

}

// @codeCoverageIgnoreStart
$maintClass = NormalizeEntryTitles::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
