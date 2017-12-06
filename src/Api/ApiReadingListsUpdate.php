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
		$this->requireAtLeastOneParameter( $params, 'name', 'description' );

		$this->getReadingListRepository( $this->getUser() )->updateList( $params['list'],
			$params['name'], $params['description'] );
	}

	/**
	 * @inheritDoc
	 * @return array
	 */
	protected function getAllowedParams() {
		return [
			'list' => [
				self::PARAM_TYPE => 'integer',
				self::PARAM_REQUIRED => true,
			],
			'name' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_MAX_BYTES => ReadingListRepository::$fieldLength['rl_name'],
			],
			'description' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_MAX_BYTES => ReadingListRepository::$fieldLength['rl_description'],
			],
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
		return [
			'action=readinglists&command=update&list=42&name=New+name&token=123ABC'
				=> 'apihelp-readinglists+update-example-1',
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
