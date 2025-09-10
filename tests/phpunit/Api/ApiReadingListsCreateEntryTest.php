<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Api;

use MediaWiki\Api\ApiUsageException;
use MediaWiki\Extension\ReadingLists\Tests\ReadingListsTestHelperTrait;
use MediaWiki\Tests\Api\ApiTestCase;
use MediaWiki\User\User;

/**
 * @covers \MediaWiki\Extension\ReadingLists\Api\ApiReadingListsCreateEntry
 * @covers \MediaWiki\Extension\ReadingLists\Api\ApiReadingLists
 * @group medium
 * @group API
 * @group Database
 */
class ApiReadingListsCreateEntryTest extends ApiTestCase {

	use ReadingListsTestHelperTrait;

	/** @var array */
	private $apiParams = [
		'action'  => 'readinglists',
		'format'  => 'json',
		'command' => 'createentry',
	];

	/** @var User */
	private $user;

	protected function setUp(): void {
		parent::setUp();
		$this->user = parent::getTestSysop()->getUser();
		$this->readingListsSetup();
	}

	/**
	 * @dataProvider createEntryProvider
	 */
	public function testCreateEntry( $projects, $apiParams, $expected ) {
		$this->addProjects( $projects );
		$listIds = $this->addLists( $this->user->mId, [
			[
				'rl_is_default' => 1,
				'rl_name' => 'dogs',
				'rl_date_created' => wfTimestampNow(),
				'rl_date_updated' => wfTimestampNow(),
				'rl_deleted' => 0,
			]
		] );

		$this->apiParams['list'] = $listIds[0];
		$this->apiParams['project'] = $apiParams['project'];
		$this->apiParams['title'] = $apiParams['title'];
		$result = $this->doApiRequestWithToken( $this->apiParams, null, $this->user );
		$this->assertEquals( $expected, $result[0]['createentry']['result'] );
	}

	public static function createEntryProvider() {
		return [
			[ [ 'https://en.wikipedia.org' ],
				[ 'project' => 'https://en.wikipedia.org', 'title' => 'Dog' ],
				'Success',
			],
		];
	}

	/**
	 * @dataProvider createEntryBatchProvider
	 */
	public function testCreateEntryBatch( $projects, $apiParams, $expected ) {
		$this->addProjects( $projects );
		$listIds = $this->addLists( $this->user->mId, [
			[
				'rl_is_default' => 1,
				'rl_name' => 'animals',
				'rl_date_created' => wfTimestampNow(),
				'rl_date_updated' => wfTimestampNow(),
				'rl_deleted' => 0,
			]
		] );

		$this->apiParams['list'] = $listIds[0];
		$this->apiParams['batch'] = json_encode( [
			(object)[ "project" => $apiParams[0]['project'], "title" => $apiParams[0]['title'] ],
			(object)[ "project" => $apiParams[1]['project'], "title" => $apiParams[1]['title'] ],
		] );

		$result = $this->doApiRequestWithToken( $this->apiParams, null, $this->user );
		$this->assertEquals( $expected, $result[0]['createentry']['result'] );
	}

	public static function createEntryBatchProvider() {
		return [
			[ [ 'https://en.wikipedia.org' ],
				[ [ 'project' => 'https://en.wikipedia.org', 'title' => 'Dog' ],
					[ 'project' => 'https://en.wikipedia.org', 'title' => 'Cat' ],
				],
				'Success',
			],
			[ [ 'https://en.wikipedia.org', 'https://pt.wikipedia.org' ],
				[ [ 'project' => 'https://en.wikipedia.org', 'title' => 'Dog' ],
					[ 'project' => 'https://pt.wikipedia.org', 'title' => 'Gato' ],
				],
				'Success',
			],
		];
	}

	/**
	 * @dataProvider createEntryUnrecognizedProjectProvider
	 */
	public function testCreateEntryUnrecognizedProject( $projects, $apiParams, $expected ) {
		$this->addProjects( $projects );
		$listIds = $this->addLists( $this->user->mId, [
			[
				'rl_is_default' => 1,
				'rl_name' => 'dogs',
				'rl_date_created' => wfTimestampNow(),
				'rl_date_updated' => wfTimestampNow(),
				'rl_deleted' => 0,
			]
		] );

		$this->apiParams['list'] = $listIds[0];
		$this->apiParams['project'] = $apiParams['project'];
		$this->apiParams['title'] = $apiParams['title'];
		$this->expectException( ApiUsageException::class );
		$this->expectExceptionMessage( 'is not a recognized project' );
		$result = $this->doApiRequestWithToken( $this->apiParams, null, $this->user );
	}

	public static function createEntryUnrecognizedProjectProvider() {
		return [
			[ [ 'https://pt.wikipedia.org' ],
				[ 'project' => 'https://en.wikipedia.org', 'title' => 'Dog' ],
				'Success',
			],
		];
	}

	protected function tearDown(): void {
		$this->readingListsTeardown();
		parent::tearDown();
	}
}
