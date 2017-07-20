<?php

namespace MediaWiki\Extensions\ReadingLists\Api;

use ApiBase;
use ApiModuleManager;

/**
 * API module for all write operations.
 * Each operation (command) is implemented as a submodule.
 */
class ApiReadingListsDeleteEntry extends ApiBase {

	use ApiTrait;

	/** @var string API module prefix */
	private static $prefix = '';

	/**
	 * Entry point for executing the module
	 * @inheritdoc
	 * @return void
	 */
	public function execute() {
		$entryId = $this->getParameter( 'entry' );

		$this->getReadingListRepository( $this->getUser() )->deleteListEntry( $entryId );
	}

	/**
	 * @inheritdoc
	 * @return array
	 */
	protected function getAllowedParams() {
		return [
			'entry' => [
				self::PARAM_TYPE => 'integer',
				self::PARAM_REQUIRED => true,
			],
		];
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
			'action=readinglists&command=deleteentry&entry=8&token=123ABC'
				=> 'apihelp-readinglists+deleteentry-example-1',
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
