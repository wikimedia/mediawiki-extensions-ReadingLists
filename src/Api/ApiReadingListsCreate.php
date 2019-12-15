<?php

namespace MediaWiki\Extensions\ReadingLists\Api;

use ApiBase;
use MediaWiki\Extensions\ReadingLists\ReadingListRepository;

/**
 * API module for all write operations.
 * Each operation (command) is implemented as a submodule.
 */
class ApiReadingListsCreate extends ApiBase {

	use ApiTrait;

	/** @var string API module prefix */
	private static $prefix = '';

	/**
	 * Entry point for executing the module
	 * @inheritDoc
	 */
	public function execute() {
		$params = $this->extractRequestParams();
		$this->requireOnlyOneParameter( $params, 'name', 'batch' );
		$this->requireOnlyOneParameter( $params, 'description', 'batch' );

		$repository = $this->getReadingListRepository( $this->getUser() );
		if ( isset( $params['name'] ) ) {
			$description = $params['description'] ?? '';
			$list = $repository->addList( $params['name'], $description );
			$listData = $this->getListFromRow( $list );
			$this->getResult()->addValue( null, $this->getModuleName(),
				[ 'id' => $list->rl_id, 'list' => $listData ] );
		} else {
			$listData = $listIds = [];
			foreach ( $this->getBatchOps( $params['batch'] ) as $op ) {
				$description = $op['description'] ?? '';
				$this->requireAtLeastOneBatchParameter( $op, 'name' );
				$list = $repository->addList( $op['name'], $description );
				$listIds[] = $list->rl_id;
				$listData[] = $this->getListFromRow( $list );
			}
			$this->getResult()->addValue( null, $this->getModuleName(),
				[ 'ids' => $listIds, 'lists' => $listData ] );
			$this->getResult()->addIndexedTagName( [ $this->getModuleName(), 'ids' ], 'id' );
			$this->getResult()->addIndexedTagName( [ $this->getModuleName(), 'lists' ], 'list' );
		}
	}

	/**
	 * @inheritDoc
	 */
	protected function getAllowedParams() {
		return [
			'name' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_MAX_BYTES => ReadingListRepository::$fieldLength['rl_name'],
			],
			'description' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_MAX_BYTES => ReadingListRepository::$fieldLength['rl_description'],
			],
			'batch' => [
				self::PARAM_TYPE => 'string',
			]
		];
	}

	/**
	 * @inheritDoc
	 */
	protected function getExtendedDescription() {
		$limit = $this->getConfig()->get( 'ReadingListsMaxListsPerUser' );
		return wfMessage( 'apihelp-readinglists+create-extended-description', $limit );
	}

	/**
	 * @inheritDoc
	 */
	public function getHelpUrls() {
		return [
			'https://www.mediawiki.org/wiki/Special:MyLanguage/Extension:ReadingLists#API',
		];
	}

	/**
	 * @inheritDoc
	 */
	protected function getExamplesMessages() {
		$batch = wfArrayToCgi( [ 'batch' => json_encode( [
			[ 'name' => 'dogs', 'description' => 'Woof!' ],
			[ 'name' => 'cats', 'description' => 'Meow' ],
		] ) ] );
		return [
			'action=readinglists&command=create&name=dogs&description=Woof!&token=123ABC'
				=> 'apihelp-readinglists+create-example-1',
			"action=readinglists&command=create&$batch&token=123ABC"
				=> 'apihelp-readinglists+create-example-2',
		];
	}

	// The parent module already enforces these but they make documentation nicer.

	/**
	 * @inheritDoc
	 */
	public function isWriteMode() {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function mustBePosted() {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function isInternal() {
		// ReadingLists API is still experimental
		return true;
	}

}
