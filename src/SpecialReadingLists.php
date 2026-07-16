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

		$pageTitle = $titleMsg->escaped();
		if ( !$customListsEnabled ) {
			$chip = ( new Codex() )->infoChip()
				->setText( $this->msg( 'readinglists-beta-tag' )->text() )
				->setStatus( 'notice' )
				->setAttributes( [ 'class' => 'reading-lists-beta-tag' ] )
				->setIcon( 'cdx-icon--lab-flask' )
				->build()
				->getHtml();
			$pageTitle .= $chip;
		}

		$output->setPageTitle( $pageTitle );

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
		if ( $exportFeature ) {
			$output->addModuleStyles( [ 'ext.readingLists.special.importDialog.styles' ] );
		}
		$output->addModules( [ 'ext.readingLists.special' ] );
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
