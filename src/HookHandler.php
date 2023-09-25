<?php

namespace MediaWiki\Extension\ReadingLists;

use ApiQuerySiteinfo;
use MediaWiki\Api\Hook\APIQuerySiteInfoGeneralInfoHook;
use MediaWiki\Hook\UnitTestsAfterDatabaseSetupHook;
use MediaWiki\Hook\UnitTestsBeforeDatabaseTeardownHook;
use Wikimedia\Rdbms\IMaintainableDatabase;

/**
 * Static entry points for hooks.
 */
class HookHandler implements
	APIQuerySiteInfoGeneralInfoHook,
	UnitTestsAfterDatabaseSetupHook,
	UnitTestsBeforeDatabaseTeardownHook
{

	/** @var array Tables which need to be set up / torn down for tests */
	public static $testTables = [
		'reading_list',
		'reading_list_entry',
		'reading_list_project',
	];

	/**
	 * Add configuration data to the siteinfo API output.
	 * Used by the RESTBase proxy for help messages in the Swagger doc.
	 * @param ApiQuerySiteinfo $module
	 * @param array &$result
	 */
	public function onAPIQuerySiteInfoGeneralInfo( $module, &$result ) {
		global $wgReadingListsMaxListsPerUser, $wgReadingListsMaxEntriesPerList,
			   $wgReadingListsDeletedRetentionDays;
		$result['readinglists-config'] = [
			'maxListsPerUser' => $wgReadingListsMaxListsPerUser,
			'maxEntriesPerList' => $wgReadingListsMaxEntriesPerList,
			'deletedRetentionDays' => $wgReadingListsDeletedRetentionDays,
		];
	}

	/**
	 * Setup the centralauth tables in the current DB, so we don't have
	 * to worry about rights on another database. The first time it's called
	 * we have to set the DB prefix ourselves, and reset it back to the original
	 * so that CloneDatabase will work. On subsequent runs, the prefix is already
	 * set up for us.
	 *
	 * @param IMaintainableDatabase $db
	 * @param string $prefix
	 */
	public function onUnitTestsAfterDatabaseSetup( $db, $prefix ) {
		global $wgReadingListsCluster, $wgReadingListsDatabase;
		$wgReadingListsCluster = false;
		$wgReadingListsDatabase = false;

		$originalPrefix = $db->tablePrefix();
		$db->tablePrefix( $prefix );
		if ( !$db->tableExists( 'reading_list', __METHOD__ ) ) {
			$type = $db->getType();
			$baseDir = dirname( __DIR__ );
			$db->sourceFile( "$baseDir/sql/$type/tables-generated.sql" );
		}
		$db->tablePrefix( $originalPrefix );
	}

	/**
	 * Cleans up tables created by onUnitTestsAfterDatabaseSetup() above
	 */
	public function onUnitTestsBeforeDatabaseTeardown() {
		$db = wfGetDB( DB_PRIMARY );
		foreach ( self::$testTables as $table ) {
			$db->dropTable( $table );
		}
	}

}
