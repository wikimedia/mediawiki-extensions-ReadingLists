<?php

namespace MediaWiki\Extensions\ReadingLists;

use ApiQuerySiteinfo;
use DatabaseUpdater;
use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\IMaintainableDatabase;

/**
 * Static entry points for hooks.
 */
class HookHandler {

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
	public static function onAPIQuerySiteInfoGeneralInfo( ApiQuerySiteinfo $module, array &$result ) {
		global $wgReadingListsMaxListsPerUser, $wgReadingListsMaxEntriesPerList,
			   $wgReadingListsDeletedRetentionDays;
		$result['readinglists-config'] = [
			'maxListsPerUser' => $wgReadingListsMaxListsPerUser,
			'maxEntriesPerList' => $wgReadingListsMaxEntriesPerList,
			'deletedRetentionDays' => $wgReadingListsDeletedRetentionDays,
		];
	}

	/**
	 * @param DatabaseUpdater $updater
	 * @return bool
	 */
	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ) {
		if ( Utils::isCentralWiki( MediaWikiServices::getInstance() ) ) {
			$baseDir = dirname( __DIR__ );
			$patchDir = "$baseDir/sql/patches";
			$updater->addExtensionTable( 'reading_list', "$baseDir/sql/readinglists.sql" );
			$updater->dropExtensionTable( 'reading_list_sortkey', "$patchDir/01-drop-sortkeys.sql" );
			$updater->addExtensionTable( 'reading_list_project',
				"$patchDir/02-add-reading_list_project.sql" );
			$updater->addExtensionIndex( 'reading_list', 'rl_user_deleted_name_id',
				"$patchDir/03-add-sort-indexes.sql" );
			$updater->dropExtensionField( 'reading_list', 'rl_color',
				"$patchDir/04-drop-metadata-columns.sql" );
			$updater->modifyExtensionField( 'reading_list_entry', 'rle_title',
				"$patchDir/05-increase-rle_title-length.sql" );
			$updater->addExtensionField( 'reading_list', 'rl_size',
				"$patchDir/06-add-rl_size.sql" );
		}
		return true;
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
	public static function onUnitTestsAfterDatabaseSetup( $db, $prefix ) {
		global $wgReadingListsCluster, $wgReadingListsDatabase;
		$wgReadingListsCluster = false;
		$wgReadingListsDatabase = false;

		$originalPrefix = $db->tablePrefix();
		$db->tablePrefix( $prefix );
		if ( !$db->tableExists( 'reading_list' ) ) {
			$baseDir = dirname( __DIR__ );
			$db->sourceFile( "$baseDir/sql/readinglists.sql" );
		}
		$db->tablePrefix( $originalPrefix );
	}

	/**
	 * Cleans up tables created by onUnitTestsAfterDatabaseSetup() above
	 */
	public static function onUnitTestsBeforeDatabaseTeardown() {
		$db = wfGetDB( DB_MASTER );
		foreach ( self::$testTables as $table ) {
			$db->dropTable( $table );
		}
	}

}
