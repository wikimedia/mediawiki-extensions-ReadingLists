<?php

namespace MediaWiki\Extensions\ReadingLists\Api;

use ApiQueryBase;
use ApiResult;
use MediaWiki\Extensions\ReadingLists\Doc\ReadingListRow;
use MediaWiki\Extensions\ReadingLists\ReadingListRepositoryException;

/**
 * API meta module for getting list order.
 */
class ApiQueryReadingListOrder extends ApiQueryBase {

	use ApiTrait;

	/** @var string API module prefix */
	private static $prefix = 'rlo';

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

			$listorder = $this->getParameter( 'listorder' );
			$lists = $this->getParameter( 'lists' ) ?: [];
			$this->requireAtLeastOneParameter( $this->extractRequestParams(), 'listorder', 'lists' );

			$path = [ 'query', $this->getModuleName() ];
			$result = $this->getResult();
			$result->addIndexedTagName( $path, 'order' );

			$repository = $this->getReadingListRepository( $this->getUser() );
			if ( $listorder ) {
				$order = $repository->getListOrder();
				ApiResult::setIndexedTagName( $order, 'list' );
				$result->addValue( $path, null, [ 'type' => 'lists', 'order' => $order ] );
			}
			foreach ( $lists as $list ) {
				$order = $repository->getListEntryOrder( $list );
				ApiResult::setIndexedTagName( $order, 'entry' );
				$result->addValue( $path, null, [ 'type' => 'entries', 'list' => $list, 'order' => $order ] );
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
			'listorder' => [
				self::PARAM_TYPE => 'boolean',
			],
			'lists' => [
				self::PARAM_TYPE => 'integer',
				self::PARAM_ISMULTI => true,
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
			"action=query&meta=readinglistorder&${prefix}listorder=1"
				=> 'apihelp-query+readinglistorder-example-1',
			"action=query&meta=readinglistorder&${prefix}lists=1|2"
				=> 'apihelp-query+readinglistorder-example-2',
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

}
