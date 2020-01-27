<?php

namespace MediaWiki\Extensions\ReadingLists\Tests\Api;

use ApiTestCase;
use ApiUsageException;
use MediaWiki\Extensions\ReadingLists\HookHandler;
use MediaWiki\Extensions\ReadingLists\Tests\ReadingListsTestHelperTrait;

/**
 * @covers \MediaWiki\Extensions\ReadingLists\Api\ApiReadingListsDelete
 * @covers \MediaWiki\Extensions\ReadingLists\Api\ApiReadingLists
 * @group medium
 * @group API
 * @group Database
 */
class ApiReadingListsDeleteTest extends ApiTestCase {

	use ReadingListsTestHelperTrait;

	private $apiParams = [
		'action'  => 'readinglists',
		'format'  => 'json',
		'command' => 'delete',
	];

	private $user;

	protected function setUp() : void {
		parent::setUp();
		$this->tablesUsed = array_merge( $this->tablesUsed, HookHandler::$testTables );
		$this->user = parent::getTestSysop()->getUser();
		$this->readingListsSetup();
	}

	public function testDelete() {
		$listIds = $this->addLists( $this->user->mId, [
			[
				'rl_is_default' => 0,
				'rl_name' => 'dogs',
				'rl_date_created' => wfTimestampNow(),
				'rl_date_updated' => wfTimestampNow(),
				'rl_deleted' => 0,
			],
		] );
		$this->apiParams['list'] = $listIds[0];

		$result = $this->doApiRequestWithToken( $this->apiParams, null, $this->user );
		$this->assertEquals( "Success", $result[0]['delete']['result'] );
	}

	public function testDeleteBatch() {
		$listIds = $this->addLists( $this->user->mId, [
			[
				'rl_is_default' => 0,
				'rl_name' => 'dogs',
				'rl_date_created' => wfTimestampNow(),
				'rl_date_updated' => wfTimestampNow(),
				'rl_deleted' => 0,
			],
			[
				'rl_is_default' => 0,
				'rl_name' => 'cats',
				'rl_date_created' => wfTimestampNow(),
				'rl_date_updated' => wfTimestampNow(),
				'rl_deleted' => 0,
			],
		] );
		$this->apiParams['batch'] = json_encode( [
			(object)[ "list" => $listIds[0] ],
			(object)[ "list" => $listIds[1] ],
		] );
		$result = $this->doApiRequestWithToken( $this->apiParams, null, $this->user );
		$this->assertEquals( "Success", $result[0]['delete']['result'] );
	}

	public function testDeleteDefault() {
		$listIds = $this->addLists( $this->user->mId, [
			[
				'rl_is_default' => 1,
				'rl_name' => 'dogs',
				'rl_date_created' => wfTimestampNow(),
				'rl_date_updated' => wfTimestampNow(),
				'rl_deleted' => 0,
			],
		] );
		$this->apiParams['list'] = $listIds[0];

		$this->expectException( ApiUsageException::class );
		$this->expectExceptionMessage( 'The default list cannot be deleted' );
		$result = $this->doApiRequestWithToken( $this->apiParams, null, $this->user );
	}

	protected function tearDown() : void {
		parent::tearDown();
		$this->readingListsTeardown();
	}
}
