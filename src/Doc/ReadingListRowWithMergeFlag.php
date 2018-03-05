<?php
/**
 * @file
 * Documentation hack for plain objects returned by DB queries.
 * For the benefit of IDEs only, won't be used outside phpdoc.
 */

namespace MediaWiki\Extensions\ReadingLists\Doc;

/**
 * A hacky way to add an extra flag telling the client that they asked for a duplicate list
 * to be created, and the existing list is being returned instead (with the description
 * updated if necessary).
 */
trait ReadingListRowWithMergeFlag {

	use ReadingListRow;

	/**
	 * True if this row is the result of an operation that requested a new row, but instead
	 * an existing row was returned.
	 * @var bool
	 */
	public $merged;

}
