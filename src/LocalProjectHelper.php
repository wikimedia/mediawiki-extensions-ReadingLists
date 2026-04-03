<?php

namespace MediaWiki\Extension\ReadingLists;

use MediaWiki\MediaWikiServices;

/**
 * Helpers for the current wiki's project identifier in ReadingLists.
 *
 * In this context, "local project" means a reading list entry from the current
 * wiki, identified either by the special '@local' value or by this wiki's
 * canonical server URL without an explicit port.
 */
class LocalProjectHelper {

	/**
	 * Returns the canonical project identifier for the current wiki.
	 * For example, on English Wikipedia this would be
	 * 'https://en.wikipedia.org'.
	 *
	 * @return string The canonical project identifier for the current wiki.
	 */
	public static function getLocalProject(): string {
		$urlUtils = MediaWikiServices::getInstance()->getUrlUtils();
		$url = $urlUtils->getCanonicalServer();
		if ( $url === '' ) {
			return '';
		}

		$parts = $urlUtils->parse( $url );

		// ReadingLists uses wiki domain-style project identifiers, so strip any
		// explicit port from the canonical server URL.
		$parts['port'] = null;
		return $urlUtils->assemble( $parts );
	}

	/**
	 * Checks whether a project refers to the current wiki.
	 *
	 * @param string $project The project identifier to check.
	 * @param string $localProject The canonical project identifier of the current wiki.
	 * @return bool True if the project refers to the current wiki, false otherwise.
	 */
	public static function isLocalProject( string $project, string $localProject ): bool {
		return $project === '@local' || $project === $localProject;
	}
}
