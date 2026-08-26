<?php

namespace MediaWiki\Extension\ReadingLists;

use MediaWiki\Config\Config;
use MediaWiki\Exception\UserNotLoggedIn;
use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\UnlistedSpecialPage;

class SpecialReadingLists extends UnlistedSpecialPage {
	/**
	 * Construct function
	 */
	private readonly Config $config;
	private readonly ReadingListRepositoryFactory $readingListRepositoryFactory;

	public function __construct(
		Config $config,
		ReadingListRepositoryFactory $readingListRepositoryFactory
	) {
		parent::__construct( 'ReadingLists' );
		$this->config = $config;
		$this->readingListRepositoryFactory = $readingListRepositoryFactory;
	}

	/**
	 * disables default behaviour since ReadingLists uses addHelpLink
	 * @inheritDoc
	 */
	protected function outputHeader( $summaryMessageKey = '' ) {
		// Note: intentionally does not call parent to avoid [[phab:T436103]]
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
		// [[phab:T436103]]
		$this->addHelpLink( 'Help:Reading_lists' );

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
		$customListsEnabled = $this->config->get( 'ReadingListsCustomLists' );

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

		if ( $customListsEnabled && isset( $parts[1] ) && ctype_digit( $parts[1] ) ) {
			$listName = $this->getCustomListName( (int)$parts[1] );
			if ( $listName !== null ) {
				$titleMsg = $this->msg( 'readinglists-special-custom-list-title' )
					->plaintextParams( $listName );
			}
		}

		if ( $exportFeature ) {
			$output->setPageTitle( $titleMsg->escaped() );
		} else {
			$output->setPageTitle(
				Html::rawElement(
					'span',
					[ 'class' => 'reading-lists-title-text' ],
					$titleMsg->escaped()
				) . $this->getPrivacyIndicatorHtml()
			);
			$output->setHTMLTitle(
				$this->msg( 'pagetitle' )
					->plaintextParams( $titleMsg->text() )
					->inContentLanguage()
			);
		}

		$output->addHTML( Html::errorBox(
			$this->msg( 'readinglists-error' )->parse(),
			'',
			'reading-lists__errorbox'
		) );

		// Render the loading indicator server-side, using Codex's CdxProgressBar markup and
		// CSS-only styles, so it appears as soon as the page's styles are loaded, rather than
		// waiting for the Vue app's JS bundle to be fetched, parsed and mounted.
		$container = Html::rawElement( 'div', [
			'class' => 'reading-lists-container'
		], Html::rawElement( 'div', [
			'class' => 'cdx-progress-bar cdx-progress-bar--block cdx-progress-bar--enabled',
			'role' => 'progressbar',
			'aria-label' => $this->msg( 'readinglists-loading' )->text()
		], Html::element( 'div', [ 'class' => 'cdx-progress-bar__bar' ] ) ) );

		$output->addHTML( $container );
		$output->addModuleStyles( [ 'ext.readingLists.special.styles' ] );
		if ( $exportFeature ) {
			$output->addModuleStyles( [ 'ext.readingLists.special.importDialog.styles' ] );
		}
		$output->addModules( [ 'ext.readingLists.special' ] );
	}

	/**
	 * @return string
	 */
	private function getPrivacyIndicatorHtml(): string {
		$tooltipId = 'reading-lists-privacy-tooltip';

		return Html::rawElement(
			'span',
			[ 'class' => 'reading-lists-privacy-indicator' ],
			Html::element( 'span', [
				'class' => 'reading-lists-privacy-indicator__trigger',
				'tabindex' => 0,
				'role' => 'img',
				'aria-label' => $this->msg( 'readinglists-privacy-indicator-label' )->text(),
				'aria-describedby' => $tooltipId,
			] ) . Html::element(
				'span',
				[
					'id' => $tooltipId,
					'class' => 'cdx-tooltip reading-lists-privacy-indicator__tooltip',
					'role' => 'tooltip',
				],
				$this->msg( 'readinglists-privacy-tooltip' )->text()
			)
		);
	}

	/**
	 * Get the name of a non-default reading list for the current user.
	 *
	 * @param int $listId
	 * @return string|null List name, or null if the list is missing, deleted,
	 *  the default list, or not owned
	 */
	private function getCustomListName( int $listId ): ?string {
		try {
			$list = $this->readingListRepositoryFactory
				->getInstanceForUser( $this->getUser() )
				->selectValidList( $listId );
		} catch ( ReadingListRepositoryException ) {
			return null;
		}

		if ( $list->rl_is_default ) {
			return null;
		}

		return $list->rl_name;
	}

	/**
	 * @return string
	 */
	protected function getGroupName() {
		return 'pages';
	}
}
