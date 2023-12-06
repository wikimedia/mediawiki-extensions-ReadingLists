<?php

namespace MediaWiki\Extension\ReadingLists\Rest;

use MediaWiki\Config\Config;
use MediaWiki\Extension\ReadingLists\ReadingListRepository;
use MediaWiki\Extension\ReadingLists\Utils;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\CentralId\CentralIdLookup;
use MediaWiki\User\UserIdentity;
use Wikimedia\Rdbms\LBFactory;

/**
 * Trait to make the ReadingListRepository data access object available to
 * ReadingLists REST handlers, and other helper code.
 */
trait ReadingListsHandlerTrait {

	private ?ReadingListRepository $repository = null;

	/**
	 * @param UserIdentity $user
	 * @return void
	 */
	private function createRepository(
		UserIdentity $user, LBFactory $dbProvider, Config $config, CentralIdLookup $centralIdLookup
	) {
		$centralId = $centralIdLookup->centralIdFromLocalUser( $user, CentralIdLookup::AUDIENCE_RAW );

		// TODO: consider alternatives to referencing the global services instance.
		$services = MediaWikiServices::getInstance();
		$dbw = Utils::getDB( DB_PRIMARY, $services );
		$dbr = Utils::getDB( DB_REPLICA, $services );

		// TODO: consider passing an IConnectionProvider instead of $dbw and $dbr.
		// It might be good to do this when/after we remove the Action API endpoints, so that
		// we don't have to refactor code that is just going to be deleted.
		$this->repository = new ReadingListRepository( $centralId, $dbw, $dbr, $dbProvider );

		$this->repository->setLimits(
			$config->get( 'ReadingListsMaxListsPerUser' ),
			$config->get( 'ReadingListsMaxEntriesPerList' )
		);
		$this->repository->setLogger( LoggerFactory::getInstance( 'readinglists' ) );
	}

	/**
	 * @return ReadingListRepository
	 */
	private function getRepository(): ReadingListRepository {
		return $this->repository;
	}
}
