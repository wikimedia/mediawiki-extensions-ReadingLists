<?php

namespace MediaWiki\Extension\ReadingLists;

use MediaWiki\Html\Html;
use MediaWiki\Message\Message;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\SpecialPage\UnlistedSpecialPage;
use MediaWiki\User\User;
use PermissionsError;

class SpecialReadingLists extends UnlistedSpecialPage {
	/**
	 * Construct function
	 */
	public function __construct() {
		parent::__construct( 'ReadingLists' );
	}

	/**
	 * Render readinglist(s) app shell
	 *
	 * @param Message $pageTitle
	 */
	private function executeReadingList( Message $pageTitle ) {
		$out = $this->getOutput();
		$out->addModuleStyles( [ 'special.readinglist.styles' ] );
		$out->addModules( [ 'special.readinglist.scripts' ] );
		$out->setPageTitleMsg( $pageTitle );
		$html = Html::errorBox(
			$this->msg( 'readinglists-error' )->parse(),
			'',
			'reading-list__errorbox'
		);
		$html .= Html::element( 'div', [ 'id' => 'reading-list-container' ],
			$this->msg( 'readinglists-loading' )->text()
		);
		$out->addHTML( $html );
	}

	/**
	 * Render Special Page ReadingLists
	 * @param string $par Parameter submitted as subpage
	 */
	public function execute( $par = '' ) {
		$out = $this->getOutput();
		$config = $out->getConfig();
		// If the feature isn't ready, redirect to Special:SpecialPages
		$params = $par ? explode( '/', $par ) : [];
		$listOwner = $params[0] ?? null;
		$listId = $params[1] ?? null;
		$req = $this->getRequest();
		$exportFeature = $req->getText( 'limport' ) !== '' || $req->getText( 'lexport' ) !== '';
		$user = $this->getUser();
		$this->setHeaders();
		$this->outputHeader();

		if ( !$user->isNamed() && !$exportFeature ) {
			$this->requireNamedUser( 'reading-list-purpose' );
		} else {
			if ( $listOwner || $exportFeature ) {
				$owner = !$listOwner ? null : User::newFromName( $listOwner );
				$privateEnabled = $config->get( 'ReadingListsWebAuthenticatedPreviews' );
				$isWatchlist = $listId === '-10';
				$canDisplayPrivateLists = $privateEnabled && $owner &&
					$owner->getId() === $user->getId();
				$pageTitle = $this->msg( 'readinglists-special-title' );
				if ( !$privateEnabled ) {
					$out->addHtmlClasses( 'mw-special-readinglist-watchlist-only' );
				}
				if ( $exportFeature ) {
					$pageTitle = $this->msg( 'readinglists-special-title-imported' );
					$out->addHtmlClasses( 'mw-special-readinglist-export-only' );
				}
				if ( $exportFeature || $isWatchlist || $privateEnabled ) {
					$this->executeReadingList( $pageTitle );
				} else {
					throw new PermissionsError( 'action-readinglist-private' );
				}
			} else {
				$out = $this->getOutput();
				$out->redirect( SpecialPage::getTitleFor( 'ReadingLists',
					$user->getName() )->getLocalURL() );
			}
		}
	}

	/**
	 * @return string
	 */
	protected function getGroupName() {
		return 'pages';
	}
}
