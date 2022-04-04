<?php

namespace MediaWiki\Extension\ReadingLists\Api;

use ApiBase;
use Wikimedia\ParamValidator\ParamValidator;

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
	 * @inheritDoc
	 */
	public function execute() {
		$params = $this->extractRequestParams();
		$this->requireOnlyOneParameter( $params, 'entry', 'batch' );

		$repository = $this->getReadingListRepository( $this->getUser() );
		if ( isset( $params['entry'] ) ) {
			$repository->deleteListEntry( $params['entry'] );
		} else {
			foreach ( $this->getBatchOps( $params['batch'] ) as $op ) {
				$this->requireAtLeastOneBatchParameter( $op, 'entry' );
				$repository->deleteListEntry( $op['entry'] );
			}
		}
	}

	/**
	 * @inheritDoc
	 */
	protected function getAllowedParams() {
		return [
			'entry' => [
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
			[ 'entry' => 8 ],
			[ 'entry' => 9 ],
		] ) ] );
		return [
			'action=readinglists&command=deleteentry&entry=8&token=123ABC'
				=> 'apihelp-readinglists+deleteentry-example-1',
			"action=readinglists&command=deleteentry&$batch&token=123ABC"
				=> 'apihelp-readinglists+deleteentry-example-2',
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
