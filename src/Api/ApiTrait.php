<?php

namespace MediaWiki\Extensions\ReadingLists\Api;

use ApiBase;
use ApiUsageException;
use CentralIdLookup;
use MediaWiki\Extensions\ReadingLists\ReadingListRepository;
use MediaWiki\Extensions\ReadingLists\Utils;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use Message;
use User;
use Wikimedia\Rdbms\DBConnRef;
use Wikimedia\Rdbms\LBFactory;

/**
 * Shared initialization and helper methods for the APIs.
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
		$config = $this->getConfig();
		$centralId = CentralIdLookup::factory()->centralIdFromLocalUser( $user,
			CentralIdLookup::AUDIENCE_RAW );
		$repository = new ReadingListRepository( $centralId, $this->dbw, $this->dbr,
			$this->loadBalancerFactory );
		$repository->setLimits( $config->get( 'ReadingListsMaxListsPerUser' ),
			$config->get( 'ReadingListsMaxEntriesPerList' ) );
		$repository->setLogger( LoggerFactory::getInstance( 'readinglists' ) );
		return $repository;
	}

	/**
	 * Decode, validate and iterate the 'batch' parameter of write APIs.
	 * @param string $rawBatch The raw value of the 'batch' parameter.
	 * @return \Generator
	 * @throws ApiUsageException
	 */
	protected function yieldBatchOps( $rawBatch ) {
		$batch = json_decode( $rawBatch, true );

		// Must be a real array, and not empty.
		if ( !is_array( $batch ) || $batch !== array_values( $batch ) || !$batch ) {
			if ( json_last_error() ) {
				$jsonError = json_last_error_msg();
				$this->dieWithError( wfMessage( 'apierror-readinglists-batch-invalid-json',
					wfEscapeWikiText( $jsonError ) ) );
			}
			$this->dieWithError( 'apierror-readinglists-batch-invalid-structure' );
		}

		foreach ( $batch as $op ) {
			// Each batch operation must be an associative array with scalar fields.
			if (
				!is_array( $op )
				|| array_values( $op ) === $op
				|| array_filter( $op, 'is_scalar' ) !== $op
			) {
				$this->dieWithError( 'apierror-readinglists-batch-invalid-structure' );
			}
			yield $op;
		}
	}

	/**
	 * Validate a single operation in the 'batch' parameter of write APIs. Works the same way as
	 * requireAtLeastOneParameter.
	 * @param array $op
	 * @param string $param,...
	 * @throws ApiUsageException
	 */
	// @codingStandardsIgnoreLine MediaWiki.WhiteSpace.SpaceBeforeSingleLineComment.NewLineComment
	protected function requireAtLeastOneBatchParameter( array $op, $param /*...*/ ) {
		$required = func_get_args();
		array_shift( $required );

		$intersection = array_intersect(
			array_keys( array_filter( $op, function ( $val ) {
				return !is_null( $val ) && $val !== false;
			} ) ),
			$required
		);

		if ( count( $intersection ) == 0 ) {
			$this->dieWithError( [
				'apierror-readinglists-batch-missingparam-at-least-one-of',
				Message::listParam( array_map(
					function ( $p ) {
						return '<var>' . $this->encodeParamName( $p ) . '</var>';
					},
					array_values( $required )
				) ),
				count( $required ),
			], 'missingparam' );
		}
	}

}
