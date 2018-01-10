<?php

namespace MediaWiki\Extensions\ReadingLists\Api;

use ApiBase;

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
	 * @return void
	 */
	public function execute() {
		$params = $this->extractRequestParams();
		$this->requireOnlyOneParameter( $params, 'entry', 'batch' );

		$repository = $this->getReadingListRepository( $this->getUser() );
		if ( isset( $params['entry'] ) ) {
			$repository->deleteListEntry( $params['entry'] );
		} else {
			foreach ( $this->yieldBatchOps( $params['batch'] ) as $op ) {
				$this->requireAtLeastOneBatchParameter( $op, 'entry' );
				$repository->deleteListEntry( $op['entry'] );
			}
		}
	}

	/**
	 * @inheritDoc
	 * @return array
	 */
	protected function getAllowedParams() {
		return [
			'entry' => [
				self::PARAM_TYPE => 'integer',
			],
			'batch' => [
				self::PARAM_TYPE => 'string',
			]
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
