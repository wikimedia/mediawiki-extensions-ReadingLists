<?php

namespace MediaWiki\Extension\ReadingLists\Maintenance;

use Maintenance;
use MediaWiki\Extension\ReadingLists\ReadingListRepository;
use MediaWiki\Extension\ReadingLists\ReadingListRepositoryException;
use MediaWiki\Extension\ReadingLists\Utils;
use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\LBFactory;

require_once getenv( 'MW_INSTALL_PATH' ) !== false
	? getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php'
	: __DIR__ . '/../../../maintenance/Maintenance.php';

/**
 * Fill the database with test data, or remove it.
 */
class PopulateWithTestData extends Maintenance {

	/** @var LBFactory */
	private $loadBalancerFactory;

	/** @var IDatabase */
	private $dbw;

	/** @var IDatabase */
	private $dbr;

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Fill the database with test data, or remove it.' );
		$this->addOption( 'users', 'Number of users', false, true );
		$this->addOption( 'lists', 'Lists per user (number or stats distribution)', false, true );
		$this->addOption( 'entries', 'Entries per list (number or stats distribution)', false, true );
		$this->addOption( 'cleanup', 'Delete lists which look like test data' );
		$this->requireExtension( 'ReadingLists' );
		if ( !extension_loaded( 'stats' ) ) {
			$this->fatalError( 'Requires the stats PHP extension' );
		}
	}

	private function setupServices() {
		// Can't do this in the constructor, initialization not done yet.
		$services = MediaWikiServices::getInstance();
		$this->loadBalancerFactory = $services->getDBLoadBalancerFactory();
		$this->dbw = Utils::getDB( DB_PRIMARY, $services );
		$this->dbr = Utils::getDB( DB_REPLICA, $services );
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$this->setupServices();
		$this->assertOptions();
		if ( $this->getOption( 'cleanup' ) ) {
			$this->cleanupTestData();
			return;
		}

		$projects = $this->dbw->selectFieldValues( 'reading_list_project', 'rlp_id', [], __METHOD__ );
		if ( !$projects ) {
			$this->fatalError( 'No projects! Please set up some' );
		}
		$totalLists = $totalEntries = 0;
		stats_rand_setall( mt_rand(), mt_rand() );
		$users = $this->getOption( 'users' );
		for ( $i = 0; $i < $users; $i++ ) {
			// The test data is for performance testing so we don't care whether the user exists.
			$centralId = 1000 + $i;
			$repository = new ReadingListRepository( $centralId, $this->dbw, $this->dbr,
				$this->loadBalancerFactory );
			try {
				$repository->setupForUser();
				$i++;
				// HACK mark default list so it will be deleted together with the rest
				$this->dbw->update(
					'reading_list',
					[ 'rl_description' => __FILE__ ],
					[
						'rl_user_id' => $centralId,
						'rl_is_default' => 1,
					],
					__METHOD__
				);
			} catch ( ReadingListRepositoryException $e ) {
				// Instead of trying to find a user ID that's not used yet, we'll be lazy
				// and just ignore "already set up" errors.
			}
			$lists = $this->getRandomValueFromDistribution( $this->getOption( 'lists' ) );
			for ( $j = 0; $j < $lists; $j++, $totalLists++ ) {
				$list = $repository->addList( "test_$j", __FILE__ );
				$entries = $this->getRandomValueFromDistribution( $this->getOption( 'entries' ) );
				$rows = [];
				for ( $k = 0; $k < $entries; $k++, $totalEntries++ ) {
					$project = $projects[array_rand( $projects )];
					// Calling addListEntry for each row separately would be a bit slow.
					$rows[] = [
						'rle_rl_id' => $list->rl_id,
						'rle_user_id' => $centralId,
						'rle_rlp_id' => $project,
						'rle_title' => "Test_$k",
					];
				}
				$this->dbw->insert(
					'reading_list_entry',
					$rows,
					__METHOD__
				);
				$this->dbw->update(
					'reading_list',
					[ 'rl_size' => $entries ],
					[ 'rl_id' => $list->rl_id ],
					__METHOD__
				);
			}
			$this->output( '.' );
		}
		$this->output( "\nAdded $totalLists lists and $totalEntries entries for $users users\n" );
	}

	private function cleanupTestData() {
		$services = MediaWikiServices::getInstance();
		$dbw = Utils::getDB( DB_PRIMARY, $services );
		$ids = $dbw->selectFieldValues(
			'reading_list',
			'rl_id',
			[ 'rl_description' => __FILE__ ],
			__METHOD__
		);
		if ( !$ids ) {
			$this->output( "Noting to clean up\n" );
			return;
		}
		$dbw->delete(
			'reading_list_entry',
			[ 'rle_rl_id' => $ids ],
			__METHOD__
		);
		$entries = $dbw->affectedRows();
		$dbw->delete(
			'reading_list',
			[ 'rl_description' => __FILE__ ],
			__METHOD__
		);
		$lists = $dbw->affectedRows();
		$this->output( "Deleted $lists lists and $entries entries\n" );
	}

	/**
	 * Get a random value according to some distribution. The parameter is either a constant
	 * (in which case it will be returned) or a distribution descriptor in the form of
	 * '<dist>,<param1>,<param2>,...' (no spaces) where <dist> refers to one of the stats_rand_gen_*
	 * methods (e.g. 'exponential,1' for an exponential distribution with λ=1, or 'normal,0,1' for
	 * a normal distribution with µ=0, ρ=1).
	 * The result is normalized to be a nonnegative integer.
	 * @param string $distribution
	 * @return int
	 */
	private function getRandomValueFromDistribution( $distribution ) {
		$params = explode( ',', $distribution );
		$type = trim( array_shift( $params ) );
		if ( is_numeric( $type ) ) {
			return (int)$type;
		}
		$function = "stats_rand_gen_$type";
		if (
			!preg_match( '/[a-z_]+/', $type )
			|| !function_exists( $function )
		) {
			$this->error( "invalid distribution: $distribution (could not parse '$type')" );
		}
		$params = array_map( function ( $param ) use ( $distribution ) {
			if ( !is_numeric( $param ) ) {
				$this->error( "invalid distribution: $distribution (could not parse '$param')" );
			}
			return (float)$param;
		}, $params );
		return max( (int)call_user_func_array( $function, $params ), 0 );
	}

	private function assertOptions() {
		if ( $this->hasOption( 'cleanup' ) ) {
			if (
				$this->hasOption( 'users' )
				|| $this->hasOption( 'lists' )
				|| $this->hasOption( 'entries' )
			) {
				$this->fatalError( "'cleanup' cannot be used together with other options" );
			}
		} else {
			if (
				!$this->hasOption( 'users' )
				|| !$this->hasOption( 'lists' )
				|| !$this->hasOption( 'entries' )
			) {
				$this->fatalError( "'users', 'lists' and 'entries' are required in non-cleanup mode" );
			}
		}
	}

}

$maintClass = PopulateWithTestData::class;
require_once RUN_MAINTENANCE_IF_MAIN;
