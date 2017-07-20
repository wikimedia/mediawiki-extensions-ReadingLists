<?php

namespace MediaWiki\Extensions\ReadingLists\Api;

use ApiBase;
use ApiModuleManager;

/**
 * API module for all write operations.
 * Each operation (command) is implemented as a submodule.
 */
class ApiReadingListsCreateEntry extends ApiBase {

	use ApiTrait;

	/** @var string API module prefix */
	private static $prefix = '';

	/**
	 * Entry point for executing the module
	 * @inheritdoc
	 * @return void
	 */
	public function execute() {
		$listId = $this->getParameter( 'list' );
		$project = $this->getParameter( 'project' );
		$title = $this->getParameter( 'title' );

		$entryId = $this->getReadingListRepository( $this->getUser() )
			->addListEntry( $listId, $project, $title );
		$this->getResult()->addValue( null, $this->getModuleName(), [ 'id' => $entryId ] );
	}

	/**
	 * @inheritdoc
	 * @return array
	 */
	protected function getAllowedParams() {
		return [
			'list' => [
				self::PARAM_TYPE => 'integer',
				self::PARAM_REQUIRED => true,
			],
			'project' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_REQUIRED => true,
			],
			'title' => [
				self::PARAM_TYPE => 'string',
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
			'action=readinglists&command=createentry&list=33&'
				. 'project=en.wikipedia.org&title=Dog&token=123ABC'
				=> 'apihelp-readinglists+createentry-example-1',
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
