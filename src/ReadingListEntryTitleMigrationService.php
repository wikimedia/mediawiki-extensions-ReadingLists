<?php

namespace MediaWiki\Extension\ReadingLists;

use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\LBFactory;
use Wikimedia\Rdbms\RawSQLValue;

/**
 * Maintenance-only migration of legacy reading_list_entry.rle_title values (spaces to underscores).
 *
 * @see ReadingListRepository::migrateNormalizeEntryTitles() which delegates here.
 */
class ReadingListEntryTitleMigrationService {

	public function __construct(
		private readonly IDatabase $dbw,
		private readonly LBFactory $lbFactory
	) {
	}

	/**
	 * Migrate legacy list entry titles that contain spaces to underscore form,
	 * and soft-delete space-form rows when an active underscore duplicate exists (ADR 0003 migration).
	 *
	 * @param int|null $listId Process only entries in this list, or null for all non-deleted lists.
	 * @param int $batchSize Rows to fetch per batch.
	 * @param bool $dryRun If true, compute stats without writing to the database.
	 * @param int|null $limit Stop after processing this many rows.
	 * @return int[] Counters: updated, soft_deleted, blocked_by_soft_deleted, skipped
	 */
	public function migrateNormalizeEntryTitles(
		?int $listId = null,
		int $batchSize = 1000,
		bool $dryRun = false,
		?int $limit = null
	): array {
		$stats = [
			'updated' => 0,
			'soft_deleted' => 0,
			'blocked_by_soft_deleted' => 0,
			'skipped' => 0,
		];

		$maxId = 0;
		$rowsProcessed = 0;

		$rleTitleContainsSpace = 'rle.rle_title' . $this->dbw->buildLike(
			$this->dbw->anyString(),
			' ',
			$this->dbw->anyString()
		);

		while ( true ) {
			$queryBuilder = $this->dbw->newSelectQueryBuilder()
				->select( [ 'rle.rle_id', 'rle.rle_rl_id', 'rle.rle_rlp_id', 'rle.rle_title' ] )
				->from( 'reading_list_entry', 'rle' )
				->join( 'reading_list', 'rl', 'rl.rl_id = rle.rle_rl_id' )
				->where( [
					'rle.rle_deleted' => 0,
					'rl.rl_deleted' => 0,
					$rleTitleContainsSpace,
					$this->dbw->expr( 'rle.rle_id', '>', $maxId ),
				] );
			if ( $listId !== null ) {
				$queryBuilder->andWhere( [ 'rle.rle_rl_id' => $listId ] );
			}

			$batch = iterator_to_array(
				$queryBuilder
					->orderBy( 'rle.rle_id', 'ASC' )
					->limit( $batchSize )
					->caller( __METHOD__ )
					->fetchResultSet(),
				false
			);

			if ( !$batch ) {
				break;
			}

			foreach ( $batch as $row ) {
				if ( $limit !== null && $rowsProcessed >= $limit ) {
					break 2;
				}
				$maxId = (int)$row->rle_id;
				if ( !$dryRun ) {
					$this->dbw->startAtomic( __METHOD__ );
				}
				try {
					$rowStats = $this->migrateNormalizeOneEntryTitle(
						(int)$row->rle_id,
						(int)$row->rle_rl_id,
						(int)$row->rle_rlp_id,
						(string)$row->rle_title,
						$dryRun
					);
					foreach ( $rowStats as $key => $n ) {
						$stats[$key] += $n;
					}
				} finally {
					if ( !$dryRun ) {
						$this->dbw->endAtomic( __METHOD__ );
					}
				}
				$rowsProcessed++;
			}

			if ( !$dryRun ) {
				$this->lbFactory->waitForReplication();
			}
		}

		return $stats;
	}

	/**
	 * @param int $rleId
	 * @param int $listId rle_rl_id
	 * @param int $projectId rle_rlp_id
	 * @param string $title Current rle_title (must contain a space)
	 * @param bool $dryRun If true, do not write; still returns the stats that would apply.
	 * @return int[] Per-row stats: updated, soft_deleted, blocked_by_soft_deleted, skipped
	 */
	private function migrateNormalizeOneEntryTitle(
		int $rleId,
		int $listId,
		int $projectId,
		string $title,
		bool $dryRun = false
	): array {
		$empty = [
			'updated' => 0,
			'soft_deleted' => 0,
			'blocked_by_soft_deleted' => 0,
			'skipped' => 0,
		];

		$normalized = strtr( $title, ' ', '_' );
		if ( $normalized === $title ) {
			$empty['skipped'] = 1;
			return $empty;
		}

		ReadingListRepository::assertFieldLength( 'rle_title', $normalized );

		// Re-read the row we are about to change, and lock it so nothing else can
		// modify it until this atomic section finishes (FOR UPDATE).
		$currentQuery = $this->dbw->newSelectQueryBuilder()
			->select( [ 'rle_title', 'rle_deleted' ] )
			->from( 'reading_list_entry' )
			->where( [ 'rle_id' => $rleId ] );
		if ( !$dryRun ) {
			$currentQuery->forUpdate();
		}
		$current = $currentQuery->caller( __METHOD__ )->fetchRow();
		if (
			!$current
			|| (int)$current->rle_deleted !== 0
			|| $current->rle_title !== $title
		) {
			$empty['skipped'] = 1;
			return $empty;
		}

		// Another live entry with the normalized title already exists for this list+project:
		// we must not create a duplicate; soft-delete this row instead (see ADR 0003).
		$activeDupId = $this->dbw->newSelectQueryBuilder()
			->select( 'rle_id' )
			->from( 'reading_list_entry' )
			->where( [
				'rle_rl_id' => $listId,
				'rle_rlp_id' => $projectId,
				'rle_title' => $normalized,
				'rle_deleted' => 0,
				$this->dbw->expr( 'rle_id', '!=', $rleId ),
			] )
			->caller( __METHOD__ )->fetchField();

		if ( $activeDupId !== false ) {
			if ( !$dryRun ) {
				$this->dbw->newUpdateQueryBuilder()
					->update( 'reading_list_entry' )
					->set( [
						'rle_deleted' => 1,
						'rle_date_updated' => $this->dbw->timestamp(),
					] )
					->where( [ 'rle_id' => $rleId ] )
					->caller( __METHOD__ )->execute();

				if ( $this->dbw->affectedRows() ) {
					$this->dbw->newUpdateQueryBuilder()
						->update( 'reading_list' )
						->set( [ 'rl_size' => new RawSQLValue( 'rl_size - 1' ) ] )
						->where( [
							'rl_id' => $listId,
							$this->dbw->expr( 'rl_size', '>', 0 ),
						] )
						->caller( __METHOD__ )->execute();
				}
			}

			$empty['soft_deleted'] = 1;
			return $empty;
		}

		// An already soft-deleted row with this normalized title blocks the rename.
		// Leave it for purge.php and retry normalization after deleted-row retention has elapsed.
		$blockerId = $this->dbw->newSelectQueryBuilder()
			->select( 'rle_id' )
			->from( 'reading_list_entry' )
			->where( [
				'rle_rl_id' => $listId,
				'rle_rlp_id' => $projectId,
				'rle_title' => $normalized,
				'rle_deleted' => 1,
				$this->dbw->expr( 'rle_id', '!=', $rleId ),
			] )
			->caller( __METHOD__ )->fetchField();

		if ( $blockerId !== false ) {
			$empty['blocked_by_soft_deleted'] = 1;
			$empty['skipped'] = 1;
			return $empty;
		}

		if ( !$dryRun ) {
			$this->dbw->newUpdateQueryBuilder()
				->update( 'reading_list_entry' )
				->set( [
					'rle_title' => $normalized,
					'rle_date_updated' => $this->dbw->timestamp(),
				] )
				->where( [ 'rle_id' => $rleId ] )
				->caller( __METHOD__ )->execute();
		}

		$updated = $dryRun ? 1 : (int)$this->dbw->affectedRows();
		return [
			'updated' => $updated,
			'soft_deleted' => 0,
			'blocked_by_soft_deleted' => 0,
			'skipped' => $updated ? 0 : 1,
		];
	}
}
