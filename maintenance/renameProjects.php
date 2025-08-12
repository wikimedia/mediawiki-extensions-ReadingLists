<?php

namespace MediaWiki\Extension\ReadingLists\Maintenance;

use MediaWiki\Extension\ReadingLists\Utils;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;
use MediaWiki\Utils\UrlUtils;
use Throwable;
use Wikimedia\Rdbms\IDatabase;

// @codeCoverageIgnoreStart
require_once getenv( 'MW_INSTALL_PATH' ) !== false
	? getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php'
	: __DIR__ . '/../../../maintenance/Maintenance.php';
// @codeCoverageIgnoreEnd

/**
 * Script to rename projects in the reading_list_project table.
 *
 * Suffix replacement for all projects urls ending with beta.wmflabs.org:
 * php renameProjects.php --from=beta.wmflabs.org --to=beta.wmcloud.org --batch-size=50
 *
 * Example for full URL replacement:
 * php renameProjects.php --from=https://en.wikipedia.beta.wmflabs.org --to=https://en.wikipedia.beta.wmcloud.org
 *
 * Example for dry run to get a preview of the changes that would be made:
 * php renameProjects.php --from=beta.wmflabs.org --to=beta.wmcloud.org --dry-run
 */
class RenameProjects extends Maintenance {

	private const STATUS_ERROR = 'error';
	private const STATUS_UPDATED = 'updated';

	private const MODE_FULL_URL = 'full-url';
	private const MODE_DOMAIN_SUFFIX = 'domain-suffix';

	private ?UrlUtils $urlUtils = null;

	private string $mode = self::MODE_DOMAIN_SUFFIX;

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Update domains in the rlp_project column in the reading_list_project table.' );

		$this->addOption(
			'from',
			'URL or suffix to replace (e.g. https://enwiki.beta.wmflabs.org or beta.wmflabs.org)',
			true,
			true
		);

		$this->addOption(
			'to',
			'URL or suffix to replace with (e.g. https://enwiki.beta.wmcloud.org or beta.wmcloud.org)',
			true,
			true
		);

		$this->addOption( 'dry-run', 'Test and preview the changes that would be made', false, false );
		$this->addOption( 'batch-size', 'Number of records to process in each batch (default: 100)', false, true );
	}

	public function execute() {
		$this->urlUtils = MediaWikiServices::getInstance()->getUrlUtils();

		$from = $this->getOption( 'from' );
		$to = $this->getOption( 'to' );
		$dryRun = $this->hasOption( 'dry-run' );
		$batchSize = (int)$this->getOption( 'batch-size', 100 );

		if ( $batchSize <= 0 ) {
			$this->fatalError( 'Batch size must be a positive integer.' );
		}

		// Determine mode based on whether from and to are valid URLs
		$fromParts = $this->urlUtils->parse( $from );
		$toParts = $this->urlUtils->parse( $to );

		// Use full URL mode if both from and to are valid URLs with schemes
		if ( $fromParts !== null && $toParts !== null ) {
			$this->mode = self::MODE_FULL_URL;
		}

		$stats = $this->updateProjects( $from, $to, $dryRun, $batchSize );

		if ( $dryRun ) {
			$this->output( "Dry run complete. \n"
				. "Projects to update: {$stats['updated']}, "
				. "Skipped: {$stats['skipped']}, "
				. "Errors: {$stats['errors']}\n" );
		} else {
			$this->output( "Done. Updated: {$stats['updated']}, "
				. "Skipped: {$stats['skipped']}, Errors: {$stats['errors']}\n" );
		}
	}

	private function updateProjects(
		string $from,
		string $to,
		bool $dryRun,
		int $batchSize
	): array {
		$skipped = 0;
		$updated = 0;
		$errors = 0;

		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
		$dbw = $lbFactory->getPrimaryDatabase( Utils::VIRTUAL_DOMAIN );
		$projectCount = $dbw->selectRowCount(
			'reading_list_project',
			'*',
			[],
			__METHOD__
		);

		try {
			$offset = 0;
			while ( $offset < $projectCount ) {
				$this->beginTransactionRound( __METHOD__ );

				$batchStats = $this->processBatch(
					$dbw,
					$from,
					$to,
					$dryRun,
					$batchSize,
					$offset
				);

				$skipped += $batchStats['skipped'];
				$updated += $batchStats['updated'];
				$errors += $batchStats['errors'];
				$offset += $batchSize;

				$this->commitTransactionRound( __METHOD__ );

				if ( $batchStats['processed'] === 0 ) {
					break;
				}
			}

		} catch ( Throwable $e ) {
			$this->fatalError( 'Error during update: ' . $e->getMessage() );
		}

		return [
			'skipped' => $skipped,
			'updated' => $updated,
			'errors' => $errors
		];
	}

	private function processBatch(
		IDatabase $dbw,
		string $from,
		string $to,
		bool $dryRun,
		int $batchSize,
		int $offset
	): array {
		$res = $dbw->select(
			'reading_list_project',
			[ 'rlp_id', 'rlp_project' ],
			[],
			__METHOD__,
			[
				'LIMIT' => $batchSize,
				'OFFSET' => $offset,
				'ORDER BY' => 'rlp_id'
			]
		);

		$processed = 0;
		$skipped = 0;
		$updated = 0;
		$errors = 0;

		foreach ( $res as $row ) {
			$processed++;
			$isMatch = $this->isMatch( $row->rlp_project, $from );
			if ( !$isMatch ) {
				$skipped++;
				continue;
			}

			$result = $this->processProject( $dbw, $row, $from, $to, $dryRun );

			if ( $result === self::STATUS_UPDATED ) {
				$updated++;
			} elseif ( $result === self::STATUS_ERROR ) {
				$errors++;
			}
		}

		return [
			'processed' => $processed,
			'skipped' => $skipped,
			'updated' => $updated,
			'errors' => $errors
		];
	}

	private function processProject(
		IDatabase $dbw,
		object $row,
		string $from,
		string $to,
		bool $dryRun
	): string {
		$oldUrl = $row->rlp_project;
		$newUrl = $this->generateNewUrl( $oldUrl, $from, $to );

		if ( $this->urlUtils->parse( $newUrl ) === null ) {
			$this->error( "Invalid URL generated: $newUrl" );
			return self::STATUS_ERROR;
		}
		$updateMessagePrefix = $dryRun ? 'Project to update:' : 'Updating project:';

		$this->output( "$updateMessagePrefix {$row->rlp_id}: $oldUrl -> $newUrl\n" );

		if ( $dryRun ) {
			return self::STATUS_UPDATED;
		}

		try {
			$dbw->update(
				'reading_list_project',
				[ 'rlp_project' => $newUrl ],
				[ 'rlp_id' => $row->rlp_id ],
				__METHOD__
			);
			return self::STATUS_UPDATED;
		} catch ( Throwable $e ) {
			$this->error( "Failed to update project {$row->rlp_id}: " . $e->getMessage() );
			return self::STATUS_ERROR;
		}
	}

	private function isMatch( string $url, string $from ): bool {
		if ( $this->mode === self::MODE_FULL_URL ) {
			return $url === $from;
		}

		$urlParts = $this->urlUtils->parse( $url );
		if ( $urlParts === null || !isset( $urlParts['host'] ) ) {
			return false;
		}

		return str_ends_with( $urlParts['host'], '.' . $from );
	}

	private function generateNewUrl( string $oldUrl, string $from, string $to ): string {
		if ( $this->mode === self::MODE_FULL_URL ) {
			return $to;
		}

		return substr( $oldUrl, 0, -strlen( $from ) ) . $to;
	}

}

$maintClass = RenameProjects::class;
require_once RUN_MAINTENANCE_IF_MAIN;
