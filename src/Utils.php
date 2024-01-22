<?php

namespace MediaWiki\Extension\ReadingLists;

use MediaWiki\MediaWikiServices;
use UnexpectedValueException;

/**
 * Static utility methods.
 */
class Utils {
	public const VIRTUAL_DOMAIN = 'virtual-readinglists';

	/**
	 * Returns the timestamp at which deleted items expire (can be purged).
	 * @return string Timestamp in TS_MW format
	 * @throws UnexpectedValueException When the extension is configured incorrectly.
	 */
	public static function getDeletedExpiry() {
		$services = MediaWikiServices::getInstance();
		$extensionConfig = $services->getConfigFactory()->makeConfig( 'ReadingLists' );
		$days = $extensionConfig->get( 'ReadingListsDeletedRetentionDays' );
		$unixTimestamp = strtotime( '-' . $days . ' days' );
		$timestamp = wfTimestamp( TS_MW, $unixTimestamp );
		if ( !$timestamp || !$unixTimestamp ) {
			// not really an argument but close enough
			throw new UnexpectedValueException( 'Invalid $wgReadingListsDeletedRetentionDays value: '
				. $days );
		}
		return $timestamp;
	}

}
