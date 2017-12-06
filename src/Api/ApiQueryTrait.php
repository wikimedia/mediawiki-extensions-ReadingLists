<?php

namespace MediaWiki\Extensions\ReadingLists\Api;

use ApiUsageException;
use MediaWiki\Extensions\ReadingLists\ReadingListRepository;

/**
 * Shared sorting / paging for the query APIs.
 */
trait ApiQueryTrait {

	// Mode constants, to support different sorting / paging / deleted item behavior for different
	// parameter combinations. For no particular reason, PHP does not allow constants in traits,
	// so we'll use statics instead.

	/**
	 * Return all lists, or all entries of the specified list(s).
	 * Intended for initial copy of data to a new device, or for devices which have information
	 * that's too outdated for normal sync. Might also be useful for devices with limited storage
	 * capacity, such as web clients.
	 */
	private static $MODE_ALL = 'all';

	/**
	 * Return lists/entries which have been changed (or deleted) recently.
	 * Intended for syncing updates to a device which has an older snapshot of the data.
	 * "Recently" is defined by the changedsince parameter.
	 */
	private static $MODE_CHANGES = 'changes';

	/**
	 * Return lists which include a given page.
	 * Intended for status indicators and such (e.g. showing a star on the current page if it's
	 * included in some list).
	 */
	private static $MODE_PAGE = 'page';

	/** @var string[] Map of sort keywords used by the API to sort keywords used by the repo. */
	private static $sortParamMap = [
		'name' => ReadingListRepository::SORT_BY_NAME,
		'updated' => ReadingListRepository::SORT_BY_UPDATED,
		'ascending' => ReadingListRepository::SORT_DIR_ASC,
		'descending' => ReadingListRepository::SORT_DIR_DESC,
	];

	/**
	 * Extract continuation data from item position and serialize it into a string.
	 * @param array $item Result item to continue from.
	 * @param string $mode One of the MODE_* constants.
	 * @param string $sort One of the SORT_BY_* constants.
	 * @return string
	 */
	private function encodeContinuationParameter( array $item, $mode, $sort ) {
		if ( $mode === self::$MODE_PAGE ) {
			return $item['id'];
		} elseif ( $sort === ReadingListRepository::SORT_BY_NAME ) {
			if ( self::$prefix === 'rl' ) {
				$name = $item['name'];
			} else {
				$name = $item['title'];
			}
			return $name . '|' . $item['id'];
		} else {
			return $item['updated'] . '|' . $item['id'];
		}
	}

	/**
	 * Recover continuation data after it has been roundtripped to the client.
	 * @param string|null $continue Continuation parameter returned by the client.
	 * @param string $mode One of the MODE_* constants.
	 * @param string $sort One of the SORT_BY_* constants.
	 * @return null|int|string[]
	 *   - null if there was no continuation parameter;
	 *   - [ rl(e)_name, rl(e)_id ] for MODE_ALL/MODE_CHANGES when sorting by name;
	 *   - [ rl(e)_date_updated, rl(e)_id ] for MODE_ALL/MODE_CHANGES when sorting by updated time;
	 *   - rle_id for MODE_PAGE.
	 * @throws ApiUsageException
	 */
	private function decodeContinuationParameter( $continue, $mode, $sort ) {
		if ( $continue === null ) {
			return null;
		}

		if ( $mode === self::$MODE_PAGE ) {
			$this->dieContinueUsageIf( $continue !== (string)(int)$continue );
			return (int)$continue;
		} else {
			// Continue token format is '<name|timestamp>|<id>'; name can contain '|'.
			$separatorPosition = strrpos( $continue, '|' );
			$this->dieContinueUsageIf( $separatorPosition === false );
			$continue = [
				substr( $continue, 0, $separatorPosition ),
				substr( $continue, $separatorPosition + 1 ),
			];
			$this->dieContinueUsageIf( $continue[1] !== (string)(int)$continue[1] );
			$continue[1] = (int)$continue[1];
			if ( $sort === ReadingListRepository::SORT_BY_UPDATED ) {
				$this->dieContinueUsageIf( wfTimestamp( TS_MW, $continue[0] ) === false );
			}
			return $continue;
		}
	}

	/**
	 * Get common sorting/paging related params for getAllowedParams().
	 * @return array
	 */
	private function getAllowedSortParams() {
		return [
			'sort' => [
				self::PARAM_TYPE => [ 'name', 'updated' ],
				self::PARAM_HELP_MSG_PER_VALUE => [],
			],
			'dir' => [
				self::PARAM_DFLT => 'ascending',
				self::PARAM_TYPE => [ 'ascending', 'descending' ],
			],
			'limit' => [
				self::PARAM_DFLT => 10,
				self::PARAM_TYPE => 'limit',
				self::PARAM_MIN => 1,
				// Temporarily limit paging sizes per T164990#3264314 / T168984#3659998
				self::PARAM_MAX => self::$prefix === 'rl' ? 10 : 100,
				self::PARAM_MAX2 => null,
			],
			'continue' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_DFLT => null,
				self::PARAM_HELP_MSG => 'api-help-param-continue',
			],
		];
	}

}
