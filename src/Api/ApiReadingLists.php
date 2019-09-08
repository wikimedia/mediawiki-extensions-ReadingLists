<?php

namespace MediaWiki\Extensions\ReadingLists\Api;

use ApiBase;
use ApiModuleManager;
use MediaWiki\Extensions\ReadingLists\ReadingListRepositoryException;
use MediaWiki\MediaWikiServices;

/**
 * API parent module for all write operations.
 * Each operation (command) is implemented as a submodule. This module just performs some
 * basic checks and dispatches the execute() call.
 */
class ApiReadingLists extends ApiBase {

	/** @var array Module name => module class */
	private static $submodules = [
		'setup' => ApiReadingListsSetup::class,
		'teardown' => ApiReadingListsTeardown::class,
		'create' => ApiReadingListsCreate::class,
		'update' => ApiReadingListsUpdate::class,
		'delete' => ApiReadingListsDelete::class,
		'createentry' => ApiReadingListsCreateEntry::class,
		'deleteentry' => ApiReadingListsDeleteEntry::class,
	];

	/** @var ApiModuleManager */
	private $moduleManager;

	/**
	 * Entry point for executing the module
	 * @inheritDoc
	 * @return void
	 */
	public function execute() {
		if ( $this->getUser()->isAnon() ) {
			$this->dieWithError( [ 'apierror-mustbeloggedin',
				$this->msg( 'action-editmyprivateinfo' ) ], 'notloggedin' );
		}
		$this->checkUserRightsAny( 'editmyprivateinfo' );

		$command = $this->getParameter( 'command' );
		$module = $this->moduleManager->getModule( $command, 'command' );
		$module->extractRequestParams();
		try {
			$module->execute();
			$module->getResult()->addValue( null, $module->getModuleName(), [ 'result' => 'Success' ] );
		} catch ( ReadingListRepositoryException $e ) {
			$module->getResult()->addValue( null, $module->getModuleName(), [ 'result' => 'Failure' ] );
			$this->dieWithException( $e );
		}
	}

	/**
	 * @inheritDoc
	 * @return ApiModuleManager
	 */
	public function getModuleManager() {
		if ( !$this->moduleManager ) {
			$modules = array_map( function ( $class ) {
				return [
					'class' => $class,
					'factory' => "$class::factory",
				];
			}, self::$submodules );
			$this->moduleManager = new ApiModuleManager(
				$this,
				MediaWikiServices::getInstance()->getObjectFactory()
			);
			$this->moduleManager->addModules( $modules, 'command' );
		}
		return $this->moduleManager;
	}

	/**
	 * @inheritDoc
	 * @return array
	 */
	protected function getAllowedParams() {
		return [
			'command' => [
				self::PARAM_TYPE => 'submodule',
				self::PARAM_REQUIRED => true,
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
	 * @return bool
	 */
	public function isWriteMode() {
		return true;
	}

	/**
	 * @inheritDoc
	 * @return bool
	 */
	public function needsToken() {
		return 'csrf';
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
