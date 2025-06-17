<?php

namespace MediaWiki\Extension\ReadingLists;

use MediaWiki\MediaWikiServices;

/** @phpcs-require-sorted-array */
return [
	'ReverseInterwikiLookup' => static function ( MediaWikiServices $services ): ReverseInterwikiLookupInterface {
		$ownServer = $services->getMainConfig()->get( 'CanonicalServer' );
		$ownServerParts = wfParseUrl( $ownServer );
		$ownDomain = '';
		if ( !empty( $ownServerParts['host'] ) ) {
			$ownDomain = $ownServerParts['host'];
		}
		return new ReverseInterwikiLookup(
			$services->getInterwikiLookup(),
			$services->getLanguageNameUtils(),
			$ownDomain
		);
	},
];
