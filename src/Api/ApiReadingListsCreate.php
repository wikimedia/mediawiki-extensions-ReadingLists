<?php

namespace MediaWiki\Extensions\ReadingLists\Api;

use ApiBase;
use ApiModuleManager;

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
	 * @inheritdoc
	 * @return void
	 */
	public function execute() {
		$params = $this->extractRequestParams();

		$listId = $this->getReadingListRepository( $this->getUser() )->addList( $params['name'],
			$params['description'], $params['color'], $params['image'], $params['icon'] );
		$this->getResult()->addValue( null, $this->getModuleName(), [ 'id' => $listId ] );
	}

	/**
	 * @inheritdoc
	 * @return array
	 */
	protected function getAllowedParams() {
		return [
			'name' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_REQUIRED => true,
			],
			'description' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_DFLT => '',
			],
			'color' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_DFLT => '',
			],
			'image' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_DFLT => '',
			],
			'icon' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_DFLT => '',
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
			'action=readinglists&command=create&name=dogs&description=Woof!&token=123ABC'
				=> 'apihelp-readinglists+create-example-1',
			'action=readinglists&command=create&name=dogs&description=Woof!'
				. '&color=brown&image=File:Australien_Kelpie.jpg&icon=File:Icon dog.gif&token=123ABC'
				=> 'apihelp-readinglists+create-example-2',
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
