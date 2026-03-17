<?php

namespace MediaWiki\Extension\ReadingLists\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Extension\ReadingLists\ReadingListRepository;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\StringDef;

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
		if ( isset( $params['project'] ) && !isset( $params['title'] ) ) {
			$this->dieWithError( [ 'apierror-missingparam', 'title' ], 'missingparam' );
		} elseif ( isset( $params['title'] ) && !isset( $params['project'] ) ) {
			$this->dieWithError( [ 'apierror-missingparam', 'project' ], 'missingparam' );
		}
		$this->requireOnlyOneParameter( $params, 'entry', 'project', 'batch' );

		$repository = $this->getReadingListRepository( $this->getUser() );
		if ( isset( $params['entry'] ) ) {
			$repository->deleteListEntry( $params['entry'] );
		} elseif ( isset( $params['project'] ) ) {
			if ( $this->isLocalProject( $params['project'] ) ) {
				$this->validateTitle( $params['title'] );
			}
			$repository->deleteListEntriesByPageTitleAndProject( $params['title'], $params['project'] );
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
			'project' => [
				ParamValidator::PARAM_TYPE => 'string',
				StringDef::PARAM_MAX_BYTES => ReadingListRepository::$fieldLength['rlp_project'],
			],
			'title' => [
				ParamValidator::PARAM_TYPE => 'string',
				StringDef::PARAM_MAX_BYTES => ReadingListRepository::$fieldLength['rle_title'],
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
			'action=readinglists&command=deleteentry&project=https://en.wikipedia.org&title=Dog&token=123ABC'
				=> 'apihelp-readinglists+deleteentry-example-3',
			'action=readinglists&command=deleteentry&project=@local&title=Garfield%20the%20Cat&token=123ABC'
				=> 'apihelp-readinglists+deleteentry-example-4',
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
