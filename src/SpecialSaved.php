<?php

namespace MediaWiki\Extension\ReadingLists;

use MediaWiki\Config\Config;
use MediaWiki\Exception\UserNotLoggedIn;
use MediaWiki\Html\Html;
use MediaWiki\MainConfigNames;
use MediaWiki\SpecialPage\UnlistedSpecialPage;

class SpecialSaved extends UnlistedSpecialPage {
	public function __construct(
		private readonly Config $config,
		private readonly ReadingListRepositoryFactory $readingListRepositoryFactory,
	) {
		parent::__construct( 'Saved' );
	}

	/**
	 * Disables default behavior since Special:Saved uses addHelpLink
	 *
	 * @inheritDoc
	 */
	protected function outputHeader( $summaryMessageKey = '' ) {
		// Note: intentionally does not call parent to avoid [[phab:T436103]].
	}

	/**
	 * Render Special:Saved.
	 *
	 * @param string|null $subPage Parameter submitted as subpage
	 * @throws UserNotLoggedIn
	 */
	public function execute( $subPage ) {
		$this->setHeaders();
		$this->outputHeader();
		// [[phab:T436103]]
		$this->addHelpLink( 'Help:Reading_lists' );

		$req = $this->getRequest();

		// If accessed via the script path (e.g. /w/index.php?title=Special:Saved&limport=...),
		// redirect to the canonical article path (e.g. /wiki/Special:Saved?limport=...).
		// This can happen when the ReadingLists alias redirects here with query params, since
		// Title::getLocalURL() with a non-empty query falls back to the script-path format.
		$scriptPath = $this->config->get( MainConfigNames::Script );
		$requestPath = null;
		try {
			$requestPath = parse_url( $req->getRequestURL(), PHP_URL_PATH );
		} catch ( \MWException ) {
			// FauxRequest in tests does not set a request URL; skip the script-path check.
		}
		if ( $requestPath === $scriptPath ) {
			$query = $req->getQueryValues();
			unset( $query['title'] );
			$url = $this->getPageTitle( $subPage ?: false )->getLocalURL();
			if ( $query ) {
				$url = wfAppendQuery( $url, $query );
			}
			$this->getOutput()->redirect( $url, '301' );
			return;
		}

		// Redirect legacy URLs that included a username as the first path segment.
		if ( $subPage !== '' && $subPage !== null ) {
			$parts = explode( '/', $subPage );
			if ( !ctype_digit( $parts[0] ) && $parts[0] !== 'collections' ) {
				$newSubPage = count( $parts ) > 1 ? implode( '/', array_slice( $parts, 1 ) ) : '';
				$this->getOutput()->redirect(
					$this->getPageTitle( $newSubPage ?: false )->getLocalURL(),
					'301'
				);
				return;
			}
		}

		if ( !$this->getUser()->isNamed() ) {
			$this->requireNamedUser();
			return;
		}

		$output = $this->getOutput();

		$exportFeature = $req->getText( 'limport' ) !== '' || $req->getText( 'lexport' ) !== '';
		$anonymizedPreviews = $this->config->get( 'ReadingListsAnonymizedPreviews' );
		$customListsEnabled = $this->config->get( 'ReadingListsCustomLists' );

		if ( $exportFeature && $anonymizedPreviews ) {
			$output->addHtmlClasses( 'reading-lists-anonymized-previews' );
		}

		// Special:Saved/42 is a subpage with a specific reading list (by list ID).
		// Special:Saved shows all items from all lists for the user.
		$parts = $subPage !== '' && $subPage !== null ? explode( '/', $subPage ) : [];
		$titleMsg = $this->msg( 'readinglists-special-subpage-title' );

		if ( $customListsEnabled && isset( $parts[0] ) && ctype_digit( $parts[0] ) ) {
			$listName = $this->getCustomListName( (int)$parts[0] );
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
