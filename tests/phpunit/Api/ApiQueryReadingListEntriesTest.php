<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Api;

use MediaWiki\Extension\ReadingLists\Tests\ReadingListsTestHelperTrait;
use MediaWiki\Tests\Api\ApiTestCase;
use MediaWiki\User\User;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @covers \MediaWiki\Extension\ReadingLists\Api\ApiQueryReadingListEntries
 * @group medium
 * @group API
 * @group Database
 */
class ApiQueryReadingListEntriesTest extends ApiTestCase {

	use ReadingListsTestHelperTrait;

	/** @var array */
	private $apiParams = [
		'action'  => 'query',
		'format'  => 'json',
		'list'    => 'readinglistentries',
	];

	/** @var User */
	private $user;

	protected function setUp(): void {
		parent::setUp();

		$this->setMwGlobals( [
			'wgCentralIdLookupProvider' => 'local',
		] );

		$this->user = $this->getTestSysop()->getUser();
		$this->addProjects( [ 'foo' ] );
		$listIds = $this->addLists( $this->user->mId, [
			[
				'rl_is_default' => 1,
				'rl_name' => 'default',
				'rl_description' => 'default list',
				'rl_date_created' => '20170913205936',
				'rl_date_updated' => '20170913205936',
				'rl_deleted' => 0,
				'entries' => [
					[
						'rlp_project' => 'foo',
						'rle_title' => 'default stuff',
						'rle_date_created' => '20100101000000',
						'rle_date_updated' => '20180817000000',
						'rle_deleted' => 0,
					],
				],
			],
			[
				'rl_is_default' => 0,
				'rl_name' => 'animals',
				'rl_description' => 'animals list',
				'rl_date_created' => '20170913205936',
				'rl_date_updated' => '20170913205936',
				'rl_deleted' => 0,
				'entries' => [
					[
						'rlp_project' => 'foo',
						'rle_title' => 'Dog',
						'rle_date_created' => '20100101000000',
						'rle_date_updated' => '20181201000000',
						'rle_deleted' => 0,
					],
					[
						'rlp_project' => 'foo1',
						'rle_title' => 'Cat',
						'rle_date_created' => '20100101000000',
						'rle_date_updated' => '20181101000000',
						'rle_deleted' => 0,
					],
					[
						'rlp_project' => 'foo2',
						'rle_title' => 'Llama',
						'rle_date_created' => '20100101000000',
						'rle_date_updated' => '20180901000000',
						'rle_deleted' => 0,
					],
					[
						'rlp_project' => 'foo3',
						'rle_title' => 'Dolphin',
						'rle_date_created' => '20100101000000',
						'rle_date_updated' => '20181001000000',
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
				'entries' => [
					[
						'rlp_project' => 'foo',
						'rle_title' => 'Cute eyes',
						'rle_date_created' => '20100101000000',
						'rle_date_updated' => '20180821000000',
						'rle_deleted' => 0,
					],
				],
			]
		] );
	}

	/**
	 * @dataProvider apiQueryProvider
	 */
	public function testApiQuery( $apiParams, $expected, $message ) {
		ConvertibleTimestamp::setFakeTime( '2018-09-13T20:59:36Z' );

		$this->apiParams = array_merge( $this->apiParams, $apiParams );

		$result = $this->doApiRequest( $this->apiParams, null, $this->user );
		unset( $result[0]['query']['readinglists-synctimestamp'] );
		$this->assertEquals( $expected, $result[0], $message );
	}

	public static function apiQueryProvider() {
		return [
			[
				[
					'rlesort' => 'updated',
					'rledir' => 'descending',
					'rlelists' => "1|2|3"
				],
				[
					"batchcomplete" => true,
					"query" => [
						"readinglistentries" => [
							[
								'id' => 2,
								'project' => 'foo',
								'title' => 'Dog',
								'created' => '2010-01-01T00:00:00Z',
								'updated' => '2018-12-01T00:00:00Z',
								'listId' => 2
							],
							[
								'id' => 3,
								'project' => 'foo1',
								'title' => 'Cat',
								'created' => '2010-01-01T00:00:00Z',
								'updated' => '2018-11-01T00:00:00Z',
								'listId' => 2
							],
							[
								'id' => 5,
								'project' => 'foo3',
								'title' => 'Dolphin',
								'created' => '2010-01-01T00:00:00Z',
								'updated' => '2018-10-01T00:00:00Z',
								'listId' => 2
							],
							[
								'id' => 4,
								'project' => 'foo2',
								'title' => 'Llama',
								'created' => '2010-01-01T00:00:00Z',
								'updated' => '2018-09-01T00:00:00Z',
								'listId' => 2
							],
							[
								'id' => 6,
								'project' => 'foo',
								'title' => 'Cute eyes',
								'created' => '2010-01-01T00:00:00Z',
								'updated' => '2018-08-21T00:00:00Z',
								'listId' => 3
							],
							[
								'id' => 1,
								'project' => 'foo',
								'title' => 'default stuff',
								'created' => '2010-01-01T00:00:00Z',
								'updated' => '2018-08-17T00:00:00Z',
								'listId' => 1
							],
						],
					]
				],
				'Sort entries by updated date descending for specific lists',
			],
			[
				[
					'rlechangedsince' => '2018-09-15T12:31:19Z'
				],
				[
					"batchcomplete" => true,
					"query" => [
						"readinglistentries" => [
							[
								'id' => 5,
								'project' => 'foo3',
								'title' => 'Dolphin',
								'created' => '2010-01-01T00:00:00Z',
								'updated' => '2018-10-01T00:00:00Z',
								'listId' => 2
							],
							[
								'id' => 3,
								'project' => 'foo1',
								'title' => 'Cat',
								'created' => '2010-01-01T00:00:00Z',
								'updated' => '2018-11-01T00:00:00Z',
								'listId' => 2
							],
							[
								'id' => 2,
								'project' => 'foo',
								'title' => 'Dog',
								'created' => '2010-01-01T00:00:00Z',
								'updated' => '2018-12-01T00:00:00Z',
								'listId' => 2
							],
						],
					]
				],
				'Filter entries changed since 2018-09-15T12:31:19Z',
			],
			[
				[
					'rlesort' => 'name',
					'rledir' => 'ascending',
					'rlelimit' => 1, 'rlelists' => "2"
				],
				[
					"batchcomplete" => true,
					"query" => [
						"readinglistentries" => [ [
								'id' => 3,
								'project' => 'foo1',
								'title' => 'Cat',
								'created' => '2010-01-01T00:00:00Z',
								'updated' => '2018-11-01T00:00:00Z',
								'listId' => 2
							],
						],
					],
					"continue" => [
						"rlecontinue" => "Dog|2",
						"continue" => "-||"
					],
				],
				'Sort entries by name ascending with limit 1 for list 2',
			],
			[
				[
					'rlesort' => 'name',
					'rledir' => 'ascending',
					'rlelimit' => 1,
					"rlecontinue" => "Cute eyes|6",
					'rlelists' => "2"
				],
				[
					"batchcomplete" => true,
					"query" => [
						"readinglistentries" => [
							[
								'id' => 2,
								'project' => 'foo',
								'title' => 'Dog',
								'created' => '2010-01-01T00:00:00Z',
								'updated' => '2018-12-01T00:00:00Z',
								'listId' => 2
							],
						],
					],
					"continue" => [
						"rlecontinue" => "Dolphin|5",
						"continue" => "-||"
					],
				],
				'Sort entries by name ascending with continue parameter',
			],
		];
	}

	/**
	 * @dataProvider apiQueryEntriesFromAllListsProvider
	 */
	public function testApiQueryEntriesFromAllLists( $apiParams, $expected, $message ) {
		$this->testApiQuery( $apiParams, $expected, $message );
	}

	public static function apiQueryEntriesFromAllListsProvider() {
		return [
			[
				[
					'rlesort' => 'updated',
					'rledir' => 'descending',
					'rlelimit' => 10,
				],
				[
					"batchcomplete" => true,
					"query" => [
						"readinglistentries" => [
							[
								'id' => 2,
								'listId' => 2,
								'project' => 'foo',
								'title' => 'Dog',
								'created' => '2010-01-01T00:00:00Z',
								'updated' => '2018-12-01T00:00:00Z',
							],
							[
								'id' => 3,
								'listId' => 2,
								'project' => 'foo1',
								'title' => 'Cat',
								'created' => '2010-01-01T00:00:00Z',
								'updated' => '2018-11-01T00:00:00Z',
							],
							[
								'id' => 5,
								'listId' => 2,
								'project' => 'foo3',
								'title' => 'Dolphin',
								'created' => '2010-01-01T00:00:00Z',
								'updated' => '2018-10-01T00:00:00Z',
							],
							[
								'id' => 4,
								'listId' => 2,
								'project' => 'foo2',
								'title' => 'Llama',
								'created' => '2010-01-01T00:00:00Z',
								'updated' => '2018-09-01T00:00:00Z',
							],
							[
								'id' => 6,
								'listId' => 3,
								'project' => 'foo',
								'title' => 'Cute eyes',
								'created' => '2010-01-01T00:00:00Z',
								'updated' => '2018-08-21T00:00:00Z',
							],
							[
								'id' => 1,
								'listId' => 1,
								'project' => 'foo',
								'title' => 'default stuff',
								'created' => '2010-01-01T00:00:00Z',
								'updated' => '2018-08-17T00:00:00Z',
							],
						],
					],
				],
				'Get entries for all lists sorted by updated date descending with limit 10',
			],
			[
				[
					'rlesort' => 'name',
					'rledir' => 'ascending',
					'rlelimit' => 10,
				],
				[
					"batchcomplete" => true,
					"query" => [
						"readinglistentries" => [
							[
								'id' => 3,
								'listId' => 2,
								'project' => 'foo1',
								'title' => 'Cat',
								'created' => '2010-01-01T00:00:00Z',
								'updated' => '2018-11-01T00:00:00Z',
							],
							[
								'id' => 6,
								'listId' => 3,
								'project' => 'foo',
								'title' => 'Cute eyes',
								'created' => '2010-01-01T00:00:00Z',
								'updated' => '2018-08-21T00:00:00Z',
							],
							[
								'id' => 2,
								'listId' => 2,
								'project' => 'foo',
								'title' => 'Dog',
								'created' => '2010-01-01T00:00:00Z',
								'updated' => '2018-12-01T00:00:00Z',
							],
							[
								'id' => 5,
								'listId' => 2,
								'project' => 'foo3',
								'title' => 'Dolphin',
								'created' => '2010-01-01T00:00:00Z',
								'updated' => '2018-10-01T00:00:00Z',
							],
							[
								'id' => 4,
								'listId' => 2,
								'project' => 'foo2',
								'title' => 'Llama',
								'created' => '2010-01-01T00:00:00Z',
								'updated' => '2018-09-01T00:00:00Z',
							],
							[
								'id' => 1,
								'listId' => 1,
								'project' => 'foo',
								'title' => 'default stuff',
								'created' => '2010-01-01T00:00:00Z',
								'updated' => '2018-08-17T00:00:00Z',
							],
						],
					],
				],
				'Get entries for all lists sorted by name ascending with limit 10 (note API sorting is case sensitive)',
			],
			[
				[
					'rlesort' => 'name',
					'rledir' => 'ascending',
					'rlelimit' => 2,
				],
				[
					"batchcomplete" => true,
					"query" => [
						"readinglistentries" => [
							[
								'id' => 3,
								'listId' => 2,
								'project' => 'foo1',
								'title' => 'Cat',
								'created' => '2010-01-01T00:00:00Z',
								'updated' => '2018-11-01T00:00:00Z',
							],
							[
								'id' => 6,
								'listId' => 3,
								'project' => 'foo',
								'title' => 'Cute eyes',
								'created' => '2010-01-01T00:00:00Z',
								'updated' => '2018-08-21T00:00:00Z',
							],
						],
					],
					"continue" => [
						"rlecontinue" => "Dog|2",
						"continue" => "-||"
					],
				],
				'Get entries from all lists sorted by name ascending with limit 2 (pagination)',
			],
			[
				[
					'rlesort' => 'name',
					'rledir' => 'ascending',
					'rlelimit' => 2,
					"rlecontinue" => "Dog|2",
				],
				[
					"batchcomplete" => true,
					"query" => [
						"readinglistentries" => [
							[
								'id' => 2,
								'listId' => 2,
								'project' => 'foo',
								'title' => 'Dog',
								'created' => '2010-01-01T00:00:00Z',
								'updated' => '2018-12-01T00:00:00Z',
							],
							[
								'id' => 5,
								'listId' => 2,
								'project' => 'foo3',
								'title' => 'Dolphin',
								'created' => '2010-01-01T00:00:00Z',
								'updated' => '2018-10-01T00:00:00Z',
							],
						],
					],
					"continue" => [
						"rlecontinue" => "Llama|4",
						"continue" => "-||"
					],
				],
				'Get entries from all lists sorted by name ascending with continue parameter (pagination continuation)',
			],
		];
	}
}
