<?php

namespace MediaWiki\Extension\ReadingLists\Maintenance;

use Maintenance;
use MediaWiki\Extension\ReadingLists\ReadingListRepository;
use MediaWiki\Extension\ReadingLists\ReadingListRepositoryException;
use MediaWiki\Extension\ReadingLists\Utils;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\User;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\LBFactory;

require_once getenv( 'MW_INSTALL_PATH' ) !== false
	? getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php'
	: __DIR__ . '/../../../maintenance/Maintenance.php';

/**
 * Fill the database with test data, or remove it.
 */
class FixListSize extends Maintenance {

	/** @var LBFactory */
	private $loadBalancerFactory;

	/** @var IDatabase */
	private $dbw;

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Recalculate the reading_list.rl_size field.' );
		$this->addOption( 'list', 'List ID', false, true );
		$this->requireExtension( 'ReadingLists' );
	}

	private function setupServices() {
		// Can't do this in the constructor, initialization not done yet.
		$services = MediaWikiServices::getInstance();
		$this->loadBalancerFactory = $services->getDBLoadBalancerFactory();
		$this->dbw = Utils::getDB( DB_PRIMARY, $services );
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$this->setupServices();

		if ( $this->hasOption( 'list' ) ) {
			$this->fixRow( $this->getOption( 'list' ) );
		} else {
			$i = $maxId = 0;
			while ( true ) {
				$ids = $this->dbw->selectFieldValues(
					'reading_list',
					'rl_id',
					[
						"rl_id > $maxId",
						// No point in wasting resources on fixing deleted lists.
						'rl_deleted' => 0,
					],
					__METHOD__,
					[
						'ORDER BY' => 'rl_id ASC',
						'LIMIT' => 1000,
					]
				);
				if ( !$ids ) {
					break;
				}
				foreach ( $ids as $id ) {
					$changed = $this->fixRow( $id );
					if ( $changed ) {
						$i++;
					}
					$maxId = (int)$id;
				}
				$this->loadBalancerFactory->waitForReplication();
			}
			$this->output( "Fixed $i lists.\n" );
		}
	}

	/**
	 * Recalculate the size of the given list.
	 * @param int $listId
	 * @return bool True if the row was changed.
	 * @throws ReadingListRepositoryException
	 */
	private function fixRow( $listId ) {
		$repo = $this->getReadingListRepository();
		try {
			$this->output( "Fixing list $listId... " );
			$changed = $repo->fixListSize( $listId );
		} catch ( ReadingListRepositoryException $e ) {
			if ( $e->getMessageObject()->getKey() === 'readinglists-db-error-no-such-list' ) {
				$this->error( "not found, skipping\n" );
				return false;
			} else {
				throw $e;
			}
		}
		$this->output( $changed ? "done\n" : "no change needed\n" );
		return $changed;
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

$maintClass = FixListSize::class;
require_once RUN_MAINTENANCE_IF_MAIN;
