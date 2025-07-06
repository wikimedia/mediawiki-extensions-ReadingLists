<?php

namespace MediaWiki\Extension\ReadingLists\Rest;

use MediaWiki\Config\Config;
use MediaWiki\Extension\ReadingLists\Doc\ReadingListEntryRow;
use MediaWiki\Extension\ReadingLists\ReadingListRepositoryException;
use MediaWiki\Extension\ReadingLists\ReverseInterwikiLookup;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Rest\Validator\Validator;
use MediaWiki\Title\TitleValue;
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

	private LBFactory $dbProvider;

	private Config $config;

	private CentralIdLookup $centralIdLookup;

	private ReverseInterwikiLookup $reverseInterwikiLookup;

	private LoggerInterface $logger;

	private const MAX_LIMIT = 100;

	/**
	 * @param LBFactory $dbProvider
	 * @param Config $config
	 * @param CentralIdLookup $centralIdLookup
	 */
	public function __construct(
		LBFactory $dbProvider,
		Config $config,
		CentralIdLookup $centralIdLookup,
		ReverseInterwikiLookup $reverseInterwikiLookup
	) {
		$this->dbProvider = $dbProvider;
		$this->config = $config;
		$this->centralIdLookup = $centralIdLookup;
		$this->reverseInterwikiLookup = $reverseInterwikiLookup;
		$this->logger = LoggerFactory::getInstance( 'readinglists' );
	}

	/**
	 * Create the repository data access object instance.
	 *
	 * @return void
	 */
	public function postInitSetup() {
		$this->repository = $this->createRepository(
			$this->getAuthority()->getUser(), $this->dbProvider, $this->config, $this->centralIdLookup, $this->logger
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

		'@phan-var stdClass[] $res';
		foreach ( $res as $i => $row ) {
			// @phan-suppress-next-line PhanTypeMismatchArgument
			$item = $this->getListEntryFromRow( $row );
			if ( $i >= $limit ) {
				$result['next'] = $this->makeNext( $item, $sort, $item['title'] );
				break;
			}
			$result['entries'][] = $item;
		}

		return $result;
	}

	/**
	 * Transform a row into an API result item
	 * @param ReadingListEntryRow $row
	 * @return ?TitleValue|string
	 */
	private function getResultTitle( $row ) {
		$interwikiPrefix = $this->reverseInterwikiLookup->lookup( $row->rlp_project );
		if ( is_string( $interwikiPrefix ) ) {
			if ( $interwikiPrefix === '' ) {
				$title = TitleValue::tryNew( NS_MAIN, $row->rle_title );
				if ( !$title ) {
					// Validation differences between wikis? Let's just return it as it is.
					$title = TitleValue::tryNew( NS_MAIN, $row->rle_title );
				}
			} else {
				// We have no way of telling what the namespace is, but Title does not support
				// foreign namespaces anyway. Let's just pretend it's in the main namespace so
				// the prefixed title string works out as expected.
				$title = TitleValue::tryNew( NS_MAIN, $row->rle_title, '', $interwikiPrefix );
			}
			return $title;
		} elseif ( is_array( $interwikiPrefix ) ) {
			$title = implode( ':', array_slice( $interwikiPrefix, 1 ) ) . ':' . $row->rle_title;
			$prefix = $interwikiPrefix[0];
			return TitleValue::tryNew( NS_MAIN, $title, '', $prefix );
		}
		// For lack of a better option let's create an invalid title.
		// ApiPageSet::populateFromTitles() is not documented to accept strings
		// but it will actually work.
		return 'Invalid project|' . $row->rlp_project . '|' . $row->rle_title;
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
