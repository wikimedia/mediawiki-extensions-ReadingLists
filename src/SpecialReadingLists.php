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

		if ( !$this->getUser()->isNamed() ) {
			$this->requireNamedUser();
			return;
		}

		$output = $this->getOutput();
		$output->setPageTitleMsg( $this->msg( 'readinglists-title' ) );
		$output->addHTML( Html::errorBox(
			$this->msg( 'readinglists-error' )->parse(),
			'',
			'reading-list__errorbox'
		) );
		$output->addHTML( '<div class="readinglists-container"></div>' );
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
