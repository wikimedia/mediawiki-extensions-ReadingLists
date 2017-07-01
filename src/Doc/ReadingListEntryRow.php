<?php
/**
 * @file
 * Documentation hack for plain objects returned by DB queries.
 * For the benefit of IDEs only, won't be used outside phpdoc.
 */

namespace MediaWiki\Extensions\ReadingLists\Doc;

/**
 * Database row for reading_list_entry.
 * Represents a single wiki page.
 */
trait ReadingListEntryRow {

	/** @var string Primary key. */
	public $rle_id;

	/** @var string Reference to reading_list.rl_id. */
	public $rle_rl_id;

	/** @var string Central ID of user. */
	public $rle_user_id;

	/** @var string Wiki project domain. */
	public $rle_project;

	/**
	 * Page title.
	 * We can't easily use page ids due to the cross-wiki nature of the project;
	 * also, page ids don't age well when content is deleted/moved.
	 * @var string
	 */
	public $rle_title;

	/** @var string Creation timestamp. */
	public $rle_date_created;

	/** @var string Last modification timestamp. */
	public $rle_date_updated;

	/**
	 * Deleted flag.
	 * Entries will be hard-deleted eventually but kept around for a while for sync.
	 * @var string
	 */
	public $rle_deleted;

}
