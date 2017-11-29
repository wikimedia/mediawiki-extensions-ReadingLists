<?php

namespace MediaWiki\Extensions\ReadingLists\Api;

use ApiPageSet;
use ApiQueryGeneratorBase;
use MediaWiki\Extensions\ReadingLists\Doc\ReadingListEntryRow;
use MediaWiki\Extensions\ReadingLists\ReadingListRepositoryException;
use MediaWiki\Extensions\ReadingLists\ReverseInterwikiLookup;
use MediaWiki\Extensions\ReadingLists\Utils;
use MediaWiki\MediaWikiServices;
use Title;

/**
 * API list module for getting list contents.
 */
class ApiQueryReadingListEntries extends ApiQueryGeneratorBase {

	use ApiTrait;

	/**
	 * Return all entries of the given list(s).
	 * Intended for initial copy of data to a new device, or for devices which have information
	 * that's too outdated for normal sync. Might also be useful for devices with limited storage
	 * capacity, such as web clients.
	 */
	const MODE_ALL = 'all';

	/**
	 * Return list entries (from any list of the user) which have been changed (or deleted) recently.
	 * Intended for syncing updates to a device which has an older snapshot of the data.
	 * "Recently" is defined by the changedsince parameter.
	 */
	const MODE_CHANGES = 'changes';

	/** @var string API module prefix */
	private static $prefix = 'rle';

	/**
	 * @inheritDoc
	 * @return void
	 */
	public function execute() {
		try {
			$this->run();
		} catch ( ReadingListRepositoryException $e ) {
			$this->dieWithException( $e );
		}
	}

	/**
	 * @inheritDoc
	 * @param ApiPageSet $resultPageSet All output should be appended to this object
	 * @return void
	 */
	public function executeGenerator( $resultPageSet ) {
		try {
			$this->run( $resultPageSet );
		} catch ( ReadingListRepositoryException $e ) {
			$this->dieWithException( $e );
		}
	}

	/**
	 * Main API logic.
	 * @param ApiPageSet|null $resultPageSet
	 */
	private function run( ApiPageSet $resultPageSet = null ) {
		if ( $this->getUser()->isAnon() ) {
			$this->dieWithError( [ 'apierror-mustbeloggedin',
				$this->msg( 'action-viewmyprivateinfo' ) ], 'notloggedin' );
		}
		$this->checkUserRightsAny( 'viewmyprivateinfo' );

		$lists = $this->getParameter( 'lists' );
		$changedSince = $this->getParameter( 'changedsince' );
		$limit = $this->getParameter( 'limit' );
		$offset = $this->getParameter( 'continue' );

		$mode = $changedSince !== null ? self::MODE_CHANGES : self::MODE_ALL;

		$this->requireOnlyOneParameter( $this->extractRequestParams(), 'lists', 'changedsince' );
		if ( $mode === self::MODE_CHANGES ) {
			$expiry = Utils::getDeletedExpiry();
			if ( $changedSince < $expiry ) {
				$errorMessage = $this->msg( 'readinglists-apierror-too-old', static::$prefix,
					wfTimestamp( TS_ISO_8601, $expiry ) );
				$this->dieWithError( $errorMessage );
			}
		}

		$path = [ 'query', $this->getModuleName() ];
		$result = $this->getResult();
		$result->addIndexedTagName( $path, 'entry' );

		$repository = $this->getReadingListRepository( $this->getUser() );
		if ( $mode === self::MODE_CHANGES ) {
			$res = $repository->getListEntriesByDateUpdated( $changedSince, $limit + 1, $offset );
		} else {
			$res = $repository->getListEntries( $lists, $limit + 1, $offset );
		}
		$titles = [];
		$resultOffset = 0;
		$fits = true;
		foreach ( $res as $row ) {
			$isLastRow = ( $resultOffset === $res->numRows() - 1 );
			if ( $resultPageSet ) {
				$titles[] = $this->getResultTitle( $row );
			} else {
				$fits = $result->addValue( $path, null, $this->getResultItem( $row, $mode ) );
			}
			if ( !$fits || ++$resultOffset >= $limit && !$isLastRow ) {
				$this->setContinueEnumParameter( 'continue', $offset + $resultOffset );
				break;
			}
		}
		if ( $resultPageSet ) {
			$resultPageSet->populateFromTitles( $titles );
		}
	}

	/**
	 * @inheritDoc
	 * @return array
	 */
	protected function getAllowedParams() {
		return [
			'lists' => [
				self::PARAM_TYPE => 'integer',
				self::PARAM_ISMULTI => true,
			],
			'changedsince' => [
				self::PARAM_TYPE => 'timestamp',
				self::PARAM_HELP_MSG => $this->msg( 'apihelp-query+readinglistentries-param-changedsince',
					wfTimestamp( TS_ISO_8601, Utils::getDeletedExpiry() ) ),
			],
			'limit' => [
				self::PARAM_DFLT => 10,
				self::PARAM_TYPE => 'limit',
				self::PARAM_MIN => 1,
				self::PARAM_MAX => self::LIMIT_BIG1,
				self::PARAM_MAX2 => self::LIMIT_BIG2,
			],
			'continue' => [
				self::PARAM_TYPE => 'integer',
				self::PARAM_DFLT => 0,
				self::PARAM_HELP_MSG => 'api-help-param-continue',
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
		$prefix = static::$prefix;
		return [
			"action=query&list=readinglistentries&${prefix}lists=10|11|12"
				=> 'apihelp-query+readinglistentries-example-1',
			"action=query&list=readinglistentries&${prefix}changedsince=2013-01-01T00:00:00Z"
				=> 'apihelp-query+readinglistentries-example-2',
		];
	}

	/**
	 * @inheritDoc
	 * @return bool
	 */
	public function isInternal() {
		// ReadingLists API is still experimental
		return true;
	}

	/**
	 * Initialize a reverse interwiki lookup helper.
	 * @return ReverseInterwikiLookup
	 */
	private function getReverseInterwikiLookup() {
		return MediaWikiServices::getInstance()->getService( 'ReverseInterwikiLookup' );
	}

	/**
	 * Transform a row into an API result item
	 * @param ReadingListEntryRow $row
	 * @param string $mode One of the MODE_* constants.
	 * @return array
	 */
	private function getResultItem( $row, $mode ) {
		return [
			'id' => (int)$row->rle_id,
			'listId' => (int)$row->rle_rl_id,
			'project' => $row->rlp_project,
			'title' => $row->rle_title,
			'created' => wfTimestamp( TS_ISO_8601, $row->rle_date_created ),
			'updated' => wfTimestamp( TS_ISO_8601, $row->rle_date_updated ),
	   ] + ( $mode === self::MODE_CHANGES ? [ 'deleted' => (bool)$row->rle_deleted ] : [] );
	}

	/**
	 * Transform a row into an API result item
	 * @param ReadingListEntryRow $row
	 * @return Title|string
	 */
	private function getResultTitle( $row ) {
		$interwikiPrefix = $this->getReverseInterwikiLookup()->lookup( $row->rlp_project );
		if ( is_string( $interwikiPrefix ) ) {
			if ( $interwikiPrefix === '' ) {
				$title = Title::newFromText( $row->rle_title );
				if ( !$title ) {
					// Validation differences between wikis? Let's just return it as it is.
					$title = Title::makeTitle( NS_MAIN, $row->rle_title );
				}
			} else {
				// We have no way of telling what the namespace is, but Title does not support
				// foreign namespaces anyway. Let's just pretend it's in the main namespace so
				// the prefixed title string works out as expected.
				$title = Title::makeTitle( NS_MAIN, $row->rle_title, '', $interwikiPrefix );
			}
			return $title;
		} elseif ( is_array( $interwikiPrefix ) ) {
			$title = implode( ':', array_slice( $interwikiPrefix, 1 ) ). ':' . $row->rle_title;
			$prefix = $interwikiPrefix[0];
			return Title::makeTitle( NS_MAIN, $title, '', $prefix );
		}
		// For lack of a better option let's create an invalid title.
		// ApiPageSet::populateFromTitles() is not documented to accept strings
		// but it will actually work.
		return 'Invalid project|' . $row->rlp_project . '|' . $row->rle_title;
	}

}
