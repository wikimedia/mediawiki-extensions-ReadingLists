<?php

namespace MediaWiki\Extension\ReadingLists\Maintenance;

use MediaWiki\Extension\ReadingLists\ReadingListRepositoryException;
use MediaWiki\Extension\ReadingLists\Utils;
use MediaWiki\Maintenance\Maintenance;
use Wikimedia\Rdbms\IDatabase;

require_once getenv( 'MW_INSTALL_PATH' ) !== false
	? getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php'
	: __DIR__ . '/../../../maintenance/Maintenance.php';

/**
 * Fill the database with test data, or remove it.
 */
class FixListSize extends Maintenance {
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
		$this->dbw = $this->getServiceContainer()->getDBLoadBalancerFactory()->getPrimaryDatabase(
			Utils::VIRTUAL_DOMAIN
		);
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
				$ids = $this->dbw->newSelectQueryBuilder()
					->select( 'rl_id' )
					->from( 'reading_list' )
					// No point in wasting resources on fixing deleted lists.
					->where( [
						$this->dbw->expr( 'rl_id', '>', $maxId ),
						'rl_deleted' => 0,
					] )
					->limit( 1000 )
					->orderBy( 'rl_id', 'ASC' )
					->caller( __METHOD__ )->fetchFieldValues();
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
				$this->waitForReplication();
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
		$repo = Utils::makeMaintenanceRepository();
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

}

$maintClass = FixListSize::class;
require_once RUN_MAINTENANCE_IF_MAIN;
