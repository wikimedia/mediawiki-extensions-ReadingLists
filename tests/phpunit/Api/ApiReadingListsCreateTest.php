<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Api;

use ApiTestCase;
use MediaWiki\Extension\ReadingLists\HookHandler;
use MediaWiki\Extension\ReadingLists\Tests\ReadingListsTestHelperTrait;

/**
 * @covers \MediaWiki\Extension\ReadingLists\Api\ApiReadingListsCreate
 * @covers \MediaWiki\Extension\ReadingLists\Api\ApiReadingLists
 * @group medium
 * @group API
 * @group Database
 */
class ApiReadingListsCreateTest extends ApiTestCase {

	use ReadingListsTestHelperTrait;

	/** @var array */
	private $apiParams = [
		'action' => 'readinglists',
		'format' => 'json',
		'command' => 'create',
	];

	/** @var \User */
	private $user;

	protected function setUp(): void {
		parent::setUp();
		$this->tablesUsed = array_merge( $this->tablesUsed, HookHandler::$testTables );
		$this->user = parent::getTestSysop()->getUser();
		$this->readingListsSetup();
	}

	/**
	 * @dataProvider createProvider
	 */
	public function testCreate( $apiParams, $expected ) {
		$this->apiParams['name'] = $apiParams['name'];
		$this->apiParams['description'] = $apiParams['description'];
		$result = $this->doApiRequestWithToken( $this->apiParams, null, $this->user );
		$this->assertEquals( $expected, $result[0]['create']['result'] );
	}

	public function createProvider() {
		return [
			[ [ 'name' => 'dogs', 'description' => 'Woof!' ], 'Success'
			]
		];
	}

	/**
	 * @dataProvider createBatchProvider
	 */
	public function testCreateBatch( $apiParams, $expected ) {
		$this->apiParams['batch'] = json_encode( [
			(object)[ "name" => $apiParams[0]['name'],"description" => $apiParams[0]['description'] ],
			(object)[ "name" => $apiParams[1]['name'],"description" => $apiParams[1]['description'] ],
		] );
		$result = $this->doApiRequestWithToken( $this->apiParams, null, $this->user );
		$this->assertEquals( $expected, $result[0]['create']['result'] );
	}

	public function createBatchProvider() {
		return [
			[ [ [ 'name' => 'dogs', 'description' => 'Woof!' ],
					[ 'name' => 'cats', 'description' => 'Meow!' ],
				], 'Success'
			]
		];
	}

	// TODO: Create a test provide that pass the apiParams

	protected function tearDown(): void {
		parent::tearDown();
		$this->readingListsTeardown();
	}
}
