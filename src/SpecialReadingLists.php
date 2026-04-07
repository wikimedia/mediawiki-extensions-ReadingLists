<?php

namespace MediaWiki\Extension\ReadingLists;

use MediaWiki\Config\Config;
use MediaWiki\Exception\UserNotLoggedIn;
use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\UnlistedSpecialPage;
use Wikimedia\Codex\Utility\Codex;

class SpecialReadingLists extends UnlistedSpecialPage {
	/**
	 * Construct function
	 */
	private readonly Config $config;

	public function __construct( Config $config ) {
		parent::__construct( 'ReadingLists' );
		$this->config = $config;
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

		$anonymizedPreviews = $this->config->get( 'ReadingListsAnonymizedPreviews' );

		if ( $exportFeature && $anonymizedPreviews ) {
			$output->addHtmlClasses( 'reading-lists-anonymized-previews' );
		}

		// Special:ReadingLists/ExampleUser/1 is a subpage, with a specific reading list
		// Special:ReadingLists/ExampleUser shows all list items from all lists for the user.
		// Special:ReadingLists redirects to Special:ReadingLists/ExampleUser
		// if the request is not for viewing an exported list.
		$parts = $subPage ? explode( '/', $subPage ) : [];
		$titleMsg = count( $parts ) >= 1
			? $this->msg( 'readinglists-special-subpage-title' )
			: $this->msg( 'readinglists-title' );

		$chip = ( new Codex() )->infoChip()
			->setText( $this->msg( 'readinglists-beta-tag' )->text() )
			->setStatus( 'notice' )
			->setAttributes( [ 'class' => 'reading-lists-beta-tag' ] )
			->setIcon( 'cdx-icon--lab-flask' )
			->build()
			->getHtml();

		$output->setPageTitle( $titleMsg->escaped() . $chip );

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
