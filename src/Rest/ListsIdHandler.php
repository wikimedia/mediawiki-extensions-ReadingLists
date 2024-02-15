<?php

namespace MediaWiki\Extension\ReadingLists\Rest;

use MediaWiki\Config\Config;
use MediaWiki\Extension\ReadingLists\Doc\ReadingListRow;
use MediaWiki\Extension\ReadingLists\ReadingListRepositoryException;
use MediaWiki\User\CentralId\CentralIdLookup;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\NumericDef;
use Wikimedia\Rdbms\FakeResultWrapper;
use Wikimedia\Rdbms\IResultWrapper;
use Wikimedia\Rdbms\LBFactory;

/**
 * Handle GET requests to /readinglists/v0/lists/{id}.
 * This endpoint is for getting lists by id.
 */
class ListsIdHandler extends ListsHandler {
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
	}

	/**
	 * @return array
	 */
	public function execute() {
		// Sorting/pagination is nonsensical for this endpoint, but getLists() expects defaults. So provide happy ones.
		$params = $this->getValidatedParams();
		$params['sort'] = 'name';
		$params['dir'] = 'ascending';
		$params['next'] = '';
		$params['limit'] = 1;

		// Because this handler returns a single list, we simplify the returned data
		$ret = $this->getLists( $params );
		return $ret['lists'][0] ?? [];
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
			// @phan-suppress-next-line PhanTypeMismatchArgument
			$result = new FakeResultWrapper( [ $repository->selectValidList( $params['id'] ) ] );
		} catch ( ReadingListRepositoryException $e ) {
			$this->die( $e->getMessageObject() );
		}

		return $result;
	}

	/**
	 * @return array[]
	 */
	public function getParamSettings() {
		return [
				'id' => [
					self::PARAM_SOURCE => 'path',
					ParamValidator::PARAM_TYPE => 'integer',
					ParamValidator::PARAM_REQUIRED => true,
					NumericDef::PARAM_MIN => 1,
				]
			];
	}
}
