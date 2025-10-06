<?php

namespace MediaWiki\Extension\ReadingLists\Maintenance;

use MediaWiki\Extension\ReadingLists\Constants;
use MediaWiki\Extension\ReadingLists\Service\UserPreferenceBatchUpdater;
use MediaWiki\Extension\ReadingLists\Validator\ReadingListPreferenceEligibilityValidator;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Maintenance\MaintenanceFatalError;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\CentralId\CentralIdLookup;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityLookup;
use MediaWiki\User\UserIdentityUtils;
use MediaWiki\User\UserOptionsManager;
use Psr\Log\LogLevel;

require_once getenv( 'MW_INSTALL_PATH' ) !== false
	? getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php'
	: __DIR__ . '/../../../maintenance/Maintenance.php';

/**
 * Maintenance script to set the reading list eligibility preference for users
 * who meet the experiment criteria. See T397532
 *
 * NOTE: User IDs should be provided one per line, and the script will sort
 * the user IDs before processing.
 *
 * Usage:
 *  php setReadingListHiddenPreference.php users.txt [--global-ids] [--verbose]
 *  echo -e "123\n456\n789" | php setReadingListHiddenPreference.php [--global-ids] [--verbose]
 *  php setReadingListHiddenPreference.php users.txt --start-id 1000 --to-id 2000 --batch-size 100 [--verbose]
 *  php setReadingListHiddenPreference.php users.txt --skip-verify [--dry-run]
 *
 * @ingroup Maintenance
 */
class SetReadingListHiddenPreference extends Maintenance {

	private const DEFAULT_BATCH_SIZE = 500;

	/**
	 * @var UserIdentityUtils
	 */
	private $userIdentityUtils = null;

	/**
	 * @var UserOptionsManager
	 */
	private $userOptionsManager = null;

	/** @var ReadingListPreferenceEligibilityValidator */
	private $eligibilityValidator = null;

	/** @var UserPreferenceBatchUpdater */
	private $batchUpdater = null;

	/** @var CentralIdLookup */
	private $centralIdLookup = null;

	/** @var UserIdentityLookup */
	private $userIdentityLookup = null;

	private int $enabled = 0;
	private int $processed = 0;
	private int $skippedAlreadySet = 0;
	private int $skippedNotEligible = 0;
	private int $skippedNotFound = 0;

	private bool $isGlobalIds = false;
	private bool $dryRun = false;
	private bool $verifyEligibility = true;

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'Set reading list web UI enabled hidden preference for a list of users. ' .
			'User IDs should be provided one per line.'
		);

		$this->addArg(
			'file',
			'File with user IDs (one per line). If not given, stdin will be used.',
			false
		);
		$this->addOption(
			'batch-size',
			'How many users to process in each batch (default: 500)',
			false,
			true
		);
		$this->addOption(
			'global-ids',
			'Input IDs are global/central IDs instead of local wiki user IDs',
			false,
			false
		);
		$this->addOption(
			'skip-verify',
			'Skip user eligibility verification (default is to verify)',
			false,
			false
		);
		$this->addOption(
			'dry-run',
			'Dry run. Does not update any preferences.',
			false,
			false
		);
		$this->addOption(
			'start-id',
			'Start from this user ID',
			false,
			true
		);
		$this->addOption(
			'to-id',
			'End at this user ID',
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

		$this->userIdentityUtils = $services->getUserIdentityUtils();
		$this->userOptionsManager = $services->getUserOptionsManager();
		$this->eligibilityValidator = $services->get( 'ReadingLists.ReadingListEligibilityValidator' );
		$this->batchUpdater = $services->get( 'UserPreferenceBatchUpdater' );
		$this->centralIdLookup = $services->getCentralIdLookupFactory()->getLookup();
		$this->userIdentityLookup = $services->getUserIdentityLookup();
	}

	/**
	 * @throws MaintenanceFatalError
	 */
	public function execute() {
		$this->initializeServices();

		$this->output( "Starting setReadingListHiddenPreference maintenance script\n" );

		$this->isGlobalIds = $this->hasOption( 'global-ids' );
		$this->dryRun = $this->hasOption( 'dry-run' );
		$this->verifyEligibility = !$this->hasOption( 'skip-verify' );

		if ( $this->hasArg( 0 ) ) {
			$input = fopen( $this->getArg( 0 ), 'r' );
			if ( !$input ) {
				$this->fatalError( "Unable to read file, exiting" );
			}
		} else {
			$input = $this->getStdin();

			// script expects piped input, not interactive terminal
			// handle same as in importDump.php
			if ( self::posix_isatty( $input ) ) {
				$this->fatalError(
					"No file argument provided and stdin is a terminal.\n" .
					"Please provide a file path or pipe user IDs to stdin.\n" .
					"Example: echo -e \"123\\n456\" | php setReadingListHiddenPreference.php\n"
				);
			}
		}

		$this->processInput( $input );
	}

	/**
	 * @param resource $file
	 * @throws MaintenanceFatalError
	 */
	private function processInput( $file ): void {
		$userIds = [];
		$lineNum = 0;

		for ( ; !feof( $file ); $lineNum++ ) {
			$line = trim( fgets( $file ) );
			if ( $line === '' ) {
				continue;
			}

			if ( !ctype_digit( $line ) ) {
				$this->outputInfo( "Invalid user ID '$line' on line $lineNum, skipping\n" );
				continue;
			}

			$userId = (int)$line;
			$userIds[] = $userId;
		}

		if ( !$userIds ) {
			$this->outputFatalError( 'ERROR: No valid user IDs provided' );
		}

		sort( $userIds, SORT_NUMERIC );
		$userIds = array_unique( $userIds, SORT_NUMERIC );
		$userIds = array_values( $userIds );

		$startId = $this->getOption( 'start-id' ) !== null ? (int)$this->getOption( 'start-id' ) : null;
		$toId = $this->getOption( 'to-id' ) !== null ? (int)$this->getOption( 'to-id' ) : null;

		if ( $startId !== null || $toId !== null ) {
			$userIds = $this->filterUserIdsByRange( $userIds, $startId, $toId );
		}

		$this->processBatches( $userIds );
	}

	/**
	 * @param int[] $userIds
	 * @param int|null $startId
	 * @param int|null $toId
	 * @return int[]
	 */
	private function filterUserIdsByRange( array $userIds, ?int $startId, ?int $toId ): array {
		return array_filter( $userIds, static function ( $id ) use ( $startId, $toId ) {
			if ( $startId !== null && $id < $startId ) {
				return false;
			}
			if ( $toId !== null && $id > $toId ) {
				return false;
			}
			return true;
		} );
	}

	/**
	 * @param int[] $userIds
	 */
	private function processBatches( array $userIds ): void {
		$batchSize = (int)$this->getOption( 'batch-size', self::DEFAULT_BATCH_SIZE );
		$totalUsers = count( $userIds );
		$batchCount = 0;

		foreach ( array_chunk( $userIds, $batchSize ) as $batch ) {
			$batchCount++;
			$this->beginTransactionRound( __METHOD__ );

			if ( $this->isVerbose() ) {
				$this->outputInfo( "Processing batch of " . count( $batch ) . " users...\n" );
			}

			foreach ( $batch as $userId ) {
				$this->processUser( $userId );
			}
			$this->processed += count( $batch );

			if ( $this->batchUpdater->hasPendingUpdates() ) {
				$this->doBatchUpdate( $batch );
			}

			$totalBatches = ceil( $totalUsers / $batchSize );
			if ( $batchCount % 10 === 0 || $batchCount === $totalBatches || $this->processed === $totalUsers ) {
				$this->outputProgress( $totalUsers );
			}

			$this->commitTransactionRound( __METHOD__ );
		}

		$skipped = $this->skippedAlreadySet + $this->skippedNotEligible + $this->skippedNotFound;
		$this->outputInfo( "Done! Processed $this->processed users, set preference for $this->enabled users.\n" );
		$this->outputInfo( "Skipped: $skipped users total " .
			"(already set: $this->skippedAlreadySet, " .
			"not eligible: $this->skippedNotEligible, " .
			"not found: $this->skippedNotFound)\n" );
	}

	/**
	 * @param array $batch
	 * @return void
	 */
	private function doBatchUpdate( array $batch ): void {
		try {
			$updatedCount = $this->batchUpdater->executeBatchUpdate();
			$this->enabled += $updatedCount;

			if ( $this->isVerbose() ) {
				$lastId = end( $batch );
				$this->outputInfo( "Updated preferences for $updatedCount user(s)"
					. " of " . count( $batch ) . " user(s), "
					. "up to the last ID: $lastId\n"
				);
			}
		} catch ( \Exception $e ) {
			$this->outputError( "ERROR: Failed to execute batch update: " . $e->getMessage() );
			$this->outputFatalError( "FATAL ERROR: Cannot continue due to database error.\n" );
		}
	}

	/**
	 * @param int $userId
	 * @return void
	 * @throws MaintenanceFatalError
	 */
	private function processUser( int $userId ): void {
		$user = $this->isGlobalIds
			? $this->lookupCentralUser( $userId )
			: $this->userIdentityLookup->getUserIdentityByUserId( $userId );

		if ( !$user ) {
			$this->skippedNotFound++;
			if ( $this->isVerbose() ) {
				$this->outputInfo( "Skipping user ID $userId: not found\n" );
			}
			return;
		}

		if ( !$user->isRegistered() ) {
			return;
		}

		if ( $this->userIdentityUtils->isTemp( $user ) ) {
			return;
		}

		if ( $this->isPreferenceAlreadySet( $user ) ) {
			$this->skippedAlreadySet++;
			if ( $this->isVerbose() ) {
				$this->outputInfo( "Skipping user {$user->getId()} with preference already set.\n" );
			}
			return;
		}

		if ( $this->verifyEligibility ) {
			try {
				if ( !$this->eligibilityValidator->isEligible( $user ) ) {
					$this->skippedNotEligible++;
					if ( $this->isVerbose() ) {
						$this->outputInfo( "User {$user->getId()} not eligible\n" );
					}
					return;
				}
			} catch ( \Exception $e ) {
				$this->outputError( "ERROR: Error checking eligibility for user "
					. "{$user->getId()}: {$e->getMessage()}\n" );
				$this->outputFatalError( "FATAL ERROR: Cannot continue due to database error.\n" );
			}
		}

		if ( !$this->dryRun ) {
			$this->batchUpdater->addUserPreference(
			$user,
			Constants::PREF_KEY_WEB_UI_ENABLED,
				'1'
			);
		} else {
			$this->outputInfo( "Adding user preference for user {$user->getId()}\n" );
			$this->enabled++;
		}
	}

	/**
	 * @param UserIdentity $user
	 * @return bool|null
	 */
	private function isPreferenceAlreadySet( UserIdentity $user ): ?bool {
		return $this->userOptionsManager->getOption(
			$user,
			Constants::PREF_KEY_WEB_UI_ENABLED ) === '1';
	}

	/**
	 * @param int $centralId
	 * @return UserIdentity|null
	 */
	private function lookupCentralUser( int $centralId ): ?UserIdentity {
		$user = $this->centralIdLookup->localUserFromCentralId( $centralId );

		if ( !$user || !$user->isRegistered() ) {
			return null;
		}

		return $user;
	}

	private function outputMessage( string $message, string $logLevel = LogLevel::INFO ): void {
		$prefix = $this->dryRun ? "[dry run] " : "";
		$fullMessage = $prefix . $message;

		switch ( $logLevel ) {
			case LogLevel::CRITICAL:
				$this->fatalError( $fullMessage );
				// @phan-suppress-next-line PhanPluginUnreachableCode
				break;
			case LogLevel::ERROR:
				$this->error( $fullMessage );
				break;
			case LogLevel::INFO:
			default:
				$this->output( $fullMessage );
				break;
		}
	}

	private function outputInfo( string $message ): void {
		$this->outputMessage( $message, LogLevel::INFO );
	}

	private function outputError( string $message ): void {
		$this->outputMessage( $message, LogLevel::ERROR );
	}

	private function outputFatalError( string $message ): void {
		$this->outputMessage( $message, LogLevel::CRITICAL );
	}

	private function outputProgress( int $totalUsers ): void {
		$message = "Progress: {$this->processed}/$totalUsers users processed " .
			"(enabled: $this->enabled, skipped: " .
			( $this->skippedAlreadySet + $this->skippedNotEligible + $this->skippedNotFound ) . ")\n";
		$this->outputInfo( $message );
	}
}

$maintClass = SetReadingListHiddenPreference::class;
require_once RUN_MAINTENANCE_IF_MAIN;
