<?php

namespace MediaWiki\Extension\ReadingLists\Maintenance;

use MediaWiki\Extension\ReadingLists\Constants;
use MediaWiki\Extension\ReadingLists\Service\UserPreferenceBatchUpdater;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserIdentityLookup;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\IResultWrapper;
use Wikimedia\Rdbms\SelectQueryBuilder;

require_once getenv( 'MW_INSTALL_PATH' ) !== false
	? getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php'
	: __DIR__ . '/../../../maintenance/Maintenance.php';

/**
 * Copy readinglists-web-ui-enabled preference to readinglistsbeta for all users
 * who have it. The old preference is not deleted; use
 * maintenance/userOptions.php --delete to clean it up afterward.
 *
 * See T414370
 *
 * Usage:
 *  php setBetaPreference.php [--dry-run] [--verbose]
 *  php setBetaPreference.php --batch-size 100 --limit 5000
 *
 * @ingroup Maintenance
 */
class SetBetaPreference extends Maintenance {

	private const DEFAULT_BATCH_SIZE = 500;

	/** @var UserPreferenceBatchUpdater */
	private $batchUpdater = null;

	/** @var UserIdentityLookup */
	private $userIdentityLookup = null;

	private bool $dryRun = false;

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'Copy readinglists-web-ui-enabled preference to readinglistsbeta=1 for all users '
			. 'who have it. The old preference is not deleted. '
			. 'No input file is needed; users are discovered from user_properties.'
		);

		$this->addOption(
			'batch-size',
			'How many users to process in each batch (default: 500)',
			false,
			true
		);
		$this->addOption(
			'dry-run',
			'Dry run. Does not update any preferences.',
			false,
			false
		);
		$this->addOption(
			'limit',
			'Maximum number of users to process (default: no limit)',
			false,
			true
		);
		$this->addOption(
			'verbose',
			'Show detailed output for each user processed',
			false,
			false
		);

		$this->requireExtension( 'ReadingLists' );
	}

	private function isVerbose(): bool {
		return $this->hasOption( 'verbose' );
	}

	private function initializeServices(): void {
		$services = MediaWikiServices::getInstance();

		$this->batchUpdater = $services->get( 'UserPreferenceBatchUpdater' );
		$this->userIdentityLookup = $services->getUserIdentityLookup();
	}

	public function execute() {
		$this->initializeServices();
		$this->dryRun = $this->hasOption( 'dry-run' );

		$batchSize = (int)$this->getOption( 'batch-size', self::DEFAULT_BATCH_SIZE );
		$limit = $this->getOption( 'limit' ) !== null ? (int)$this->getOption( 'limit' ) : null;

		$dbr = $this->getReplicaDB();

		$copied = 0;
		$skippedNotFound = 0;
		$processed = 0;
		$fromUserId = 0;

		$prefix = $this->dryRun ? '[dry run] ' : '';
		$this->output( "{$prefix}Copying preference '"
			. Constants::PREF_KEY_WEB_UI_ENABLED . "' to '"
			. Constants::PREF_KEY_BETA_FEATURES . "'...\n" );

		$batchCount = 0;
		$hasMoreToProcess = true;
		while ( $hasMoreToProcess ) {
			$batchCount++;
			$this->beginTransactionRound( __METHOD__ );

			$result = $this->fetchEligibleUserBatch( $dbr, $fromUserId, $batchSize );
			$hasMoreToProcess = $result->numRows() > 0;

			foreach ( $result as $row ) {
				if ( $limit !== null && $processed >= $limit ) {
					$hasMoreToProcess = false;
					break;
				}

				$fromUserId = (int)$row->up_user;

				if ( $this->processUser( $fromUserId, $prefix ) ) {
					$processed++;
					if ( $this->dryRun ) {
						$copied++;
					}
				} else {
					$skippedNotFound++;
				}
			}

			if ( !$this->dryRun && $this->batchUpdater->hasPendingUpdates() ) {
				try {
					$copied += $this->batchUpdater->executeBatchUpdate();
				} catch ( \Exception $e ) {
					$this->rollbackTransactionRound( __METHOD__ );
					$this->fatalError(
						"FATAL ERROR: batch update failed: " . $e->getMessage() . "\n"
					);
				}
			}

			if ( $batchCount % 10 === 0 ) {
				$this->output( "{$prefix}Progress: processed $processed, "
					. "copied $copied, skipped $skippedNotFound (not found) "
					. "(last user ID: $fromUserId)\n" );
			}

			$this->commitTransactionRound( __METHOD__ );

			if ( $limit !== null && $processed >= $limit ) {
				$hasMoreToProcess = false;
				break;
			}
		}

		$copiedLabel = $this->dryRun ? 'would copy' : 'copied';
		$this->output( "{$prefix}Done! Processed $processed users, "
			. "$copiedLabel $copied, skipped $skippedNotFound (not found).\n" );
	}

	/**
	 * Fetch the next batch of users who have the old preference but not
	 * yet the beta preference.
	 */
	private function fetchEligibleUserBatch(
		IReadableDatabase $dbr,
		int $fromUserId,
		int $batchSize
	): IResultWrapper {
		return $dbr->newSelectQueryBuilder()
			->select( [ 'src.up_user' ] )
			->from( 'user_properties', 'src' )
			->leftJoin( 'user_properties', 'beta', [
				'beta.up_user = src.up_user',
				'beta.up_property' => Constants::PREF_KEY_BETA_FEATURES,
			] )
			->where( [
				'src.up_property' => Constants::PREF_KEY_WEB_UI_ENABLED,
				'beta.up_user' => null,
				$dbr->expr( 'src.up_user', '>', $fromUserId ),
			] )
			->orderBy( 'src.up_user', SelectQueryBuilder::SORT_ASC )
			->limit( $batchSize )
			->caller( __METHOD__ )
			->fetchResultSet();
	}

	/**
	 * @param int $userId
	 * @param string $prefix Output prefix (e.g. '[dry run] ')
	 * @return bool True if the user was processed, false if skipped (not found).
	 */
	private function processUser( int $userId, string $prefix ): bool {
		$user = $this->userIdentityLookup->getUserIdentityByUserId( $userId );

		if ( !$user || !$user->isRegistered() ) {
			if ( $this->isVerbose() ) {
				$this->output(
					"{$prefix}Skipping user ID $userId: not found or not registered\n"
				);
			}
			return false;
		}

		if ( $this->isVerbose() ) {
			$this->output(
				$prefix . ( $this->dryRun ? "Would set" : "Setting" )
				. " beta preference for user {$user->getId()}\n"
			);
		}

		if ( !$this->dryRun ) {
			$this->batchUpdater->addUserPreferenceToBatch(
				$user,
				Constants::PREF_KEY_BETA_FEATURES,
				'1'
			);
		}

		return true;
	}

}

$maintClass = SetBetaPreference::class;
require_once RUN_MAINTENANCE_IF_MAIN;
