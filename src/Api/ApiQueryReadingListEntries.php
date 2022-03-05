<?php

namespace MediaWiki\Extension\ReadingLists\Api;

use ApiPageSet;
use ApiQueryGeneratorBase;
use LogicException;
use MediaWiki\Extension\ReadingLists\Doc\ReadingListEntryRow;
use MediaWiki\Extension\ReadingLists\ReadingListRepositoryException;
use MediaWiki\Extension\ReadingLists\ReverseInterwikiLookup;
use MediaWiki\Extension\ReadingLists\Utils;
use MediaWiki\MediaWikiServices;
use Title;

/**
 * API list module for getting list contents.
 */
class ApiQueryReadingListEntries extends ApiQueryGeneratorBase {

	use ApiTrait;
	use ApiQueryTrait;

	/** @var string API module prefix */
	private static $prefix = 'rle';

	/**
	 * @inheritDoc
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
		if ( !$this->getUser()->isRegistered() ) {
			$this->dieWithError( [ 'apierror-mustbeloggedin',
				$this->msg( 'action-viewmyprivateinfo' ) ], 'notloggedin' );
		}
		$this->checkUserRightsAny( 'viewmyprivateinfo' );

		$lists = $this->getParameter( 'lists' );
		$changedSince = $this->getParameter( 'changedsince' );
		$sort = $this->getParameter( 'sort' );
		$dir = $this->getParameter( 'dir' );
		$limit = $this->getParameter( 'limit' );
		$continue = $this->getParameter( 'continue' );

		$mode = $changedSince !== null ? self::$MODE_CHANGES : self::$MODE_ALL;
		if ( $sort === null ) {
			$sort = ( $mode === self::$MODE_CHANGES ) ? 'updated' : 'name';
		}
		if ( $mode === self::$MODE_CHANGES && $sort === 'name' ) {
			// We don't have the right DB index for this. Wouldn't make much sense anyways.
			$errorMessage = $this->msg( 'apierror-readinglists-invalidsort-notbyname', static::$prefix );
			$this->dieWithError( $errorMessage, 'invalidparammix' );
		}
		$sort = self::$sortParamMap[$sort];
		$dir = self::$sortParamMap[$dir];
		$continue = $this->decodeContinuationParameter( $continue, $mode, $sort );

		$this->requireOnlyOneParameter( $this->extractRequestParams(), 'lists', 'changedsince' );
		if ( $mode === self::$MODE_CHANGES ) {
			$expiry = Utils::getDeletedExpiry();
			if ( $changedSince < $expiry ) {
				$errorMessage = $this->msg( 'apierror-readinglists-too-old', static::$prefix,
					wfTimestamp( TS_ISO_8601, $expiry ) );
				$this->dieWithError( $errorMessage );
			}
		}

		$path = [ 'query', $this->getModuleName() ];
		$result = $this->getResult();
		$result->addIndexedTagName( $path, 'entry' );

		$repository = $this->getReadingListRepository( $this->getUser() );
		if ( $mode === self::$MODE_CHANGES ) {
			$res = $repository->getListEntriesByDateUpdated( $changedSince, $dir, $limit + 1, $continue );
		} else {
			$res = $repository->getListEntries( $lists, $sort, $dir, $limit + 1, $continue );
		}
		'@phan-var stdClass[] $res';
		$titles = [];
		$fits = true;
		foreach ( $res as $i => $row ) {
			// @phan-suppress-next-line PhanTypeMismatchArgument
			$item = $this->getResultItem( $row, $mode );
			if ( $i >= $limit ) {
				// we reached the extra row.
				$this->setContinueEnumParameter( 'continue',
					$this->encodeContinuationParameter( $item, $mode, $sort ) );
				break;
			}
			if ( $resultPageSet ) {
				// @phan-suppress-next-line PhanTypeMismatchArgument
				$titles[] = $this->getResultTitle( $row );
			} else {
				$fits = $result->addValue( $path, null, $item );
			}
			if ( !$fits ) {
				$this->setContinueEnumParameter( 'continue',
					$this->encodeContinuationParameter( $item, $mode, $sort ) );
				break;
			}
		}
		if ( $resultPageSet ) {
			$resultPageSet->populateFromTitles( $titles );
		}
	}

	/**
	 * @inheritDoc
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
		] + $this->getAllowedSortParams();
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
		if ( $row->rle_deleted && $mode !== self::$MODE_CHANGES ) {
			$this->logger->error( 'Deleted row returned in non-changes mode', [
				'rle_id' => $row->rle_id,
				'rl_id' => $row->rle_rl_id,
				'user_central_id' => $row->rle_user_id,
			] );
			throw new LogicException( 'Deleted row returned in non-changes mode' );
		}
		return $this->getListEntryFromRow( $row );
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
			$title = implode( ':', array_slice( $interwikiPrefix, 1 ) ) . ':' . $row->rle_title;
			$prefix = $interwikiPrefix[0];
			return Title::makeTitle( NS_MAIN, $title, '', $prefix );
		}
		// For lack of a better option let's create an invalid title.
		// ApiPageSet::populateFromTitles() is not documented to accept strings
		// but it will actually work.
		return 'Invalid project|' . $row->rlp_project . '|' . $row->rle_title;
	}

}
