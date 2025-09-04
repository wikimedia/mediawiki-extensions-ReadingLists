<?php

namespace MediaWiki\Extension\ReadingLists\Rest;

use MediaWiki\Config\Config;
use MediaWiki\Extension\ReadingLists\Doc\ReadingListEntryRow;
use MediaWiki\Extension\ReadingLists\Doc\ReadingListRow;
use MediaWiki\Extension\ReadingLists\ReadingListRepositoryException;
use MediaWiki\Extension\ReadingLists\Utils;
use MediaWiki\User\CentralId\CentralIdLookup;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\Rdbms\IResultWrapper;
use Wikimedia\Rdbms\LBFactory;

/**
 * Handle GET requests to /{module}/lists/changes/since.
 * This endpoint is for getting lists and list entries that have recently changed.
 */
class ListsChangesSinceHandler extends ListsHandler {
	// Temporarily limit paging sizes per T164990#3264314 / T168984#3659998
	private const MAX_LIMIT = 10;

	public function __construct(
		LBFactory $dbProvider,
		Config $config,
		CentralIdLookup $centralIdLookup,
	) {
		parent::__construct( $dbProvider, $config, $centralIdLookup );
		$this->allowDeletedRowsInResponse = true;
	}

	/**
	 * @return array
	 */
	public function execute() {
		$expiry = strtotime( Utils::getDeletedExpiry() );
		$date = $this->getValidatedParams()['date']::time();
		if ( $date < $expiry ) {
			$this->die(
				'apierror-readinglists-too-old',
				[ '', wfTimestamp( TS_ISO_8601, $expiry ) ]
			);
		}

		return $this->getLists( $this->getValidatedParams() );
	}

	/**
	 * Common function for getting processed data for Reading Lists responses.
	 *
	 * @param array $params all parameters (path and query)
	 *
	 * @return array
	 */
	protected function getListsData( array $params ): array {
		// For this endpoint, we handle continuation specially. See comment below.
		$listsDataParams = $params;
		$listsDataParams['next'] = $params['next']['lists'] ?? null;

		$result = parent::getListsData( $listsDataParams );
		$listsNext = $result['next'] ?? null;
		unset( $result['next'] );

		// perform database query and get results
		$entriesDataParams = $params;
		$entriesDataParams['next'] = $params['next']['entries'] ?? null;
		$res = $this->doGetEntries( $entriesDataParams );

		$entriesNext = null;
		$entries = [];
		foreach ( $res as $i => $row ) {
			'@phan-var ReadingListEntryRow $row';
			$item = $this->getListEntryFromRow( $row );
			if ( $i >= $params['limit'] ) {
				// We reached the extra row. Create and return a "next" value that the client
				// can send with a subsequent request for pagination.
				$entriesNext = $this->makeNext( $item, $params['sort'], $item['title'] );
				break;
			}
			$entries[$i] = $item;
		}

		$result['entries'] = $entries;

		$next = $this->makeChangesSinceNext( $listsNext, $entriesNext );
		if ( $next ) {
			$result['next'] = $next;
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
			return $repository->getListsByDateUpdated(
				$params['date'], $params['sort'], $params['dir'], $params['limit'] + 1, $params['next']
			);
		} catch ( ReadingListRepositoryException $e ) {
			$this->die( $e->getMessageObject() );
		}
	}

	/**
	 * Worker function for getting Reading Lists entries
	 *
	 * @param array $params all parameters (path and query)
	 * @return IResultWrapper<ReadingListEntryRow>
	 */
	private function doGetEntries( array $params ) {
		$repository = $this->getRepository();

		try {
			return $repository->getListEntriesByDateUpdated(
				$params['date'], $params['dir'], $params['limit'] + 1, $params['next']
			);
		} catch ( ReadingListRepositoryException $e ) {
			$this->die( $e->getMessageObject() );
		}
	}

	/**
	 * Creates a "next" string suitable for the /changes/since response body. This endpoint
	 * response includes two datasets: "lists" and "entries". The "next" string in the response
	 * therefore includes two components, one for the "lists" continuation point and one for the
	 * "entries" continuation point.
	 *
	 * This function combines the "next components. Creation of each component should be handled
	 * normally via makeNext().
	 *
	 * @param ?string $listsNext Next value for the "lists" portion of the response.
	 * @param ?string $entriesNext Next value for the "entries" portion of the response.
	 *
	 * @return ?string
	 */
	protected function makeChangesSinceNext( ?string $listsNext, ?string $entriesNext ): ?string {
		$next = '';
		if ( $listsNext || $entriesNext ) {
			$next = [];
			if ( $listsNext ) {
				$next['lists'] = $listsNext;
			}
			if ( $entriesNext ) {
				$next['entries'] = $entriesNext;
			}
			$next = json_encode( $next );
		}
		return $next;
	}

	/**
	 * Recover continuation data after it has been roundtripped to the client. The /changes/since
	 * endpoint uses a composite "next" value that requires special handling.
	 *
	 * @param string|null $encodedNext Continuation parameter returned by the client.
	 * @param string $sort One of the SORT_BY_* constants.
	 *
	 * @return null|int|string[]
	 *   Continuation token format is:
	 *   - null if there was no continuation parameter, OR
	 * *   - an array of decoded "next" components, as strings
	 */
	protected function decodeNext( $encodedNext, $sort ) {
		if ( !$encodedNext ) {
			return null;
		}

		$decoded = json_decode( $encodedNext, true );
		$this->dieIf( !is_array( $decoded ), 'apierror-badcontinue' );

		// Decode components. Using explicit indices protects against unexpected data in the
		//"next" parameter sent from the client
		$next = [];
		if ( isset( $decoded['lists'] ) ) {
			$next['lists'] = $this->traitDecodeNext( (string)$decoded['lists'], $sort );
		}
		if ( isset( $decoded['entries'] ) ) {
			$next['entries'] = $this->traitDecodeNext( (string)$decoded['entries'], $sort );
		}
		return $next;
	}

	/**
	 * @return array[]
	 */
	public function getParamSettings() {
		$settings = [
			'date' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'timestamp',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'next' => [
				self::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_DEFAULT => '',
			]
		] + $this->getSortParamSettings( self::MAX_LIMIT, 'updated' );

		// The changes/since endpoint allows sorting only by updated, not by name.
		$settings['sort'][ParamValidator::PARAM_TYPE] = [ 'updated' ];

		return $settings;
	}
}
