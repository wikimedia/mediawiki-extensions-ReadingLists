<?php

namespace MediaWiki\Extension\ReadingLists;

use MediaWiki\Exception\UserNotLoggedIn;
use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\UnlistedSpecialPage;

class SpecialReadingLists extends UnlistedSpecialPage {
	/**
	 * Construct function
	 */
	public function __construct() {
		parent::__construct( 'ReadingLists' );
	}

	/**
	 * Render SpecialPage:ReadingLists
	 *
	 * @param string $subPage Parameter submitted as subpage
	 * @throws UserNotLoggedIn
	 */
	public function execute( $subPage ) {
		$this->setHeaders();
		$this->outputHeader();

		$req = $this->getRequest();
		$exportFeature = $req->getText( 'limport' ) !== '' || $req->getText( 'lexport' ) !== '';

		if ( !$this->getUser()->isNamed() ) {
			$this->requireNamedUser();
			return;
		}

		if ( ( $subPage === '' || $subPage === null ) && !$exportFeature ) {
			$redirectTitle = $this->getPageTitle( $this->getUser()->getName() );
			$this->getOutput()->redirect( $redirectTitle->getLocalURL() );
			return;
		}

		$output = $this->getOutput();
		$config = $this->getConfig();

		$anonymizedPreviews = $config->get( 'ReadingListsAnonymizedPreviews' );

		if ( $exportFeature && $anonymizedPreviews ) {
			$output->addHtmlClasses( 'reading-lists-anonymized-previews' );
		}

		// Special:ReadingLists/ExampleUser/1 is a subpage, with a specific reading list
		// Special:ReadingLists/ExampleUser shows all list items from all lists for the user.
		// Special:ReadingLists redirects to Special:ReadingLists/ExampleUser
		// if the request is not for viewing an exported list.
		$parts = $subPage ? explode( '/', $subPage ) : [];
		if ( count( $parts ) >= 1 ) {
			$output->setPageTitleMsg( $this->msg( 'readinglists-special-subpage-title' ) );
		} else {
			$output->setPageTitleMsg( $this->msg( 'readinglists-title' ) );
		}

		$output->addHTML( Html::errorBox(
			$this->msg( 'readinglists-error' )->parse(),
			'',
			'reading-lists__errorbox'
		) );

		$container = Html::element( 'div', [
			'class' => 'reading-lists-container'
		] );

		$output->addHTML( $container );
		$output->addModuleStyles( [ 'ext.readingLists.special.styles' ] );
		$output->addModules( [ 'ext.readingLists.special' ] );
	}

	/**
	 * @return string
	 */
	protected function getGroupName() {
		return 'pages';
	}
}
