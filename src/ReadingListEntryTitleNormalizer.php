<?php

namespace MediaWiki\Extension\ReadingLists;

use MediaWiki\Title\Title;

class ReadingListEntryTitleNormalizer {

	/**
	 * Normalizes a title for storage.
	 *
	 * For entries from the current wiki, use Title-based normalization so local
	 * wiki rules such as first-letter capitalization and namespace aliases are
	 * applied.
	 *
	 * For cross-wiki entries, only normalize spaces to underscores,
	 * since other wikis may have different title rules that are not
	 * available on the current wiki where the reading list entry
	 * save API call is made.
	 *
	 * @param string $project The project identifier of the reading list entry.
	 * @param string $title The title to normalize.
	 * @param string $localProject The canonical project identifier of the current wiki.
	 * @return string The normalized title.
	 */
	public function normalizeForStorage(
		string $project,
		string $title,
		string $localProject
	): string {
		if ( LocalProjectHelper::isLocalProject( $project, $localProject ) ) {
			$normalizedTitle = Title::newFromText( $title );
			if ( $normalizedTitle ) {
				return $normalizedTitle->getPrefixedDBkey();
			}
		}

		return strtr( $title, ' ', '_' );
	}
}
