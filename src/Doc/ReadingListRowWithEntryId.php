<?php
/**
 * @file
 * Documentation hack for plain objects returned by DB queries.
 * For the benefit of IDEs only, won't be used outside phpdoc.
 */

namespace MediaWiki\Extension\ReadingLists\Doc;

/**
 * Database row for reading_list joined with the entry id of a matching reading_list_entry row.
 */
trait ReadingListRowWithEntryId {

	use ReadingListRow;

	/** @var string Primary key of the matching reading_list_entry row. */
	public $rle_id;

}
