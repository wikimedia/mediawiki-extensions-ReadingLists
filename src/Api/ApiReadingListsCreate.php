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

		$listId = $this->getReadingListRepository( $this->getUser() )->addList( $params['name'],
			$params['description'] );
		$this->getResult()->addValue( null, $this->getModuleName(), [ 'id' => $listId ] );
	}

	/**
	 * @inheritDoc
	 * @return array
	 */
	protected function getAllowedParams() {
		return [
			'name' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_REQUIRED => true,
				self::PARAM_MAX_BYTES => ReadingListRepository::$fieldLength['rl_name'],
			],
			'description' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_DFLT => '',
				self::PARAM_MAX_BYTES => ReadingListRepository::$fieldLength['rl_description'],
			],
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
		return [
			'action=readinglists&command=create&name=dogs&description=Woof!&token=123ABC'
				=> 'apihelp-readinglists+create-example-1',
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
