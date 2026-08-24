<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Api;

use MediaWiki\Tests\Api\ApiTestCase;
use MediaWiki\User\User;

/**
 * @covers \MediaWiki\Extension\ReadingLists\Api\ApiReadingListsCreate
 * @covers \MediaWiki\Extension\ReadingLists\Api\ApiReadingLists
 * @group medium
 * @group API
 * @group Database
 */
class ApiReadingListsCreateTest extends ApiTestCase {

	use ReadingListsApiTestHelperTrait;

	/** @var array */
	private $apiParams = [
		'action' => 'readinglists',
		'format' => 'json',
		'command' => 'create',
	];

	/** @var User */
	private $user;

	protected function setUp(): void {
		parent::setUp();
		$this->user = parent::getTestSysop()->getUser();
		$this->readingListsSetup( $this->user );
	}

	public function testCreate() {
		$apiParams = [ 'name' => 'dogs', 'description' => 'Woof!' ];
		$this->apiParams = array_merge( $this->apiParams, $apiParams );
		$result = $this->doApiRequestWithToken( $this->apiParams, null, $this->user );

		$this->assertEquals( 'Success', $result[0]['create']['result'] );
	}

	public function testCreateWithoutDescription() {
		$apiParams = [ 'name' => 'cats' ];
		$this->apiParams = array_merge( $this->apiParams, $apiParams );
		$result = $this->doApiRequestWithToken( $this->apiParams, null, $this->user );

		$this->assertEquals( 'Success', $result[0]['create']['result'] );
		$this->assertEquals( 'cats', $result[0]['create']['list']['name'] );
		$this->assertSame( '', $result[0]['create']['list']['description'] );
	}

	/**
	 * @dataProvider createBatchProvider
	 */
	public function testCreateBatch( $batch, $expectedDescriptions ) {
		$this->apiParams['batch'] = json_encode( $batch );
		$result = $this->doApiRequestWithToken( $this->apiParams, null, $this->user );

		$this->assertSame( 'Success', $result[0]['create']['result'] );
		$this->assertSame( $expectedDescriptions, array_column( $result[0]['create']['lists'], 'description' ) );
	}

	public static function createBatchProvider() {
		return [
			[
				[
					[ 'name' => 'dogs' ],
					[ 'name' => 'cats', 'description' => 'Meow!' ],
				],
				[
					'',
					'Meow!'
				],
			],
		];
	}

	public function testCreateWithNameAndBatchNotAllowed() {
		$this->apiParams['name'] = 'dogs';
		$this->apiParams['batch'] = json_encode( [
			[ 'name' => 'cats' ],
		] );
		$this->expectException( 'MediaWiki\Api\ApiUsageException' );
		$this->doApiRequestWithToken( $this->apiParams, null, $this->user );
	}

	public function testCreateWithDescriptionAndBatchNotAllowed() {
		$this->apiParams['description'] = 'meowy!';
		$this->apiParams['batch'] = json_encode( [
			[ 'name' => 'cats' ],
		] );
		$this->expectException( 'MediaWiki\Api\ApiUsageException' );
		$this->doApiRequestWithToken( $this->apiParams, null, $this->user );
	}

	public function testCreateWithNameDescriptionAndBatchNotAllowed() {
		$this->apiParams['name'] = 'favorite cats';
		$this->apiParams['description'] = 'meowy!';
		$this->apiParams['batch'] = json_encode( [
			[ 'name' => 'cats' ],
		] );
		$this->expectException( 'MediaWiki\Api\ApiUsageException' );
		$this->doApiRequestWithToken( $this->apiParams, null, $this->user );
	}
}
