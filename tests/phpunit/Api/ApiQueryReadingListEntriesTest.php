<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Api;

use MediaWiki\Extension\ReadingLists\Tests\ReadingListsTestHelperTrait;
use MediaWiki\Tests\Api\ApiTestCase;
use MediaWiki\User\User;

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

	/** @var string Create date that isn't older than one month to test rlchangedsince */
	private $lastUpdate;

	/** @var User */
	private $user;

	public function __construct( $name = null, array $data = [], $dataName = '' ) {
		parent::__construct( $name, $data, $dataName );
		$this->lastUpdate = wfTimestamp( TS_MW );
	}

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
						'rle_date_updated' => $this->lastUpdate,
						'rle_deleted' => 0,
					],
					[
						'rlp_project' => 'foo1',
						'rle_title' => 'Cat',
						'rle_date_created' => '20100101000000',
						'rle_date_updated' => '20180903000000',
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
						'rle_date_updated' => '20180902000000',
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
	public function testApiQuery( $apiParams, $expected ) {
		$this->apiParams = array_merge( $this->apiParams, $apiParams );

		$result = $this->doApiRequest( $this->apiParams, null, $this->user );
		unset( $result[0]['query']['readinglists-synctimestamp'] );
		$this->assertEquals( $expected, $result[0] );
	}

	public function apiQueryProvider() {
		$mwLastUpdate = wfTimestamp( TS_ISO_8601, $this->lastUpdate );
		// Create date 7 days before the last update to test rlchangedsince
		$rlChangedSince = wfTimestamp( TS_ISO_8601, strtotime( "-7 days" ) );
		return [
			[ [ 'rlesort' => 'updated', 'rledir' => 'descending', 'rlelists' => "1|2|3" ],
				[
					"batchcomplete" => true,
					"query" => [
						"readinglistentries" => [
							[
								'id' => 2,
								'project' => 'foo',
								'title' => 'Dog',
								'created' => '2010-01-01T00:00:00Z',
								'updated' => $mwLastUpdate,
								'listId' => 2
							],
							[
								'id' => 3,
								'project' => 'foo1',
								'title' => 'Cat',
								'created' => '2010-01-01T00:00:00Z',
								'updated' => '2018-09-03T00:00:00Z',
								'listId' => 2
							],
							[
								'id' => 5,
								'project' => 'foo3',
								'title' => 'Dolphin',
								'created' => '2010-01-01T00:00:00Z',
								'updated' => '2018-09-02T00:00:00Z',
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
			],
			[ [ 'rlechangedsince' => $rlChangedSince ],
				[
					"batchcomplete" => true,
					"query" => [
						"readinglistentries" => [
							[
								'id' => 2,
								'project' => 'foo',
								'title' => 'Dog',
								'created' => '2010-01-01T00:00:00Z',
								'updated' => $mwLastUpdate,
								'listId' => 2
							],
						],
					]
				],
			],
			[ [ 'rlesort' => 'name', 'rledir' => 'ascending', 'rlelimit' => 1, 'rlelists' => "2" ],
				[
					"batchcomplete" => true,
					"query" => [
						"readinglistentries" => [ [
								'id' => 3,
								'project' => 'foo1',
								'title' => 'Cat',
								'created' => '2010-01-01T00:00:00Z',
								'updated' => '2018-09-03T00:00:00Z',
								'listId' => 2
							],
						],
					],
					"continue" => [
						"rlecontinue" => "Dog|2",
						"continue" => "-||"
					],
				],
			],
			[ [ 'rlesort' => 'name',
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
								'updated' => $mwLastUpdate,
								'listId' => 2
							],
						],
					],
					"continue" => [
						"rlecontinue" => "Dolphin|5",
						"continue" => "-||"
					],
				],
			],
		];
	}
}
