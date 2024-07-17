<?php

namespace MediaWiki\Extension\ReadingLists\Rest;

use MediaWiki\Config\Config;
use MediaWiki\Extension\ReadingLists\Doc\ReadingListRow;
use MediaWiki\Extension\ReadingLists\ReadingListRepositoryException;
use MediaWiki\Extension\ReadingLists\Utils;
use MediaWiki\User\CentralId\CentralIdLookup;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\Rdbms\IResultWrapper;
use Wikimedia\Rdbms\LBFactory;

/**
 * Handle GET requests to /{module}/lists/changes/since.
 * This endpoint is for getting lists that have recently changed.
 */
class ListsChangesSinceHandler extends ListsHandler {
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
	 * @return array[]
	 */
	public function getParamSettings() {
		return [
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
	}
}
