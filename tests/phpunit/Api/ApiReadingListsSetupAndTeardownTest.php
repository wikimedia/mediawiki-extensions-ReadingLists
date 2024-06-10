<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Api;

use MediaWiki\Extension\ReadingLists\Tests\ReadingListsTestHelperTrait;
use MediaWiki\Tests\Api\ApiTestCase;
use MediaWiki\User\User;

/**
 * @covers \MediaWiki\Extension\ReadingLists\Api\ApiReadingListsSetup
 * @covers \MediaWiki\Extension\ReadingLists\Api\ApiReadingListsTeardown
 * @covers \MediaWiki\Extension\ReadingLists\Api\ApiReadingLists
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

	/** @var User */
	private $user;

	protected function setUp(): void {
		parent::setUp();
		$this->user = parent::getTestSysop()->getUser();
	}

	public function testSetup() {
		$this->addProjects( [ 'test' ] );

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
