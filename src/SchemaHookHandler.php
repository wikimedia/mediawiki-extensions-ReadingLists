<?php

namespace MediaWiki\Extension\ReadingLists;

use MediaWiki\Installer\DatabaseUpdater;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

/**
 * Static entry points for hooks.
 */
class SchemaHookHandler implements LoadExtensionSchemaUpdatesHook {

	/**
	 * @param DatabaseUpdater $updater
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$baseDir = dirname( __DIR__ ) . '/sql/' . $updater->getDB()->getType();
		$updater->addExtensionUpdateOnVirtualDomain(
			[ Utils::VIRTUAL_DOMAIN, 'addTable', 'reading_list', "$baseDir/tables-generated.sql", true ]
		);
		$updater->addExtensionUpdateOnVirtualDomain(
			[ Utils::VIRTUAL_DOMAIN, [ self::class, 'populateProjectsTable' ] ]
		);
	}

	/**
	 * Populates the projects table if necessary.
	 */
	public static function populateProjectsTable() {
		$repository = Utils::makeMaintenanceRepository();
		$repository->initializeProjectIfNeeded();
	}

}
