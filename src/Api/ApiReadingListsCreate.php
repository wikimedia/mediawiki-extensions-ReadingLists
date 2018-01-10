<?php

namespace MediaWiki\Extensions\ReadingLists\Api;

use ApiBase;
use MediaWiki\Extensions\ReadingLists\ReadingListRepository;
use Message;

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
	 * @return void
	 */
	public function execute() {
		$params = $this->extractRequestParams();
		$this->requireOnlyOneParameter( $params, 'name', 'batch' );
		$this->requireOnlyOneParameter( $params, 'description', 'batch' );

		$repository = $this->getReadingListRepository( $this->getUser() );
		if ( isset( $params['name'] ) ) {
			$description = isset( $params['description'] ) ? $params['description'] : '';
			$listId = $repository->addList( $params['name'], $params['description'] );
			$this->getResult()->addValue( null, $this->getModuleName(), [ 'id' => $listId ] );
		} else {
			$listIds = [];
			foreach ( $this->yieldBatchOps( $params['batch'] ) as $op ) {
				$description = isset( $op['description'] ) ? $op['description'] : '';
				$this->requireAtLeastOneBatchParameter( $op, 'name' );
				$listIds[] = $repository->addList( $op['name'], $description );
			}
			$this->getResult()->addValue( null, $this->getModuleName(), [ 'ids' => $listIds ] );
			$this->getResult()->addIndexedTagName( $this->getModuleName(), 'id' );
		}
	}

	/**
	 * @inheritDoc
	 * @return array
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
	 * @return Message
	 */
	protected function getExtendedDescription() {
		$limit = $this->getConfig()->get( 'ReadingListsMaxListsPerUser' );
		return wfMessage( 'apihelp-readinglists+create-extended-description', $limit );
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
