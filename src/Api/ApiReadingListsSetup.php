<?php

namespace MediaWiki\Extensions\ReadingLists\Api;

use ApiBase;

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
	 * @inheritDoc
	 * @return void
	 */
	public function execute() {
		$list = $this->getReadingListRepository( $this->getUser() )->setupForUser();
		$listData = $this->getListFromRow( $list );
		$this->getResult()->addValue( null, $this->getModuleName(),
			[ 'list' => $listData ] );
	}

	/**
	 * @inheritDoc
	 * @return array
	 */
	protected function getAllowedParams() {
		return [];
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
			'action=readinglists&command=setup&token=123ABC'
				=> 'apihelp-readinglists+setup-example-1',
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
