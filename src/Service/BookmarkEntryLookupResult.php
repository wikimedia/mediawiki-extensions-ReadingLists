<?php

namespace MediaWiki\Extension\ReadingLists\Service;

/**
 * Saved-state information for a page in the bookmark UI.
 *
 * @internal
 */
final class BookmarkEntryLookupResult {

	public function __construct(
		private readonly bool $isSaved,
		private readonly bool $hasCustomListEntry
	) {
	}

	public function isSaved(): bool {
		return $this->isSaved;
	}

	public function hasCustomListEntry(): bool {
		return $this->hasCustomListEntry;
	}
}
