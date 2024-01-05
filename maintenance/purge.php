<?php

namespace MediaWiki\Extension\ReadingLists\Maintenance;

use Maintenance;
use MediaWiki\Extension\ReadingLists\ReadingListRepository;
use MediaWiki\Extension\ReadingLists\Utils;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\User;

require_once getenv( 'MW_INSTALL_PATH' ) !== false
	? getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php'
	: __DIR__ . '/../../../maintenance/Maintenance.php';

/**
 * Maintenance script for purging unneeded DB rows (deleted lists/entries or orphaned sortkeys).
 * Purging deleted lists/entries limits clients' ability to sync deletes.
 * Purging orphaned sortkeys has no user-visible effect.
 * @ingroup Maintenance
 */
class Purge extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'Purge unneeded database rows (deleted lists/entries or orphaned sortkeys).' );
		$this->addOption( 'before', 'Purge deleted lists/entries before this timestamp', false, true );
		$this->requireExtension( 'ReadingLists' );
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$now = wfTimestampNow();
		if ( $this->hasOption( 'before' ) ) {
			$before = wfTimestamp( TS_MW, $this->getOption( 'before' ) );
			if ( !$before || $now <= $before ) {
				// Let's not delete all rows if the user entered an invalid timestamp.
				$this->fatalError( 'Invalid timestamp' );
			}
		} else {
			$before = Utils::getDeletedExpiry();
		}
		$this->output( "...purging deleted rows\n" );
		$this->getReadingListRepository()->purgeOldDeleted( $before );
		$this->output( "done.\n" );
	}

	/**
	 * Initializes the repository.
	 * @return ReadingListRepository
	 */
	private function getReadingListRepository() {
		$services = MediaWikiServices::getInstance();
		$loadBalancerFactory = $services->getDBLoadBalancerFactory();
		$dbw = Utils::getDB( DB_PRIMARY, $services );
		$dbr = Utils::getDB( DB_REPLICA, $services );
		$user = User::newSystemUser( 'Maintenance script', [ 'steal' => true ] );
		// There isn't really any way for this user to be non-local, but let's be future-proof.
		$centralId = $services->getCentralIdLookupFactory()
			->getLookup()
			->centralIdFromLocalUser( $user );
		$repository = new ReadingListRepository( $centralId, $dbw, $dbr, $loadBalancerFactory );
		$repository->setLogger( LoggerFactory::getInstance( 'readinglists' ) );
		return $repository;
	}

}

$maintClass = Purge::class;
require_once RUN_MAINTENANCE_IF_MAIN;
