<?php

namespace MediaWiki\Extension\ReadingLists\Rest;

use MediaWiki\Config\Config;
use MediaWiki\Extension\ReadingLists\ReadingListRepository;
use MediaWiki\Logger\LoggerFactory;
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
		$this->repository = new ReadingListRepository( $centralId, $dbProvider );

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
