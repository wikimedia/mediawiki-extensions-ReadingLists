<?php

namespace MediaWiki\Extensions\ReadingLists\Api;

use ApiBase;

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
		$this->requireAtLeastOneParameter( $params, 'name', 'description', 'color', 'image', 'icon' );

		$this->getReadingListRepository( $this->getUser() )->updateList( $params['list'],
			$params['name'], $params['description'], $params['color'], $params['image'], $params['icon'] );
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
			],
			'description' => [
				self::PARAM_TYPE => 'string',
			],
			'color' => [
				self::PARAM_TYPE => 'string',
			],
			'image' => [
				self::PARAM_TYPE => 'string',
			],
			'icon' => [
				self::PARAM_TYPE => 'string',
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
