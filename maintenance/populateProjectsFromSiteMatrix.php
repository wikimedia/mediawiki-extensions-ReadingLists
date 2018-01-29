<?php

namespace MediaWiki\Extensions\ReadingLists\Maintenance;

use Generator;
use Maintenance;
use MediaWiki\Extensions\ReadingLists\Utils;
use MediaWiki\MediaWikiServices;
use SiteMatrix;

require_once getenv( 'MW_INSTALL_PATH' ) !== false
	? getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php'
	: __DIR__ . '/../../../maintenance/Maintenance.php';

/**
 * Maintenance script for populating the reading_list_project table.
 * If the table is already populated, add new entries (old entries that don't exist anymore are
 * left in place).
 * Uses the SiteMatrix extension as the data source.
 * @ingroup Maintenance
 */
class PopulateProjectsFromSiteMatrix extends Maintenance {

	/** @var SiteMatrix */
	private $siteMatrix;

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'Populate (or update) the reading_list_project table from SiteMatrix data.' );
		$this->setBatchSize( 100 );
		$this->requireExtension( 'ReadingLists' );
		$this->requireExtension( 'SiteMatrix' );
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		// Would be nicer to the put this in the constructor but there extensions are not loaded yet.
		$this->siteMatrix = new SiteMatrix();

		$services = MediaWikiServices::getInstance();
		$loadBalancerFactory = $services->getDBLoadBalancerFactory();
		$dbw = Utils::getDB( DB_MASTER, $services );
		$inserted = 0;

		$this->output( "populating...\n" );
		foreach ( $this->generateAllowedDomains() as list( $project ) ) {
			$dbw->insert(
				'reading_list_project',
				[ 'rlp_project' => $project ],
				__METHOD__,
				[ 'IGNORE' ]
			);
			if ( $dbw->affectedRows() ) {
				$inserted++;
				if ( $inserted % $this->mBatchSize ) {
					$loadBalancerFactory->waitForReplication();
				}
			}
		}
		$this->output( "inserted $inserted projects\n" );
	}

	/**
	 * List all sites known to SiteMatrix.
	 * @return Generator [ language, site ]
	 */
	private function generateSites() {
		foreach ( $this->siteMatrix->getSites() as $site ) {
			foreach ( $this->siteMatrix->getLangList() as $lang ) {
				if ( !$this->siteMatrix->exist( $lang, $site ) ) {
					continue;
				}
				yield [ $lang, $site ];
			}
		}
		foreach ( $this->siteMatrix->getSpecials() as $special ) {
			list( $lang, $site ) = $special;
			yield [ $lang, $site ];
		}
	}

	/**
	 * List all sites that are safe to add to the reading list.
	 * @return Generator [ domain, dbname ]
	 */
	private function generateAllowedDomains() {
		foreach ( $this->generateSites() as list( $lang, $site ) ) {
			$dbName = $this->siteMatrix->getDBName( $lang, $site );
			$domain = $this->siteMatrix->getCanonicalUrl( $lang, $site );

			if ( $this->siteMatrix->isPrivate( $dbName ) ) {
				continue;
			}

			yield [ $domain, $dbName ];
		}
	}

}

$maintClass = PopulateProjectsFromSiteMatrix::class;
require_once RUN_MAINTENANCE_IF_MAIN;
