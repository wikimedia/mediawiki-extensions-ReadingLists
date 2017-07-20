<?php

namespace MediaWiki\Extensions\ReadingLists\Api;

use ApiBase;
use ApiModuleManager;

/**
 * API module for all write operations.
 * Each operation (command) is implemented as a submodule.
 */
class ApiReadingListsSetup extends ApiBase {

	use ApiTrait;

	/** @var string API module prefix */
	private static $prefix = '';

	/**
	 * Entry point for executing the module
	 * @inheritdoc
	 * @return void
	 */
	public function execute() {
		$this->getReadingListRepository( $this->getUser() )->setupForUser();
	}

	/**
	 * @inheritdoc
	 * @return array
	 */
	protected function getAllowedParams() {
		return [];
	}

	/**
	 * @inheritdoc
	 * @return array
	 */
	public function getHelpUrls() {
		return [
			'https://www.mediawiki.org/wiki/Special:MyLanguage/Extension:ReadingLists#API',
		];
	}

	/**
	 * @inheritdoc
	 * @return array
	 */
	protected function getExamplesMessages() {
		return [
			'action=readinglists&command=setup&token=123ABC'
				=> 'apihelp-readinglists+setup-example-1',
		];
	}

	// The parent module already enforces these but they make documentation nicer.

	/**
	 * @inheritdoc
	 * @return bool
	 */
	public function isWriteMode() {
		return true;
	}

	/**
	 * @inheritdoc
	 * @return bool
	 */
	public function mustBePosted() {
		return true;
	}

	/**
	 * @inheritdoc
	 * @return bool
	 */
	public function isInternal() {
		// ReadingLists API is still experimental
		return true;
	}

}
