<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Api;

use ApiTestCase;
use MediaWiki\Extension\ReadingLists\HookHandler;
use MediaWiki\Extension\ReadingLists\Tests\ReadingListsTestHelperTrait;
use MediaWiki\User\User;

/**
 * @covers \MediaWiki\Extension\ReadingLists\Api\ApiReadingListsDeleteEntry
 * @covers \MediaWiki\Extension\ReadingLists\Api\ApiReadingLists
 * @group medium
 * @group API
 * @group Database
 */
class ApiReadingListsDeleteEntryTest extends ApiTestCase {

	use ReadingListsTestHelperTrait;

	/** @var array */
	private $apiParams = [
		'action'  => 'readinglists',
		'format'  => 'json',
		'command' => 'deleteentry',
	];

	/** @var User */
	private $user;

	protected function setUp(): void {
		parent::setUp();
		$this->tablesUsed = array_merge( $this->tablesUsed, HookHandler::$testTables );
		$this->user = parent::getTestSysop()->getUser();
		$this->readingListsSetup();
	}

	public function testDeleteEntry() {
		$this->addProjects( [ 'https://en.wikipedia.org' ] );
		$listIds = $this->addLists( $this->user->mId, [
			[
				'rl_is_default' => 1,
				'rl_name' => 'dogs',
				'rl_date_created' => wfTimestampNow(),
				'rl_date_updated' => wfTimestampNow(),
				'rl_deleted' => 0,
			]
		] );

		$entryIds = $this->addListEntries( $listIds[0], $this->user->mId, [
			[
				'rlp_project' => 'https://en.wikipedia.org',
				'rle_title' => 'Bar',
				'rle_date_created' => wfTimestampNow(),
				'rle_date_updated' => wfTimestampNow(),
				'rle_deleted' => 0,
			],
		] );

		$this->apiParams['entry'] = $entryIds[0];
		$result = $this->doApiRequestWithToken( $this->apiParams, null, $this->user );
		$this->assertEquals( "Success", $result[0]['deleteentry']['result'] );
	}

	// TODO: Create a test provider that pass the apiParams
	// also test project recognize

	public function testDeleteEntryBatch() {
		$this->addProjects( [ 'https://en.wikipedia.org' ] );
		$listIds = $this->addLists( $this->user->mId, [
			[
				'rl_is_default' => 1,
				'rl_name' => 'dogs',
				'rl_date_created' => wfTimestampNow(),
				'rl_date_updated' => wfTimestampNow(),
				'rl_deleted' => 0,
			],
		] );

		$entryIds = $this->addListEntries( $listIds[0], $this->user->mId, [
			[
				'rlp_project' => 'https://en.wikipedia.org',
				'rle_title' => 'Bar',
				'rle_date_created' => wfTimestampNow(),
				'rle_date_updated' => wfTimestampNow(),
				'rle_deleted' => 0,
			],
			[
				'rlp_project' => 'https://en.wikipedia.org',
				'rle_title' => 'Bar2',
				'rle_date_created' => wfTimestampNow(),
				'rle_date_updated' => wfTimestampNow(),
				'rle_deleted' => 0,
			],
		] );

		$this->apiParams['batch'] = json_encode( [
			(object)[ "entry" => $entryIds[0] ],
			(object)[ "entry" => $entryIds[1] ],
		] );

		$result = $this->doApiRequestWithToken( $this->apiParams, null, $this->user );
		$this->assertEquals( "Success", $result[0]['deleteentry']['result'] );
	}

	protected function tearDown(): void {
		parent::tearDown();
		$this->readingListsTeardown();
	}
}
