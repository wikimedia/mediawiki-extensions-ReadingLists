<?php

namespace MediaWiki\Extension\ReadingLists;

use DatabaseUpdater;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;
use MediaWiki\MediaWikiServices;

/**
 * Static entry points for hooks.
 */
class SchemaHookHandler implements LoadExtensionSchemaUpdatesHook {

	/**
	 * @param DatabaseUpdater $updater
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		if ( Utils::isCentralWiki( MediaWikiServices::getInstance() ) ) {
			$type = $updater->getDB()->getType();
			$baseDir = dirname( __DIR__ ) . '/sql/' . $type;
			$patchDir = "$baseDir/patches";
			$updater->addExtensionTable( 'reading_list', "$baseDir/tables-generated.sql" );
			if ( $type === 'mysql' ) {
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
		}
	}

}
