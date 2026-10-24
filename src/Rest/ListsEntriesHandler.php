<?php

namespace MediaWiki\Extension\ReadingLists\Rest;

use MediaWiki\Config\Config;
use MediaWiki\Extension\ReadingLists\Doc\ReadingListEntryRow;
use MediaWiki\Extension\ReadingLists\ReadingListRepositoryException;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Rest\Validator\Validator;
use MediaWiki\User\CentralId\CentralIdLookup;
use Psr\Log\LoggerInterface;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\Rdbms\LBFactory;

/**
 * Handle GET requests to /{module}/lists/{id}/entries
 *
 * Gets reading list entries
 */
class ListsEntriesHandler extends SimpleHandler {
	use ReadingListsHandlerTrait;

	private readonly LoggerInterface $logger;

	private const MAX_LIMIT = 100;

	public function __construct(
		private readonly LBFactory $dbProvider,
		private readonly Config $config,
		private readonly CentralIdLookup $centralIdLookup,
	) {
		$this->logger = LoggerFactory::getInstance( 'readinglists' );
	}

	/**
	 * Create the repository data access object instance.
	 *
	 * @return void
	 */
	public function postInitSetup() {
		$this->repository = $this->createRepository(
			$this->getAuthority()->getUser(),
			$this->config,
			$this->centralIdLookup,
			$this->logger
		);
	}

	/**
	 * @inheritDoc
	 */
	public function validate( Validator $restValidator ) {
		try {
			parent::validate( $restValidator );
		} catch ( LocalizedHttpException $e ) {
			// Add fields expected by WMF mobile apps
			$this->die( $e->getMessageValue(), [], $e->getCode(), $e->getErrorData() );
		}
	}

	/**
	 * @param int $id the list to get entries from
	 * @return array
	 */
	public function run( int $id ) {
		// The parameters are somewhat different between the RESTBase contract for this endpoint
		// and the Action API endpoint that it forwards to. We mostly follow the RESTBase naming
		// herein, and note where we differ from that.
		$params = $this->getValidatedParams();

		$sort = $params['sort'] ?? 'name';

		// In the original RESTBase => Action API implementation, limit and dir were not exposed
		// by RESTBase to the caller. Instead, dir was implied by sort type and limit was sent as
		// the hard-coded special string 'max'. Callers who used the Action API directly could
		// specify dir or limit if desired. To not lose this ability when replacing the RESTBase
		// and Action API endpoints with MW REST endpoints, we extended the RESTBase contract to
		// accept optional limit and parameters. Because the MW REST API does not support "limit"
		// types, the special string 'max' is not allowed.
		$limit = $params['limit'];
		$dir = $params['dir'] ?? null;
		$next = $params['next'] ?? null;

		// Action API allowed multiple lists, but RESTBase (the contract we're matching) did not.
		// Exposing an equivalent while still matching the RESTBase contract would be problematic,
		// because the list id is part of the path. We internally mirror the Action API code,
		// which allowed multiple lists, in case we decide we need to add that ability in some
		// way. But we do not expose that capability to callers.
		//
		// Also, Action API offered a "changedsince" parameter to query list entries by timestamp
		// instead of by list. Because that was incompatible with querying by list, and because
		// we now always require a list id, we eliminated the "changedsince" functionality. It was
		// never exposed by RESTBase.
		$lists = [ $id ];

		$repository = $this->getRepository();

		$this->checkAuthority( $this->getAuthority() );

		$sort = self::$sortParamMap[$sort];
		$dir = self::$sortParamMap[$dir];
		$next = $this->decodeNext( $next, $sort );

		$result = [
			'entries' => []
		];

		try {
			$res = $repository->getListEntries( $lists, $sort, $dir, $limit + 1, $next );
		} catch ( ReadingListRepositoryException $e ) {
			$this->die( $e->getMessageObject() );
		}

		foreach ( $res as $i => $row ) {
			'@phan-var ReadingListEntryRow $row';
			$item = $this->getListEntryFromRow( $row );
			if ( $i >= $limit ) {
				$result['next'] = $this->makeNext( $item, $sort, $row->rle_title );
				break;
			}
			$result['entries'][] = $item;
		}

		return $result;
	}

	/**
	 * @return array[]
	 */
	public function getParamSettings() {
		return [
			'id' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
				Handler::PARAM_SOURCE => 'path',
			],
			'next' => [
				self::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
			],
		] + $this->getSortParamSettings( self::MAX_LIMIT, 'name' );
	}
}
