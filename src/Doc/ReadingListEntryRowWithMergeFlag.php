<?php
/**
 * @file
 * Documentation hack for plain objects returned by DB queries.
 * For the benefit of IDEs only, won't be used outside phpdoc.
 */

namespace MediaWiki\Extension\ReadingLists\Doc;

/**
 * A hacky way to add an extra flag telling the client that they asked for a duplicate list entry
 * to be created, and the existing entry is being returned instead.
 */
trait ReadingListEntryRowWithMergeFlag {

	use ReadingListEntryRow;

	/**
	 * True if this row is the result of an operation that requested a new row, but instead
	 * an existing row was returned.
	 * @var bool
	 */
	public $merged;

}
