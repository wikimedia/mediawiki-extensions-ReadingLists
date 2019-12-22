<?php

namespace MediaWiki\Extensions\ReadingLists;

use MediaWiki\MediaWikiServices;

return [
	'ReverseInterwikiLookup' => function ( MediaWikiServices $services ) {
		$interwikiLookup = $services->getInterwikiLookup();
		$ownServer = $services->getMainConfig()->get( 'CanonicalServer' );
		$ownServerParts = wfParseUrl( $ownServer );
		$ownDomain = '';
		if ( !empty( $ownServerParts['host'] ) ) {
			$ownDomain = $ownServerParts['host'];
		}
		return new ReverseInterwikiLookup( $interwikiLookup, $ownDomain );
	},
];
