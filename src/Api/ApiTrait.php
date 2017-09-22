<?php

namespace MediaWiki\Extensions\ReadingLists\Api;

use ApiBase;
use CentralIdLookup;
use MediaWiki\Extensions\ReadingLists\ReadingListRepository;
use MediaWiki\Extensions\ReadingLists\Utils;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use User;
use Wikimedia\Rdbms\DBConnRef;
use Wikimedia\Rdbms\LBFactory;

/**
 * Shared initialization for the APIs.
 * Classes using it must have a static $prefix property (the API module prefix).
 */
trait ApiTrait {
	/** @var ReadingListRepository */
	private $repository;

	/** @var LBFactory */
	private $loadBalancerFactory;

	/** @var DBConnRef */
	private $dbw;

	/** @var DBConnRef */
	private $dbr;

	/** @var ApiBase */
	private $parent;

	/**
	 * Static entry point for initializing the module
	 * @param ApiBase $parent Parent module
	 * @param string $name Module name
	 * @return static
	 */
	public static function factory( ApiBase $parent, $name ) {
		$services = MediaWikiServices::getInstance();
		$loadBalancerFactory = $services->getDBLoadBalancerFactory();
		$dbw = Utils::getDB( DB_MASTER, $services );
		$dbr = Utils::getDB( DB_REPLICA, $services );
		if ( static::$prefix ) {
			// We are in one of the read modules, $parent is ApiQuery.
			// This is an ApiQueryBase subclass so we need to pass ApiQuery.
			$module = new static( $parent, $name, static::$prefix );
		} else {
			// We are in one of the write submodules, $parent is ApiReadingLists.
			// This is an ApiBase subclass so we need to pass ApiMain.
			$module = new static( $parent->getMain(), $name, static::$prefix );
		}
		$module->parent = $parent;
		$module->injectDatabaseDependencies( $loadBalancerFactory, $dbw, $dbr );
		return $module;
	}

	/**
	 * Get the parent module.
	 * @return ApiBase
	 */
	public function getParent() {
		return $this->parent;
	}

	/**
	 * Set database-related dependencies. Required when initializing a module that uses this trait.
	 * @param LBFactory $loadBalancerFactory
	 * @param DBConnRef $dbw Master connection
	 * @param DBConnRef $dbr Replica connection
	 */
	protected function injectDatabaseDependencies(
		LBFactory $loadBalancerFactory, DBConnRef $dbw, DBConnRef $dbr
	) {
		$this->loadBalancerFactory = $loadBalancerFactory;
		$this->dbw = $dbw;
		$this->dbr = $dbr;
	}

	/**
	 * Get the repository for the given user.
	 * @param User $user
	 * @return ReadingListRepository
	 */
	protected function getReadingListRepository( User $user = null ) {
		$centralId = CentralIdLookup::factory()->centralIdFromLocalUser( $user,
			CentralIdLookup::AUDIENCE_RAW );
		$repository = new ReadingListRepository( $centralId, $this->dbw, $this->dbr,
			$this->loadBalancerFactory );
		$repository->setLogger( LoggerFactory::getInstance( 'readinglists' ) );
		return $repository;
	}

}
