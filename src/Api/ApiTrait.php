<?php

namespace MediaWiki\Extension\ReadingLists\Api;

use ApiBase;
use ApiUsageException;
use MediaWiki\Extension\ReadingLists\Doc\ReadingListEntryRow;
use MediaWiki\Extension\ReadingLists\Doc\ReadingListEntryRowWithMergeFlag;
use MediaWiki\Extension\ReadingLists\Doc\ReadingListRow;
use MediaWiki\Extension\ReadingLists\Doc\ReadingListRowWithMergeFlag;
use MediaWiki\Extension\ReadingLists\ReadingListRepository;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\CentralId\CentralIdLookup;
use MediaWiki\User\UserIdentity;
use Message;
use Psr\Log\LoggerInterface;
use Wikimedia\Rdbms\LBFactory;

/**
 * Shared initialization and helper methods for the APIs.
 * Classes using it must have a static $prefix property (the API module prefix).
 *
 * Issue with phan and traits - https://github.com/phan/phan/issues/1067
 */
trait ApiTrait {

	/** @var LoggerInterface */
	private $logger;

	/** @var ReadingListRepository */
	private $repository;

	/** @var LBFactory */
	private $loadBalancerFactory;

	/** @var ApiBase */
	private $parent;

	/**
	 * Static entry point for initializing the module
	 * @param ApiBase $parent Parent module
	 * @param string $name Module name
	 * @return static
	 * @suppress PhanUndeclaredStaticProperty, PhanUndeclaredMethod
	 */
	public static function factory( ApiBase $parent, $name ) {
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

		$services = MediaWikiServices::getInstance();
		$loadBalancerFactory = $services->getDBLoadBalancerFactory();
		$module->injectDatabaseDependencies( $loadBalancerFactory );

		$module->logger = LoggerFactory::getInstance( 'readinglists' );

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
	 */
	protected function injectDatabaseDependencies( LBFactory $loadBalancerFactory ) {
		$this->loadBalancerFactory = $loadBalancerFactory;
	}

	/**
	 * Get the repository for the given user.
	 * @param UserIdentity|null $user
	 * @return ReadingListRepository
	 * @suppress PhanUndeclaredMethod
	 */
	protected function getReadingListRepository( UserIdentity $user = null ) {
		$config = $this->getConfig();
		$centralId = MediaWikiServices::getInstance()
			->getCentralIdLookupFactory()
			->getLookup()
			->centralIdFromLocalUser( $user, CentralIdLookup::AUDIENCE_RAW );
		$repository = new ReadingListRepository( $centralId, $this->loadBalancerFactory );
		$repository->setLimits( $config->get( 'ReadingListsMaxListsPerUser' ),
			$config->get( 'ReadingListsMaxEntriesPerList' ) );
		$repository->setLogger( $this->logger );
		return $repository;
	}

	/**
	 * Decode, validate and normalize the 'batch' parameter of write APIs.
	 * @param string $rawBatch The raw value of the 'batch' parameter.
	 * @return array[] One operation, typically a flat associative array.
	 * @throws ApiUsageException
	 * @suppress PhanUndeclaredMethod
	 */
	protected function getBatchOps( $rawBatch ) {
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

		$i = 0;
		$request = $this->getContext()->getRequest();
		foreach ( $batch as &$op ) {
			if ( ++$i > ApiBase::LIMIT_BIG1 ) {
				$msg = wfMessage( 'apierror-readinglists-batch-toomanyvalues', ApiBase::LIMIT_BIG1 );
				$this->dieWithError( $msg, 'toomanyvalues' );
			}
			// Each batch operation must be an associative array with scalar fields.
			if (
				!is_array( $op )
				|| array_values( $op ) === $op
				|| array_filter( $op, 'is_scalar' ) !== $op
			) {
				$this->dieWithError( 'apierror-readinglists-batch-invalid-structure' );
			}
			// JSON-escaped characters might have skipped WebRequest's normalization, repeat it.
			array_walk_recursive( $op, static function ( &$value ) use ( $request ) {
				if ( is_string( $value ) ) {
					$value = $request->normalizeUnicode( $value );
				}
			} );
		}
		return $batch;
	}

	/**
	 * Validate a single operation in the 'batch' parameter of write APIs. Works the same way as
	 * requireAtLeastOneParameter.
	 * @param array $op
	 * @param string ...$param
	 * @throws ApiUsageException
	 */
	protected function requireAtLeastOneBatchParameter( array $op, ...$param ) {
		$intersection = array_intersect(
			array_keys( array_filter( $op, static function ( $val ) {
				return $val !== null && $val !== false;
			} ) ),
			$param
		);

		if ( count( $intersection ) == 0 ) {
			// @phan-suppress-next-line PhanUndeclaredMethod
			$this->dieWithError( [
				'apierror-readinglists-batch-missingparam-at-least-one-of',
				Message::listParam( array_map(
					function ( $p ) {
						// @phan-suppress-next-line PhanUndeclaredMethod
						return '<var>' . $this->encodeParamName( $p ) . '</var>';
					},
					$param
				) ),
				count( $param ),
			], 'missingparam' );
		}
	}

	/**
	 * Convert a list record from ReadingListRepository into an array suitable for adding to
	 * the API result.
	 * @param ReadingListRow|ReadingListRowWithMergeFlag $row
	 * @return array
	 */
	protected function getListFromRow( $row ) {
		$item = [
			'id' => (int)$row->rl_id,
			'name' => $row->rl_name,
			'default' => (bool)$row->rl_is_default,
			'description' => $row->rl_description,
			'created' => wfTimestamp( TS_ISO_8601, $row->rl_date_created ),
			'updated' => wfTimestamp( TS_ISO_8601, $row->rl_date_updated ),
		];
		if ( isset( $row->merged ) ) {
			$item['duplicate'] = (bool)$row->merged;
		}
		if ( $row->rl_deleted ) {
			$item['deleted'] = (bool)$row->rl_deleted;
		}
		return $item;
	}

	/**
	 * Convert a list entry record from ReadingListRepository into an array suitable for adding to
	 * the API result.
	 * @param ReadingListEntryRow|ReadingListEntryRowWithMergeFlag $row
	 * @return array
	 */
	protected function getListEntryFromRow( $row ) {
		$item = [
			'id' => (int)$row->rle_id,
			'listId' => (int)$row->rle_rl_id,
			'project' => $row->rlp_project,
			'title' => $row->rle_title,
			'created' => wfTimestamp( TS_ISO_8601, $row->rle_date_created ),
			'updated' => wfTimestamp( TS_ISO_8601, $row->rle_date_updated ),
		];
		if ( isset( $row->merged ) ) {
			$item['duplicate'] = (bool)$row->merged;
		}
		if ( $row->rle_deleted ) {
			$item['deleted'] = true;
		}
		return $item;
	}

}
