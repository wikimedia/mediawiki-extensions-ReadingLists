<?php

namespace MediaWiki\Extension\ReadingLists\Api;

use MediaWiki\Api\ApiBase;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * API module for all write operations.
 * Each operation (command) is implemented as a submodule.
 */
class ApiReadingListsDelete extends ApiBase {

	use ApiTrait;

	/** @var string API module prefix */
	private static $prefix = '';

	/**
	 * Entry point for executing the module
	 * @inheritDoc
	 */
	public function execute() {
		$params = $this->extractRequestParams();
		$this->requireOnlyOneParameter( $params, 'list', 'batch' );

		$repository = $this->getReadingListRepository( $this->getUser() );
		if ( isset( $params['list'] ) ) {
			$repository->deleteList( $params['list'] );
		} else {
			foreach ( $this->getBatchOps( $params['batch'] ) as $op ) {
				$this->requireAtLeastOneBatchParameter( $op, 'list' );
				$repository->deleteList( $op['list'] );
			}
		}
	}

	/**
	 * @inheritDoc
	 */
	protected function getAllowedParams() {
		return [
			'list' => [
				ParamValidator::PARAM_TYPE => 'integer',
			],
			'batch' => [
				ParamValidator::PARAM_TYPE => 'string',
			]
		];
	}

	/**
	 * @inheritDoc
	 */
	public function getHelpUrls() {
		return [
			'https://www.mediawiki.org/wiki/Special:MyLanguage/Extension:ReadingLists#API',
		];
	}

	/**
	 * @inheritDoc
	 */
	protected function getExamplesMessages() {
		$batch = wfArrayToCgi( [ 'batch' => json_encode( [
			[ 'list' => 11 ],
			[ 'list' => 12 ],
		] ) ] );
		return [
			'action=readinglists&command=delete&list=11&token=123ABC'
				=> 'apihelp-readinglists+delete-example-1',
			"action=readinglists&command=delete&$batch&token=123ABC"
				=> 'apihelp-readinglists+delete-example-2',
		];
	}

	// The parent module already enforces these but they make documentation nicer.

	/**
	 * @inheritDoc
	 */
	public function isWriteMode() {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function mustBePosted() {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function isInternal() {
		// ReadingLists API is still experimental
		return true;
	}

}
