<?php

namespace MediaWiki\Extension\ReadingLists;

use Html;
use PermissionsError;
use SpecialPage;
use UnlistedSpecialPage;
use User;

class SpecialReadingLists extends UnlistedSpecialPage {
	/**
	 * Construct function
	 */
	public function __construct() {
		parent::__construct( 'ReadingLists' );
	}

	/**
	 * Render readinglist(s) app shell
	 */
	private function executeReadingList() {
		$out = $this->getOutput();
		$out->addModuleStyles( [ 'special.readinglist.styles' ] );
		$out->addModules( [ 'special.readinglist.scripts' ] );
		$out->setPageTitle( $this->msg( 'readinglists-special-title' ) );
		$html = Html::errorBox(
			$this->msg( 'readinglists-error' )->text(),
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
				$canDisplayPrivateLists = $privateEnabled && $owner &&
					$owner->getId() === $user->getId();
				if ( $exportFeature || $canDisplayPrivateLists ) {
					$this->executeReadingList();
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
	 * @inheritDoc
	 */
	public function getAssociatedNavigationLinks(): array {
		return [
			self::getTitleFor( $this->getName() )->getPrefixedText(),
		];
	}

	/**
	 * @return string
	 */
	protected function getGroupName() {
		return 'pages';
	}
}
