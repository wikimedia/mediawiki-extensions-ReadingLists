<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Api;

use MediaWiki\Extension\ReadingLists\Tests\ReadingListsTestHelperTrait;
use MediaWiki\Tests\Api\ApiTestCase;
use MediaWiki\User\User;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @covers \MediaWiki\Extension\ReadingLists\Api\ApiQueryReadingLists
 * @group medium
 * @group API
 * @group Database
 */
class ApiQueryReadingListsTest extends ApiTestCase {

	use ReadingListsTestHelperTrait;

	/** @var array */
	private $apiParams = [
		'action'  => 'query',
		'format'  => 'json',
		'meta'    => 'readinglists',
	];

	/** @var User */
	private $user;

	protected function setUp(): void {
		parent::setUp();
		$this->user = parent::getTestSysop()->getUser();
		$this->addProjects( [ 'foo' ] );
		$listIds = $this->addLists( $this->user->mId, [
			[
				'rl_is_default' => 1,
				'rl_name' => 'default',
				'rl_description' => 'default list',
				'rl_date_created' => '20170913205936',
				'rl_date_updated' => '20170913205936',
				'rl_deleted' => 0,
			],
			[
				'rl_is_default' => 0,
				'rl_name' => 'dogs',
				'rl_description' => 'Woof!',
				'rl_date_created' => '20170913205936',
				'rl_date_updated' => '20170913205936',
				'rl_deleted' => 0,
				'entries' => [
					[
						'rlp_project' => 'foo',
						'rle_title' => 'Dog',
						'rle_date_created' => '20100101000000',
						'rle_date_updated' => '20150101000000',
						'rle_deleted' => 0,
					],
				],
			],
			[
				'rl_is_default' => 0,
				'rl_name' => 'cats',
				'rl_description' => "Meow!",
				'rl_date_created' => '20180913205936',
				'rl_date_updated' => '20180913205936',
				'rl_deleted' => 0,
			]
		] );
	}

	/**
	 * @dataProvider apiQueryProvider
	 */
	public function testApiQuery( $apiParams, $expected ) {
		ConvertibleTimestamp::setFakeTime( '2018-09-13T20:59:36Z' );

		$this->apiParams = array_merge( $this->apiParams, $apiParams );

		$result = $this->doApiRequest( $this->apiParams, null, $this->user );
		unset( $result[0]['query']['readinglists-synctimestamp'] );
		$this->assertEquals( $expected, $result[0] );
	}

	public static function apiQueryProvider() {
		return [
			[ [ 'rllist' => 2 ],
				[
					"batchcomplete" => true,
					"query" => [
						"readinglists" => [
							[
								"id" => 2,
								"name" => "dogs",
								"default" => false,
								"description" => "Woof!",
								"created" => "2017-09-13T20:59:36Z",
								"updated" => "2017-09-13T20:59:36Z"
							],
						],
					]
				],
			],
			[ [ 'rlsort' => 'name', 'rldir' => 'descending' ],
				[
					"batchcomplete" => true,
					"query" => [
						"readinglists" => [
							[
								"id" => 2,
								"name" => "dogs",
								"default" => false,
								"description" => "Woof!",
								"created" => "2017-09-13T20:59:36Z",
								"updated" => "2017-09-13T20:59:36Z"
							],
							[
								"id" => 1,
								"name" => "default",
								"default" => true,
								"description" => "default list",
								"created" => "2017-09-13T20:59:36Z",
								"updated" => "2017-09-13T20:59:36Z"
							],
							[
								"id" => 3,
								"name" => "cats",
								"default" => false,
								"description" => "Meow!",
								"created" => "2018-09-13T20:59:36Z",
								"updated" => "2018-09-13T20:59:36Z"
							],
						],
					]
				],
			],
			[ [ 'rltitle' => 'Dog', 'rlproject' => 'foo' ],
				[
					"batchcomplete" => true,
					"query" => [
						"readinglists" => [
							[
								"id" => 2,
								"name" => "dogs",
								"default" => false,
								"description" => "Woof!",
								"created" => "2017-09-13T20:59:36Z",
								"updated" => "2017-09-13T20:59:36Z"
							],
						],
					]
				],
			],
			[ [ 'rlchangedsince' => '2018-09-10T00:00:00Z' ],
				[
					"batchcomplete" => true,
					"query" => [
						"readinglists" => [
							[
								"id" => 3,
								"name" => "cats",
								"default" => false,
								"description" => "Meow!",
								"created" => "2018-09-13T20:59:36Z",
								"updated" => "2018-09-13T20:59:36Z"
							],
						],
					]
				],
			],
			[ [ 'rlsort' => 'name', 'rldir' => 'ascending', 'rllimit' => 1 ],
				[
					"batchcomplete" => true,
					"query" => [
						"readinglists" => [
							[
								"id" => 3,
								"name" => "cats",
								"default" => false,
								"description" => "Meow!",
								"created" => "2018-09-13T20:59:36Z",
								"updated" => "2018-09-13T20:59:36Z"
							],
						],
					],
					"continue" => [
						"rlcontinue" => "default|1",
						"continue" => "-||"
					],
				],
			],
			[ [ 'rlsort' => 'name', 'rldir' => 'ascending', 'rllimit' => 1, "rlcontinue" => "default|1" ],
				[
					"batchcomplete" => true,
					"query" => [
						"readinglists" => [
							[
								"id" => 1,
								"name" => "default",
								"default" => true,
								"description" => "default list",
								"created" => "2017-09-13T20:59:36Z",
								"updated" => "2017-09-13T20:59:36Z"
							],
						],
					],
					"continue" => [
						"rlcontinue" => "dogs|2",
						"continue" => "-||"
					],
				],
			],
		];
	}
}
