<?php

namespace MediaWiki\Extensions\ReadingLists\Api;

use ApiBase;
use MediaWiki\Extensions\ReadingLists\ReadingListRepository;
use Message;
use Title;

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
	 * @inheritDoc
	 * @return void
	 */
	public function execute() {
		$params = $this->extractRequestParams();
		$listId = $this->getParameter( 'list' );
		$this->requireOnlyOneParameter( $params, 'project', 'batch' );
		$this->requireOnlyOneParameter( $params, 'title', 'batch' );

		$repository = $this->getReadingListRepository( $this->getUser() );
		if ( isset( $params['project'] ) ) {
			// Lists can contain titles from other wikis, and we have no idea of the exact title
			// validation rules used there; but in practice it's unlikely the rules would differ,
			// and allowing things like <> or # in the title could result in vulnerabilities in
			// clients that assume they are getting something sane. So let's validate anyway.
			// We do not normalize, that would contain too much local logic (e.g. title case), and
			// clients are expected to submit already normalized titles (that they got from the API)
			// anyway.
			if ( !Title::newFromText( $params['title'] ) ) {
				$this->dieWithError( [ 'apierror-invalidtitle', wfEscapeWikiText( $params['title'] ) ] );
			}

			$entry = $repository->addListEntry( $listId, $params['project'], $params['title'] );
			$entryData = $this->getListEntryFromRow( $entry );
			$this->getResult()->addValue( null, $this->getModuleName(),
				[ 'id' => $entry->rle_id, 'entry' => $entryData ] );
		} else {
			$entryIds = $entryData = [];
			foreach ( $this->getBatchOps( $params['batch'] ) as $op ) {
				$this->requireAtLeastOneBatchParameter( $op, 'project' );
				$this->requireAtLeastOneBatchParameter( $op, 'title' );
				if ( !Title::newFromText( $op['title'] ) ) {
					$this->dieWithError( [ 'apierror-invalidtitle', wfEscapeWikiText( $op['title'] ) ] );
				}
				$entry = $repository->addListEntry( $listId, $op['project'], $op['title'] );
				$entryIds[] = $entry->rle_id;
				$entryData[] = $this->getListEntryFromRow( $entry );
			}
			$this->getResult()->addValue( null, $this->getModuleName(),
				[ 'ids' => $entryIds, 'entries' => $entryData ] );
			$this->getResult()->addIndexedTagName( [ $this->getModuleName(), 'ids' ], 'id' );
			$this->getResult()->addIndexedTagName( [ $this->getModuleName(), 'entries' ], 'entry' );
		}
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
			'project' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_MAX_BYTES => ReadingListRepository::$fieldLength['rlp_project'],
			],
			'title' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_MAX_BYTES => ReadingListRepository::$fieldLength['rle_title'],
			],
			'batch' => [
				self::PARAM_TYPE => 'string',
			]
		];
	}

	/**
	 * @inheritDoc
	 * @return Message
	 */
	protected function getExtendedDescription() {
		$limit = $this->getConfig()->get( 'ReadingListsMaxEntriesPerList' );
		return wfMessage( 'apihelp-readinglists+createentry-extended-description', $limit );
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
			[ 'project' => 'https://en.wikipedia.org', 'title' => 'Dog' ],
			[ 'project' => 'https://en.wikipedia.org', 'title' => 'Cat' ],
		] ) ] );
		return [
			'action=readinglists&command=createentry&list=33&'
				. 'project=https%3A%2F%2Fen.wikipedia.org&title=Dog&token=123ABC'
				=> 'apihelp-readinglists+createentry-example-1',
			"action=readinglists&command=createentry&list=33&$batch&token=123ABC"
				=> 'apihelp-readinglists+createentry-example-2',
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
