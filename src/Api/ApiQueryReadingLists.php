<?php

namespace MediaWiki\Extensions\ReadingLists\Api;

use ApiQueryBase;
use DateTime;
use DateTimeZone;
use LogicException;
use MediaWiki\Extensions\ReadingLists\Doc\ReadingListRow;
use MediaWiki\Extensions\ReadingLists\ReadingListRepositoryException;
use MediaWiki\Extensions\ReadingLists\Utils;
use MWTimestamp;

/**
 * API meta module for getting list metadata.
 */
class ApiQueryReadingLists extends ApiQueryBase {

	use ApiTrait;
	use ApiQueryTrait;

	/** @var string API module prefix */
	private static $prefix = 'rl';

	/**
	 * @inheritDoc
	 * @return void
	 */
	public function execute() {
		try {
			if ( $this->getUser()->isAnon() ) {
				$this->dieWithError( [ 'apierror-mustbeloggedin',
					$this->msg( 'action-viewmyprivateinfo' ) ], 'notloggedin' );
			}
			$this->checkUserRightsAny( 'viewmyprivateinfo' );

			$listId = $this->getParameter( 'list' );
			$changedSince = $this->getParameter( 'changedsince' );
			$project = $this->getParameter( 'project' );
			$title = $this->getParameter( 'title' );
			$sort = $this->getParameter( 'sort' );
			$dir = $this->getParameter( 'dir' );
			$limit = $this->getParameter( 'limit' );
			$continue = $this->getParameter( 'continue' );

			$path = [ 'query', $this->getModuleName() ];
			$result = $this->getResult();
			$result->addIndexedTagName( $path, 'list' );
			$repository = $this->getReadingListRepository( $this->getUser() );

			$mode = null;
			$this->requireMaxOneParameter( $this->extractRequestParams(), 'list', 'title', 'changedsince' );
			if ( $project !== null && $title !== null ) {
				$mode = self::$MODE_PAGE;
			} elseif ( $project !== null || $title !== null ) {
				$errorMessage = $this->msg( 'apierror-readinglists-project-title-param', static::$prefix );
				$this->dieWithError( $errorMessage, 'missingparam' );
			} elseif ( $changedSince !== null ) {
				$expiry = Utils::getDeletedExpiry();
				if ( $changedSince < $expiry ) {
					$errorMessage = $this->msg( 'apierror-readinglists-too-old', static::$prefix,
						wfTimestamp( TS_ISO_8601, $expiry ) );
					$this->dieWithError( $errorMessage );
				}
				$mode = self::$MODE_CHANGES;
			} elseif ( $listId !== null ) {
				// FIXME 'dir' and 'limit' aren't compatible either but requireMaxOneParameter
				// does not work with parameters which have a default value
				$params = [ 'sort', 'continue' ];
				foreach ( $params as $name ) {
					$this->requireMaxOneParameter( $this->extractRequestParams(), 'list', $name );
				}
				$mode = self::$MODE_ID;
			} else {
				$mode = self::$MODE_ALL;
			}

			if ( $sort === null ) {
				$sort = ( $mode === self::$MODE_CHANGES ) ? 'updated' : 'name';
			}
			$sort = self::$sortParamMap[$sort];
			$dir = self::$sortParamMap[$dir];
			$continue = $this->decodeContinuationParameter( $continue, $mode, $sort );
			// timestamp from before querying the DB
			$timestamp = new DateTime( 'now', new DateTimeZone( 'GMT' ) );

			if ( $mode === self::$MODE_PAGE ) {
				$res = $repository->getListsByPage( $project, $title, $limit + 1, $continue );
			} elseif ( $mode === self::$MODE_CHANGES ) {
				$res = $repository->getListsByDateUpdated( $changedSince, $sort, $dir, $limit + 1, $continue );
			} elseif ( $mode === self::$MODE_ID ) {
				$res = [ $repository->selectValidList( $listId ) ];
			} else {
				$res = $repository->getAllLists( $sort, $dir, $limit + 1, $continue );
			}
			foreach ( $res as $i => $row ) {
				$item = $this->getResultItem( $row, $mode );
				if ( $i >= $limit ) {
					// we reached the extra row.
					$this->setContinueEnumParameter( 'continue',
						$this->encodeContinuationParameter( $item, $mode, $sort ) );
					break;
				}
				$fits = $result->addValue( $path, null, $item );
				if ( !$fits ) {
					$this->setContinueEnumParameter( 'continue',
						$this->encodeContinuationParameter( $item, $mode, $sort ) );
					break;
				}
			}

			// Add a timestamp that, when used in the changedsince parameter in the readinglists
			// and readinglistentries modules, guarantees that no change will be skipped (at the
			// cost of possibly repeating some changes in the current query). See T182706 for details.
			if ( !$continue ) {
				// Ignore continuations (the client should just use the timestamp received in the
				// first step). Otherwise, backdate more than the max transaction duration, to
				// prevent the situation where a DB write happens before the current request
				// but is only committed after it. (If there is no max transaction duration, the
				// client is on its own.)
				global $wgMaxUserDBWriteDuration;
				if ( $wgMaxUserDBWriteDuration ) {
					$timestamp->modify( '-' . ( $wgMaxUserDBWriteDuration + 1 ) . ' seconds' );
				}
				$syncTimestamp = ( new MWTimestamp( $timestamp ) )->getTimestamp( TS_ISO_8601 );
				$result->addValue( 'query', 'readinglists-synctimestamp', $syncTimestamp );
			}
		} catch ( ReadingListRepositoryException $e ) {
			$this->dieWithException( $e );
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
				self::PARAM_REQUIRED => false,
				self::PARAM_MIN => 1,
				self::PARAM_DFLT => null,
			],
			'project' => [
				self::PARAM_TYPE => 'string',
			],
			'title' => [
				self::PARAM_TYPE => 'string',
			],
			'changedsince' => [
				self::PARAM_TYPE => 'timestamp',
				self::PARAM_HELP_MSG => $this->msg( 'apihelp-query+readinglists-param-changedsince',
					wfTimestamp( TS_ISO_8601, Utils::getDeletedExpiry() ) ),
			],
		] + $this->getAllowedSortParams();
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
			'action=query&meta=readinglists'
				=> 'apihelp-query+readinglists-example-1',
			"action=query&meta=readinglists&${prefix}changedsince=2013-01-01T00:00:00Z"
				=> 'apihelp-query+readinglists-example-2',
			"action=query&meta=readinglists&${prefix}project=https%3A%2F%2Fen.wikipedia.org"
				. "&${prefix}title=Dog"
				=> 'apihelp-query+readinglists-example-3',
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
	 * Transform a row into an API result item
	 * @param ReadingListRow $row List row, with additions from addExtraData().
	 * @param string $mode One of the MODE_* constants.
	 * @return array
	 */
	private function getResultItem( $row, $mode ) {
		if ( $row->rl_deleted && $mode !== self::$MODE_CHANGES ) {
			$this->logger->error( 'Deleted row returned in non-changes mode', [
				'rl_id' => $row->rl_id,
				'user_central_id' => $row->rl_user_id,
			] );
			throw new LogicException( 'Deleted row returned in non-changes mode' );
		}
		return $this->getListFromRow( $row );
	}

}
