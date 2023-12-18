<?php

namespace MediaWiki\Extension\ReadingLists\Rest;

use DateTime;
use DateTimeZone;
use LogicException;
use MediaWiki\Config\Config;
use MediaWiki\Extension\ReadingLists\Doc\ReadingListRow;
use MediaWiki\Extension\ReadingLists\ReadingListRepositoryException;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Rest\Handler;
use MediaWiki\User\CentralId\CentralIdLookup;
use MediaWiki\Utils\MWTimestamp;
use Psr\Log\LoggerInterface;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\Rdbms\IResultWrapper;
use Wikimedia\Rdbms\LBFactory;

/**
 * Handle GET requests to /readinglists/v0/lists
 *
 * Gets reading lists
 *
 * We derive from Handler, not SimpleHandler, even though child classes use path parameters.
 * This is because the child classes would need to override run() with different signatures.
 */
class ListsHandler extends Handler {
	use ReadingListsHandlerTrait;
	use ReadingListsTokenAwareHandlerTrait;

	private LBFactory $dbProvider;

	private Config $config;

	private CentralIdLookup $centralIdLookup;

	private LoggerInterface $logger;

	protected bool $allowDeletedRowsInResponse = false;

	// Temporarily limit paging sizes per T164990#3264314 / T168984#3659998
	private const MAX_LIMIT = 10;

	/**
	 * @param LBFactory $dbProvider
	 * @param Config $config
	 * @param CentralIdLookup $centralIdLookup
	 */
	public function __construct(
		LBFactory $dbProvider,
		Config $config,
		CentralIdLookup $centralIdLookup
	) {
		$this->dbProvider = $dbProvider;
		$this->config = $config;
		$this->centralIdLookup = $centralIdLookup;
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
	 * @return array
	 */
	public function execute() {
		return $this->getLists( $this->getValidatedParams() );
	}

	/**
	 * Common function for getting Reading Lists.
	 *
	 * @param array $params all parameters (path and query)
	 * @return array
	 */
	protected function getLists( array $params ): array {
		$result = [];

		$this->checkAuthority( $this->getAuthority() );

		$params['sort'] = self::$sortParamMap[$params['sort']];
		$params['dir'] = self::$sortParamMap[$params['dir']];
		$params['next'] = $this->decodeNext( $params['next'], $params['sort'] );

		// timestamp from before querying the DB
		$timestamp = new DateTime( 'now', new DateTimeZone( 'GMT' ) );

		// perform database query and get results
		$res = $this->doGetLists( $params );

		$lists = [];
		foreach ( $res as $i => $row ) {
			'@phan-var ReadingListRow $row';
			$item = $this->getResultItem( $row );
			if ( $i >= $params['limit'] ) {
				// We reached the extra row. Create and return a "next" value that the client
				// can send with a subsequent request for pagination.
				$result['next'] = $this->makeNext( $item, $params['sort'], $item['name'] );
				break;
			}
			$lists[$i] = $item;
		}
		$result['lists'] = $lists;

		// Add a timestamp that, when used in the date parameter in the \lists
		// and \entries endpoints, guarantees that no change will be skipped (at the
		// cost of possibly repeating some changes in the current query). See T182706 for details.
		if ( !$params['next'] ) {
			// Ignore continuations (the client should just use the timestamp received in the
			// first step). Otherwise, backdate more than the max transaction duration, to
			// prevent the situation where a DB write happens before the current request
			// but is only committed after it. (If there is no max transaction duration, the
			// client is on its own.)
			$maxUserDBWriteDuration = $this->config->get( 'MaxUserDBWriteDuration' );
			if ( $maxUserDBWriteDuration ) {
				$timestamp->modify( '-' . ( $maxUserDBWriteDuration + 1 ) . ' seconds' );
			}
			$syncTimestamp = ( new MWTimestamp( $timestamp ) )->getTimestamp( TS_ISO_8601 );
			$result['continue-from'] = $syncTimestamp;
		}

		return $result;
	}

	/**
	 * Worker function for getting Reading Lists.
	 *
	 * @param array $params all parameters (path and query)
	 * @return IResultWrapper<ReadingListRow>
	 */
	protected function doGetLists( array $params ) {
		$repository = $this->getRepository();

		try {
			$result = $repository->getAllLists(
				$params['sort'], $params['dir'], $params['limit'] + 1, $params['next']
			);
		} catch ( ReadingListRepositoryException $e ) {
			$this->die( $e->getMessageObject() );
		}

		return $result;
	}

	/**
	 * Transform a row into an API result item
	 * @param ReadingListRow $row List row, with additions from addExtraData().
	 * @return array
	 */
	private function getResultItem( $row ): array {
		if ( $row->rl_deleted && !$this->allowDeletedRowsInResponse ) {
			$this->logger->error( 'Deleted row returned in non-changes mode', [
				'rl_id' => $row->rl_id,
				'user_central_id' => $row->rl_user_id,
			] );
			throw new LogicException( 'Deleted row returned in non-changes mode' );
		}
		return $this->getListFromRow( $row );
	}

	/**
	 * @return false
	 */
	public function needsWriteAccess() {
		return false;
	}

	/**
	 * @return array[]
	 */
	public function getParamSettings() {
		return [
			'next' => [
				self::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_DEFAULT => '',
			],
		] + $this->getSortParamSettings( self::MAX_LIMIT, 'name' );
	}
}
