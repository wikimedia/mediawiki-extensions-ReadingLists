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
	}

	/**
	 * @dataProvider createEntryProvider
	 */
	public function testCreateEntry( $projects, $apiParams, $expected ) {
		$this->readingListsSetup();

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

	public function testCreateEntry_omittingListParamAddToExistingDefaultList() {
		$defaultListId = $this->readingListsSetup();
		$this->addProjects( [ 'https://en.wikipedia.org' ] );

		$this->apiParams['project'] = 'https://en.wikipedia.org';
		$this->apiParams['title'] = 'Kitten';

		$result = $this->doApiRequestWithToken( $this->apiParams, null, $this->user );
		$this->assertEquals( 'Success', $result[0]['createentry']['result'] );

		$entry = $result[0]['createentry']['entry'];

		$this->assertEquals( $defaultListId, $entry['listId'] );
		$this->assertEquals( 'https://en.wikipedia.org', $entry['project'] );
		$this->assertEquals( 'Kitten', $entry['title'] );
	}

	public function testCreateEntry_omittingListParamSetupAndAddToDefaultList() {
		$this->needsTeardown = true;
		$this->setMwGlobals( [
			'wgCentralIdLookupProvider' => 'local',
		] );

		$this->addProjects( [ 'https://en.wikipedia.org' ] );

		$this->apiParams['project'] = 'https://en.wikipedia.org';
		$this->apiParams['title'] = 'Another Kitten';

		$result = $this->doApiRequestWithToken( $this->apiParams, null, $this->user );
		$this->assertEquals( 'Success', $result[0]['createentry']['result'] );

		$entry = $result[0]['createentry']['entry'];
		$this->assertEquals( 'https://en.wikipedia.org', $entry['project'] );
		$this->assertEquals( 'Another Kitten', $entry['title'] );

		$listsResult = $this->doApiRequest( [
			'action' => 'query',
			'meta' => 'readinglists',
			'rllist' => $entry['listId'],
		], null, false, $this->user );

		$list = $listsResult[0]['query']['readinglists'][0];
		$this->assertTrue( $list['default'] );
	}

	/**
	 * @dataProvider createEntryBatchProvider
	 */
	public function testCreateEntryBatch( $projects, $apiParams, $expected ) {
		$this->readingListsSetup();
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

	public function testCreateEntryBatch_withoutListParamFails() {
		$this->readingListsSetup();
		$this->addProjects( [ 'https://en.wikipedia.org' ] );

		$this->apiParams['batch'] = json_encode( [
			(object)[ 'project' => 'https://en.wikipedia.org', 'title' => 'Dog' ],
			(object)[ 'project' => 'https://en.wikipedia.org', 'title' => 'Cat' ],
		] );

		$this->expectException( ApiUsageException::class );
		$this->expectExceptionMessage( 'The parameter "list" is required' );
		$this->doApiRequestWithToken( $this->apiParams, null, $this->user );
	}

	/**
	 * @dataProvider createEntryUnrecognizedProjectProvider
	 */
	public function testCreateEntryUnrecognizedProject( $projects, $apiParams, $expected ) {
		$this->readingListsSetup();
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
		if ( $this->needsTeardown ) {
			$this->readingListsTeardown();
		}
		parent::tearDown();
	}
}
