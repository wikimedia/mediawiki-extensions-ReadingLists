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
		$listId = $this->getParameter( 'list' );
		$project = $this->getParameter( 'project' );
		$title = $this->getParameter( 'title' );

		// Lists can contain titles from other wikis, and we have no idea of the exact title
		// validation rules used there; but in practice it's unlikely the rules would differ,
		// and allowing things like <> or # in the title could result in vulnerabilities in
		// clients that assume they are getting something sane. So let's validate anyway.
		// We do not normalize, that would contain too much local logic (e.g. title case), and
		// clients are expected to submit already normalized titles (that they got from the API) anyway.
		if ( !Title::newFromText( $title ) ) {
			$this->dieWithError( 'apierror-invalidtitle', wfEscapeWikiText( $title ) );
		}

		$entryId = $this->getReadingListRepository( $this->getUser() )
			->addListEntry( $listId, $project, $title );
		$this->getResult()->addValue( null, $this->getModuleName(), [ 'id' => $entryId ] );
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
				self::PARAM_REQUIRED => true,
				self::PARAM_MAX_BYTES => ReadingListRepository::$fieldLength['rle_project'],
			],
			'title' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_REQUIRED => true,
				self::PARAM_MAX_BYTES => ReadingListRepository::$fieldLength['rle_title'],
			],
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
		return [
			'action=readinglists&command=createentry&list=33&'
				. 'project=en.wikipedia.org&title=Dog&token=123ABC'
				=> 'apihelp-readinglists+createentry-example-1',
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
