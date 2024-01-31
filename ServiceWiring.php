<?php

namespace MediaWiki\Extension\ReadingLists;

use MediaWiki\MediaWikiServices;

return [
	'ReverseInterwikiLookup' => static function ( MediaWikiServices $services ): ReverseInterwikiLookupInterface {
		$interwikiLookup = $services->getInterwikiLookup();
		$ownServer = $services->getMainConfig()->get( 'CanonicalServer' );
		$ownServerParts = wfParseUrl( $ownServer );
		$ownDomain = '';
		if ( !empty( $ownServerParts['host'] ) ) {
			$ownDomain = $ownServerParts['host'];
		}
		return new ReverseInterwikiLookup(
			$interwikiLookup,
			$services->getLanguageNameUtils(),
			$ownDomain
		);
	},
];
