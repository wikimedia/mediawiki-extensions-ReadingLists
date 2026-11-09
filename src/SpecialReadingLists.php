<?php

namespace MediaWiki\Extension\ReadingLists;

use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\SpecialPage\UnlistedSpecialPage;

class SpecialReadingLists extends UnlistedSpecialPage {
	public function __construct() {
		parent::__construct( 'ReadingLists' );
	}

	/**
	 * Redirect to Special:Saved, stripping the username from the URL path.
	 *
	 * Old URL patterns vs new:
	 *   Special:ReadingLists/{user}/{listId}/{title?} -> Special:Saved/{listId}/{title?}
	 *   Special:ReadingLists/{user}/collections       -> Special:Saved/collections
	 *   Special:ReadingLists/{user}                   -> Special:Saved
	 *   Special:ReadingLists                          -> Special:Saved
	 *
	 * The limport and lexport query parameters are forwarded unchanged.
	 *
	 * @param string|null $subPage
	 */
	public function execute( $subPage ) {
		$this->setHeaders();

		$newSubPage = '';
		if ( $subPage !== '' && $subPage !== null ) {
			$parts = explode( '/', $subPage );
			if ( count( $parts ) > 1 ) {
				// Strip the first segment (username) and keep the rest.
				$newSubPage = implode( '/', array_slice( $parts, 1 ) );
			}
			// count($parts) === 1 means only a username — redirect to Special:Saved.
		}

		$request = $this->getRequest();
		$query = array_filter( [
			'limport' => $request->getRawVal( 'limport' ),
			'lexport' => $request->getRawVal( 'lexport' ),
		] );

		$savedTitle = SpecialPage::getTitleFor( 'Saved', $newSubPage ?: false );
		$this->getOutput()->redirect( $savedTitle->getLocalURL( $query ), '301' );
	}

	/**
	 * @return string
	 */
	protected function getGroupName() {
		return 'pages';
	}
}
