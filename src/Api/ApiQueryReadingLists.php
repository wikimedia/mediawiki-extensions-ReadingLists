<?php

namespace MediaWiki\Extensions\ReadingLists\Api;

use ApiQueryBase;
use MediaWiki\Extensions\ReadingLists\Doc\ReadingListRow;
use MediaWiki\Extensions\ReadingLists\ReadingListRepository;
use MediaWiki\Extensions\ReadingLists\ReadingListRepositoryException;
use MediaWiki\Extensions\ReadingLists\Utils;

/**
 * API meta module for getting list metadata.
 */
class ApiQueryReadingLists extends ApiQueryBase {

	use ApiTrait;

	/**
	 * Return all lists.
	 * Intended for initial copy of data to a new device, or for devices which have information
	 * that's too outdated for normal sync. Might also be useful for devices with limited storage
	 * capacity, such as web clients.
	 */
	const MODE_ALL = 'all';

	/**
	 * Return lists which have been changed (or deleted) recently.
	 * Intended for syncing updates to a device which has an older snapshot of the data.
	 * "Recently" is defined by the changedsince parameter.
	 */
	const MODE_CHANGES = 'changes';

	/**
	 * Return lists which include a given page.
	 * Intended for status indicators and such (e.g. showing a star on the current page if it's
	 * included in some list).
	 */
	const MODE_PAGE = 'page';

	/** @var string API module prefix */
	private static $prefix = 'rl';

	/**
	 * @inheritdoc
	 * @return void
	 */
	public function execute() {
		try {
			if ( $this->getUser()->isAnon() ) {
				$this->dieWithError( [ 'apierror-mustbeloggedin',
					$this->msg( 'action-viewmyprivateinfo' ) ], 'notloggedin' );
			}
			$this->checkUserRightsAny( 'viewmyprivateinfo' );

			$changedSince = $this->getParameter( 'changedsince' );
			$project = $this->getParameter( 'project' );
			$title = $this->getParameter( 'title' );
			$limit = $this->getParameter( 'limit' );
			$offset = $this->getParameter( 'continue' );

			$path = [ 'query', $this->getModuleName() ];
			$result = $this->getResult();
			$result->addIndexedTagName( $path, 'list' );
			$repository = $this->getReadingListRepository( $this->getUser() );

			$mode = null;
			$this->requireMaxOneParameter( $this->extractRequestParams(), 'title', 'changedsince' );
			if ( $project !== null && $title !== null ) {
				$mode = self::MODE_PAGE;
			} elseif ( $project !== null || $title !== null ) {
				$errorMessage = $this->msg( 'readinglists-apierror-project-title-param', static::$prefix );
				$this->dieWithError( $errorMessage, 'missingparam' );
			} elseif ( $changedSince !== null ) {
				$expiry = Utils::getDeletedExpiry();
				if ( $changedSince < $expiry ) {
					$errorMessage = $this->msg( 'readinglists-apierror-too-old', static::$prefix,
						wfTimestamp( TS_ISO_8601, $expiry ) );
					$this->dieWithError( $errorMessage );
				}
				$mode = self::MODE_CHANGES;
			} else {
				$mode = self::MODE_ALL;
			}

			if ( $mode === self::MODE_PAGE ) {
				$res = $repository->getListsByPage( $project, $title, $limit + 1, $offset );
			} elseif ( $mode === self::MODE_CHANGES ) {
				$res = $repository->getListsByDateUpdated( $changedSince, $limit + 1, $offset );
			} else {
				$res = $repository->getAllLists( $limit + 1, $offset );
			}
			$resultOffset = 0;
			foreach ( $res as $row ) {
				$isLastRow = ( $resultOffset === $res->numRows() - 1 );
				$this->addExtraData( $row, $repository, $mode );
				$item = $this->getResultItem( $row, $mode );
				$fits = $result->addValue( $path, null, $item );
				if ( !$fits || ++$resultOffset >= $limit && !$isLastRow ) {
					$this->setContinueEnumParameter( 'continue', $offset + $resultOffset );
					break;
				}
			}
		} catch ( ReadingListRepositoryException $e ) {
			$this->dieWithException( $e );
		}
	}

	/**
	 * @inheritdoc
	 * @return array
	 */
	protected function getAllowedParams() {
		return [
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
	 * @inheritdoc
	 * @return array
	 */
	public function getHelpUrls() {
		return [
			'https://www.mediawiki.org/wiki/Special:MyLanguage/Extension:ReadingLists#API',
		];
	}

	/**
	 * @inheritdoc
	 * @return array
	 */
	protected function getExamplesMessages() {
		$prefix = static::$prefix;
		return [
			'action=query&meta=readinglists'
				=> 'apihelp-query+readinglists-example-1',
			"action=query&meta=readinglists&${prefix}changedsince=2013-01-01T00:00:00Z"
				=> 'apihelp-query+readinglists-example-2',
			"action=query&meta=readinglists&${prefix}project=en.wikipedia.org&${prefix}title=Dog"
				=> 'apihelp-query+readinglists-example-3',
		];
	}

	/**
	 * @inheritdoc
	 * @return bool
	 */
	public function isInternal() {
		// ReadingLists API is still experimental
		return true;
	}

	/**
	 * @param ReadingListRow $row
	 * @param ReadingListRepository $repository
	 * @param string $mode One of the MODE_* constants.
	 * @return void
	 * @fixme If apps really need this, find a more performant way to get the data
	 */
	private function addExtraData( &$row, $repository, $mode ) {
		if ( $mode !== self::MODE_PAGE ) {
			$row->order = $repository->getListEntryOrder( $row->rl_id );
			if ( $row->rl_is_default ) {
				$row->list_order = $repository->getListOrder();
			}
		}
	}

	/**
	 * Transform a row into an API result item
	 * @param ReadingListRow $row
	 * @param string $mode One of the MODE_* constants.
	 * @return array
	 */
	private function getResultItem( $row, $mode ) {
		$item = [
			'id' => (int)$row->rl_id,
			'name' => (int)$row->rl_name,
			'default' => (bool)$row->rl_is_default,
			'description' => $row->rl_description,
			'color' => $row->rl_color,
			'image' => $row->rl_image,
			'icon' => $row->rl_icon,
			'created' => wfTimestamp( TS_ISO_8601, $row->rl_date_created ),
			'updated' => wfTimestamp( TS_ISO_8601, $row->rl_date_updated ),
		];
		if ( $mode === self::MODE_CHANGES ) {
			$item['deleted'] = (bool)$row->rl_deleted;
		}
		if ( isset( $row->order ) ) {
			$item['order'] = $row->order;
		}
		if ( isset( $row->list_order ) ) {
			$item['listOrder'] = $row->list_order;
		}
		return $item;
	}

}
