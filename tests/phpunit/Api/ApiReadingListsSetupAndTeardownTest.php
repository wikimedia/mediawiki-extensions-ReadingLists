<?php

namespace MediaWiki\Extensions\ReadingLists\Tests\Api;

use ApiTestCase;
use MediaWiki\Extensions\ReadingLists\HookHandler;
use MediaWiki\Extensions\ReadingLists\Tests\ReadingListsTestHelperTrait;

/**
 * @covers \MediaWiki\Extensions\ReadingLists\Api\ApiReadingListsSetup
 * @covers \MediaWiki\Extensions\ReadingLists\Api\ApiReadingListsTeardown
 * @covers \MediaWiki\Extensions\ReadingLists\Api\ApiReadingLists
 * @group medium
 * @group API
 * @group Database
 */
class ApiReadingListsSetupAndTeardownTest extends ApiTestCase {

	use ReadingListsTestHelperTrait;

	/** @var array */
	private $apiParams = [
		'action' => 'readinglists',
		'format' => 'json',
	];

	protected function setUp(): void {
		parent::setUp();
		$this->tablesUsed = array_merge( $this->tablesUsed, HookHandler::$testTables );
		$this->user = parent::getTestSysop()->getUser();
	}

	public function testSetup() {
		$this->apiParams['command'] = 'setup';
		$result = $this->doApiRequestWithToken( $this->apiParams, null, $this->user );
		$this->assertEquals( 'Success', $result[0]['setup']['result'] );
		$this->readingListsTeardown();
	}

	/**
	 * @depends testSetup
	 */
	public function testTeardown() {
		$this->readingListsSetup();
		$this->apiParams['command'] = 'teardown';
		$result = $this->doApiRequestWithToken( $this->apiParams, null, $this->user );
		$this->assertEquals( "Success", $result[0]['teardown']['result'] );
	}
}
