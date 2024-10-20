<?php

namespace MediaWiki\Extension\ReadingLists\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Extension\ReadingLists\ReadingListRepository;
use Wikimedia\ParamValidator\ParamValidator;

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
				$name = $op['name'] ?? null;
				$description = $op['description'] ?? null;
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
	 */
	protected function getAllowedParams() {
		return [
			'list' => [
				ParamValidator::PARAM_TYPE => 'integer',
			],
			'name' => [
				ParamValidator::PARAM_TYPE => 'string',
				self::PARAM_MAX_BYTES => ReadingListRepository::$fieldLength['rl_name'],
			],
			'description' => [
				ParamValidator::PARAM_TYPE => 'string',
				self::PARAM_MAX_BYTES => ReadingListRepository::$fieldLength['rl_description'],
			],
			'batch' => [
				ParamValidator::PARAM_TYPE => 'string',
			]
		];
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
