<?php

namespace MediaWiki\Extension\ReadingLists;

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\User;
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

	/**
	 * Create a repository for maintenance use.
	 * The repo will be associated with a system user.
	 *
	 * @return ReadingListRepository
	 */
	public static function makeMaintenanceRepository() {
		// TODO: Move this to a service
		$services = MediaWikiServices::getInstance();
		$user = User::newSystemUser( 'Maintenance script', [ 'steal' => true ] );
		// There isn't really any way for this user to be non-local, but let's be future-proof.
		$centralId = $services->getCentralIdLookupFactory()
			->getLookup()
			->centralIdFromLocalUser( $user );
		$repository = new ReadingListRepository( $centralId, $services->getDBLoadBalancerFactory() );
		$repository->setLogger( LoggerFactory::getInstance( 'readinglists' ) );
		return $repository;
	}

}
