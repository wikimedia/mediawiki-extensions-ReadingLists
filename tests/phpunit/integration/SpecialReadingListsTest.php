<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Integration;

use MediaWiki\Extension\ReadingLists\Tests\ReadingListsTestHelperTrait;
use MediaWiki\Output\OutputPage;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Request\WebRequest;
use MediaWiki\Tests\Specials\SpecialPageExecutor;
use MediaWiki\Tests\Specials\SpecialPageTestBase;
use MediaWiki\User\User;

/**
 * @group Database
 * @covers \MediaWiki\Extension\ReadingLists\SpecialReadingLists
 */
class SpecialReadingListsTest extends SpecialPageTestBase {
	use ReadingListsTestHelperTrait;

	private User $user;
	private int $defaultListId;
	private int $customListId;

	protected function setUp(): void {
		parent::setUp();

		$this->setUserLang( 'en' );
		$this->overrideConfigValue( 'CentralIdLookupProviders', [
			'local' => [
				'class' => 'MediaWiki\\User\\CentralId\\LocalIdLookup',
				'services' => [
					'MainConfig',
					'DBLoadBalancerFactory',
					'HideUserUtils',
				],
			],
		] );
		$this->overrideConfigValue( 'CentralIdLookupProvider', 'local' );

		$this->user = $this->getTestUser()->getUser();
		$userId = $this->user->getId();

		[ $this->defaultListId, $this->customListId ] = $this->addLists( $userId, [
			[
				'rl_is_default' => 1,
				'rl_name' => 'default',
				'rl_description' => '',
				'rl_date_created' => wfTimestampNow(),
				'rl_date_updated' => wfTimestampNow(),
				'rl_deleted' => 0,
			],
			[
				'rl_is_default' => 0,
				'rl_name' => 'Favorite Dogs',
				'rl_description' => 'A list of dogs',
				'rl_date_created' => wfTimestampNow(),
				'rl_date_updated' => wfTimestampNow(),
				'rl_deleted' => 0,
			],
		] );
	}

	protected function newSpecialPage() {
		return $this->getServiceContainer()->getSpecialPageFactory()->getPage( 'ReadingLists' );
	}

	/**
	 * @param string $subPage
	 * @param WebRequest|null $request
	 * @return OutputPage
	 */
	private function getSpecialPageOutput(
		string $subPage,
		?WebRequest $request = null
	): OutputPage {
		$page = $this->newSpecialPage();
		( new SpecialPageExecutor() )->executeSpecialPage(
			$page,
			$subPage,
			$request,
			'en',
			$this->user
		);
		return $page->getOutput();
	}

	/**
	 * @param string $subPage
	 * @return string Page title HTML set by the special page
	 */
	private function getSpecialPageTitle( string $subPage ): string {
		return $this->getSpecialPageOutput( $subPage )->getPageTitle();
	}

	public function testPageTitleForUserSubpageWithoutCustomLists() {
		$this->overrideConfigValue( 'ReadingListsCustomLists', false );

		$output = $this->getSpecialPageOutput( $this->user->getName() );
		$title = $output->getPageTitle();
		$this->assertStringContainsString( 'Saved', $title );
		$this->assertStringNotContainsString( 'Favorite Dogs', $title );
		$this->assertStringContainsString( 'reading-lists-privacy-indicator__trigger', $title );
		$this->assertStringContainsString( 'cdx-tooltip', $title );
		$this->assertStringContainsString(
			'Your saved items and collections are private and visible only to you',
			$title
		);
		$this->assertStringNotContainsString(
			'Your saved items and collections are private and visible only to you',
			$output->getHTMLTitle()
		);
	}

	public function testPageTitleForCustomListWithFlagOff() {
		$this->overrideConfigValue( 'ReadingListsCustomLists', false );

		$title = $this->getSpecialPageTitle(
			$this->user->getName() . '/' . $this->customListId
		);
		$this->assertStringContainsString( 'Saved', $title );
		$this->assertStringNotContainsString( 'Favorite Dogs', $title );
		$this->assertStringContainsString( 'reading-lists-privacy-indicator', $title );
	}

	public function testPageTitleForCustomListWithFlagOn() {
		$this->overrideConfigValue( 'ReadingListsCustomLists', true );

		$page = $this->newSpecialPage();
		( new SpecialPageExecutor() )->executeSpecialPage(
			$page,
			$this->user->getName() . '/' . $this->customListId,
			null,
			'en',
			$this->user
		);

		$title = $page->getOutput()->getPageTitle();
		$this->assertStringContainsString( 'Saved / Favorite Dogs', $title );
		$this->assertStringContainsString( 'reading-lists-privacy-indicator', $title );
	}

	public function testPageTitleForDefaultListWithFlagOn() {
		$this->overrideConfigValue( 'ReadingListsCustomLists', true );

		$title = $this->getSpecialPageTitle(
			$this->user->getName() . '/' . $this->defaultListId
		);
		$this->assertStringContainsString( 'Saved', $title );
		$this->assertStringNotContainsString( 'Saved /', $title );
		$this->assertStringContainsString( 'reading-lists-privacy-indicator', $title );
	}

	public function testPageTitleForUserSubpageWithFlagOn() {
		$this->overrideConfigValue( 'ReadingListsCustomLists', true );

		$title = $this->getSpecialPageTitle( $this->user->getName() );
		$this->assertStringContainsString( 'Saved', $title );
		$this->assertStringNotContainsString( 'Favorite Dogs', $title );
		$this->assertStringContainsString( 'reading-lists-privacy-indicator', $title );
	}

	public function testPrivacyIndicatorIsNotRenderedForImportOrExport() {
		foreach ( [ 'limport', 'lexport' ] as $parameter ) {
			$output = $this->getSpecialPageOutput(
				'',
				new FauxRequest( [ $parameter => 'serialized-list' ] )
			);
			$this->assertStringNotContainsString(
				'reading-lists-privacy-indicator',
				$output->getPageTitle()
			);
		}
	}
}
