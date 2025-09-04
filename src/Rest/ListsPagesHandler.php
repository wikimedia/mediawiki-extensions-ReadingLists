<?php

namespace MediaWiki\Extension\ReadingLists\Rest;

use MediaWiki\Config\Config;
use MediaWiki\Extension\ReadingLists\Doc\ReadingListRow;
use MediaWiki\Extension\ReadingLists\ReadingListRepositoryException;
use MediaWiki\Rest\Validator\Validator;
use MediaWiki\User\CentralId\CentralIdLookup;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\NumericDef;
use Wikimedia\Rdbms\IResultWrapper;
use Wikimedia\Rdbms\LBFactory;

/**
 * Handle GET requests to /{module}/lists/pages.
 * This endpoint is for finding lists that contain a given page.
 */
class ListsPagesHandler extends ListsHandler {
	// Temporarily limit paging sizes per T164990#3264314 / T168984#3659998
	private const MAX_LIMIT = 10;

	public function __construct(
		LBFactory $dbProvider,
		Config $config,
		CentralIdLookup $centralIdLookup,
	) {
		parent::__construct( $dbProvider, $config, $centralIdLookup );
	}

	/**
	 * @return array
	 */
	public function execute() {
		// We don't expose sorting parameters for this endpoint, so include happy defaults
		$params = $this->getValidatedParams();
		$params['sort'] = 'name';
		$params['dir'] = 'ascending';

		return $this->getLists( $params );
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
			return $repository->getListsByPage(
				$params['project'], $params['title'], $params['limit'] + 1, $params['next']
			);
		} catch ( ReadingListRepositoryException $e ) {
			$this->die( $e->getMessageObject() );
		}
	}

	/**
	 * Extract continuation data from item position and serialize it into a string.
	 * Overrides the trait version to customize continuation behavior for this endpoint.
	 *
	 * @param array $item Result item to continue from.
	 * @param string $sort One of the SORT_BY_* constants.
	 * @param string $name The item name
	 * @return string
	 */
	protected function makeNext( array $item, string $sort, string $name ): string {
		return $item['id'];
	}

	/**
	 * Recover continuation data after it has been roundtripped to the client.
	 * Overrides the trait version to customize continuation behavior for this endpoint.
	 *
	 * @param string|null $next Continuation parameter returned by the client.
	 * @param string $sort One of the SORT_BY_* constants.
	 * @return null|int|string[]
	 *  Continuation token format is:
	 *   - null if there was no continuation parameter OR
	 *   - id for lists/pages/{project}/{title} OR
	 *   - [ name, id ] when sorting by name OR
	 *   - [ date_updated, id ] when sorting by updated time
	 */
	protected function decodeNext( $next, $sort ) {
		if ( !$next ) {
			return null;
		}

		$this->dieIf( $next !== (string)(int)$next, 'apierror-badcontinue' );
		return (int)$next;
	}

	/**
	 * @return array[]
	 */
	public function getParamSettings() {
		return [
			'project' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],

			// TODO: investigate "title" type vs "string" type. Consider PARAM_RETURN_OBJECT.
			//  However, T303619 might make phpunit tests problematic.
			'title' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'next' => [
				self::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_DEFAULT => '',
			],
			'limit' => [
				Validator::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_DEFAULT => 10,
				NumericDef::PARAM_MIN => 1,
				NumericDef::PARAM_MAX => self::MAX_LIMIT,
			],
		];
	}
}
