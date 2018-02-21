<?php

namespace MediaWiki\Extensions\ReadingLists\Api;

use ApiBase;
use MediaWiki\Extensions\ReadingLists\ReadingListRepository;

/**
 * API module for all write operations.
 * Each operation (command) is implemented as a submodule.
 */
class ApiReadingListsUpdate extends ApiBase {

	use ApiTrait;

	/** @var string API module prefix */
	private static $prefix = '';

	/**
	 * Entry point for executing the module
	 * @inheritDoc
	 * @return void
	 */
	public function execute() {
		$params = $this->extractRequestParams();
		$this->requireOnlyOneParameter( $params, 'list', 'batch' );
		$this->requireMaxOneParameter( $params, 'name', 'batch' );
		$this->requireMaxOneParameter( $params, 'description', 'batch' );

		$repository = $this->getReadingListRepository( $this->getUser() );
		if ( isset( $params['list'] ) ) {
			$this->requireAtLeastOneParameter( $params, 'name', 'description' );
			$list = $repository->updateList( $params['list'], $params['name'], $params['description'] );
			$listData = $this->getListFromRow( $list );
			$this->getResult()->addValue( null, $this->getModuleName(),
				[ 'id' => $list->rl_id, 'list' => $listData ] );
		} else {
			$listData = $listIds = [];
			foreach ( $this->getBatchOps( $params['batch'] ) as $op ) {
				$this->requireAtLeastOneBatchParameter( $op, 'list' );
				$this->requireAtLeastOneBatchParameter( $op, 'name', 'description' );
				$name = isset( $op['name'] ) ? $op['name'] : null;
				$description = isset( $op['description'] ) ? $op['description'] : null;
				$list = $repository->updateList( $op['list'], $name, $description );
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
	 * @return array
	 */
	protected function getAllowedParams() {
		return [
			'list' => [
				self::PARAM_TYPE => 'integer',
			],
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
	 * @return array
	 */
	public function getHelpUrls() {
		return [
			'https://www.mediawiki.org/wiki/Special:MyLanguage/Extension:ReadingLists#API',
		];
	}

	/**
	 * @inheritDoc
	 * @return array
	 */
	protected function getExamplesMessages() {
		$batch = wfArrayToCgi( [ 'batch' => json_encode( [
			[ 'list' => 42, 'name' => 'New name' ],
			[ 'list' => 43, 'description' => 'New description' ],
		] ) ] );
		return [
			'action=readinglists&command=update&list=42&name=New+name&token=123ABC'
				=> 'apihelp-readinglists+update-example-1',
			"action=readinglists&command=update&$batch&token=123ABC"
				=> 'apihelp-readinglists+update-example-2',
		];
	}

	// The parent module already enforces these but they make documentation nicer.

	/**
	 * @inheritDoc
	 * @return bool
	 */
	public function isWriteMode() {
		return true;
	}

	/**
	 * @inheritDoc
	 * @return bool
	 */
	public function mustBePosted() {
		return true;
	}

	/**
	 * @inheritDoc
	 * @return bool
	 */
	public function isInternal() {
		// ReadingLists API is still experimental
		return true;
	}

}
