<?php

namespace MediaWiki\Extension\ReadingLists\Maintenance;

use Generator;
use MediaWiki\Extension\ReadingLists\Utils;
use MediaWiki\Extension\SiteMatrix\SiteMatrix;
use MediaWiki\Maintenance\Maintenance;
use Throwable;
use Wikimedia\Rdbms\IReadableDatabase;

// @codeCoverageIgnoreStart
require_once getenv( 'MW_INSTALL_PATH' ) !== false
	? getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php'
	: __DIR__ . '/../../../maintenance/Maintenance.php';
// @codeCoverageIgnoreEnd

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
		$this->addOption( 'dry-run', 'List projects that would be added without updating the database' );
		$this->addOption( 'verbose', 'Show verbose output' );
		$this->addOption(
			'family',
			'Only populate projects for a SiteMatrix family, e.g. wikipedia',
			false,
			true
		);
		$this->addOption(
			'wiki-id',
			'Only populate one wiki by database name, e.g. banwiki',
			false,
			true
		);
		$this->setBatchSize( 100 );
		$this->requireExtension( 'ReadingLists' );
		$this->requireExtension( 'SiteMatrix' );
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$this->siteMatrix = $this->getSiteMatrix();

		$dbw = $this->getServiceContainer()->getDBLoadBalancerFactory()->getPrimaryDatabase(
			Utils::VIRTUAL_DOMAIN
		);
		$inserted = 0;
		$projects = $this->getProjectsToAdd( $dbw );

		$this->verboseOutput( 'database domain ID: ' . $dbw->getDomainID() );
		$this->verboseOutput( 'projects to insert: ' . count( $projects ) );

		if ( $this->hasOption( 'dry-run' ) ) {
			$this->output( "would insert " . count( $projects ) . " projects\n" );
			foreach ( $projects as $project ) {
				$this->output( "$project\n" );
			}
			return;
		}

		$batchSize = $this->getBatchSize();

		$this->output( "populating with batch size $batchSize...\n" );
		foreach ( array_chunk( $projects, $batchSize ) as $projectBatch ) {
			$this->beginTransactionRound( __METHOD__ );
			foreach ( $projectBatch as $project ) {
				try {
					$dbw->newInsertQueryBuilder()
						->insertInto( 'reading_list_project' )
						->row( [ 'rlp_project' => $project ] )
						->caller( __METHOD__ )
						->execute();
				} catch ( Throwable $e ) {
					$this->error( "failed to insert $project: " . $e->getMessage() );
					throw $e;
				}
				$affectedRows = $dbw->affectedRows();
				$this->verboseOutput( "insert $project affected rows: $affectedRows" );
				if ( $affectedRows ) {
					$inserted++;
				}
			}
			try {
				$waitSucceeded = $this->commitTransactionRound( __METHOD__ );
				$this->verboseOutput(
					'transaction committed; replication wait ' . ( $waitSucceeded ? 'succeeded' : 'failed' )
				);
			} catch ( Throwable $e ) {
				$this->error( 'failed to commit transaction round: ' . $e->getMessage() );
				throw $e;
			}
		}
		$this->output( "inserted $inserted projects\n" );
	}

	protected function getSiteMatrix(): SiteMatrix {
		return new SiteMatrix();
	}

	private function verboseOutput( string $message ): void {
		if ( $this->hasOption( 'verbose' ) ) {
			$this->output( "$message\n" );
		}
	}

	/**
	 * @param IReadableDatabase $db
	 * @return string[]
	 */
	private function getProjectsToAdd( IReadableDatabase $db ): array {
		$projects = [];
		foreach ( $this->generateAllowedDomains() as [ $project ] ) {
			$projects[$this->normalizeProject( $project )] = true;
		}
		$projects = array_keys( $projects );

		if ( !$projects ) {
			return [];
		}

		$existingProjects = $db->newSelectQueryBuilder()
			->select( 'rlp_project' )
			->from( 'reading_list_project' )
			->caller( __METHOD__ )
			->fetchFieldValues();

		$existingProjects = array_map(
			fn ( $project ) => $this->normalizeProject( $project ),
			$existingProjects
		);

		return array_values( array_diff( $projects, $existingProjects ) );
	}

	private function normalizeProject( string $project ): string {
		return rtrim( $project );
	}

	private function getFamilyOption(): ?string {
		if ( !$this->hasOption( 'family' ) ) {
			return null;
		}

		$family = $this->getOption( 'family' );
		return $family === 'wikipedia' ? 'wiki' : $family;
	}

	/**
	 * List all sites known to SiteMatrix.
	 * @return Generator [ language, site ]
	 */
	private function generateSites() {
		$family = $this->getFamilyOption();
		foreach ( $this->siteMatrix->getSites() as $site ) {
			if ( $family !== null && $site !== $family ) {
				continue;
			}
			foreach ( $this->siteMatrix->getLangList() as $lang ) {
				if ( !$this->siteMatrix->exist( $lang, $site ) ) {
					continue;
				}
				yield [ $lang, $site ];
			}
		}
		foreach ( $this->siteMatrix->getSpecials() as $special ) {
			[ $lang, $site ] = $special;
			if ( $family !== null && $site !== $family ) {
				continue;
			}
			yield [ $lang, $site ];
		}
	}

	/**
	 * List all sites that are safe to add to the reading list.
	 * @return Generator [ domain, dbname ]
	 */
	private function generateAllowedDomains() {
		$wikiId = $this->getOption( 'wiki-id', null );
		foreach ( $this->generateSites() as [ $lang, $site ] ) {
			$dbName = $this->siteMatrix->getDBName( $lang, $site );
			$domain = $this->siteMatrix->getCanonicalUrl( $lang, $site );

			if ( $wikiId !== null && $dbName !== $wikiId ) {
				continue;
			}
			if ( $this->siteMatrix->isPrivate( $dbName ) ) {
				continue;
			}

			yield [ $domain, $dbName ];
		}
	}

}

// @codeCoverageIgnoreStart
$maintClass = PopulateProjectsFromSiteMatrix::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
