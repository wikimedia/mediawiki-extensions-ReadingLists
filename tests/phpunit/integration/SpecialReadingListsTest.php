<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Integration;

use MediaWiki\Extension\ReadingLists\Tests\ReadingListsTestHelperTrait;
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
	 * @return string Page title HTML set by the special page
	 */
	private function getSpecialPageTitle( string $subPage ): string {
		$page = $this->newSpecialPage();
		( new SpecialPageExecutor() )->executeSpecialPage(
			$page,
			$subPage,
			null,
			'en',
			$this->user
		);
		return $page->getOutput()->getPageTitle();
	}

	public function testPageTitleForUserSubpageWithoutCustomLists() {
		$this->overrideConfigValue( 'ReadingListsCustomLists', false );

		$title = $this->getSpecialPageTitle( $this->user->getName() );
		$this->assertStringContainsString( 'Saved', $title );
		$this->assertStringNotContainsString( 'Favorite Dogs', $title );
	}

	public function testPageTitleForCustomListWithFlagOff() {
		$this->overrideConfigValue( 'ReadingListsCustomLists', false );

		$title = $this->getSpecialPageTitle(
			$this->user->getName() . '/' . $this->customListId
		);
		$this->assertStringContainsString( 'Saved', $title );
		$this->assertStringNotContainsString( 'Favorite Dogs', $title );
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
		$this->assertStringNotContainsString( 'reading-lists-beta-tag', $title );
	}

	public function testPageTitleIncludesBetaChipWithFlagOff() {
		$this->overrideConfigValue( 'ReadingListsCustomLists', false );

		$title = $this->getSpecialPageTitle( $this->user->getName() );
		$this->assertStringContainsString( 'reading-lists-beta-tag', $title );
	}

	public function testPageTitleForDefaultListWithFlagOn() {
		$this->overrideConfigValue( 'ReadingListsCustomLists', true );

		$title = $this->getSpecialPageTitle(
			$this->user->getName() . '/' . $this->defaultListId
		);
		$this->assertStringContainsString( 'Saved', $title );
		$this->assertStringNotContainsString( 'Saved /', $title );
	}

	public function testPageTitleForUserSubpageWithFlagOn() {
		$this->overrideConfigValue( 'ReadingListsCustomLists', true );

		$title = $this->getSpecialPageTitle( $this->user->getName() );
		$this->assertStringContainsString( 'Saved', $title );
		$this->assertStringNotContainsString( 'Favorite Dogs', $title );
	}
}
