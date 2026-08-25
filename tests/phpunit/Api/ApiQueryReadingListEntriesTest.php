<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Api;

use MediaWiki\Config\SiteConfiguration;
use MediaWiki\Extension\ReadingLists\LocalProjectHelper;
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

	/** @var int */
	private static $user2Id;

	/** @var int */
	private static $user2ListId;

	/** @var User */
	private $user2;

	protected function setUp(): void {
		parent::setUp();

		$this->setMwGlobals( [
			'wgCentralIdLookupProvider' => 'local',
		] );

		$this->user2 = $this->getServiceContainer()
			->getUserFactory()
			->newFromId( self::$user2Id );
	}

	public function addDBDataOnce(): void {
		$this->addQueryTestData( $this->getTestSysop()->getUser()->mId );

		$user2 = $this->getTestUser()->getUser();
		self::$user2Id = $user2->getId();
		$this->addAdditionalQueryTestData( self::$user2Id );
	}

	private function addQueryTestData( int $userId ): void {
		$this->addProjects( [ 'foo' ] );
		$this->addLists( $userId, [
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

	private function addAdditionalQueryTestData( int $userId ): void {
		$localProject = LocalProjectHelper::getLocalProject();
		$listIds = $this->addLists( $userId, [
			[
				'rl_is_default' => 0,
				'rl_name' => 'pagination',
				'rl_description' => '',
				'rl_date_created' => '20170913205936',
				'rl_date_updated' => '20170913205936',
				'rl_deleted' => 0,
				'entries' => [
					[
						'rlp_project' => 'foo',
						'rle_title' => 'Apple_pie',
						'rle_date_created' => '20100101000000',
						'rle_date_updated' => '20180816000000',
						'rle_deleted' => 0,
					],
					[
						'rlp_project' => 'foo',
						'rle_title' => 'Banana_split',
						'rle_date_created' => '20100101000000',
						'rle_date_updated' => '20180817000000',
						'rle_deleted' => 0,
					],
					[
						'rlp_project' => 'foo',
						'rle_title' => 'Cherry_cake',
						'rle_date_created' => '20100101000000',
						'rle_date_updated' => '20180818000000',
						'rle_deleted' => 0,
					],
				],
			],
			[
				'rl_is_default' => 0,
				'rl_name' => 'local project',
				'rl_description' => '',
				'rl_date_created' => '20170913205936',
				'rl_date_updated' => '20170913205936',
				'rl_deleted' => 0,
				'entries' => [
					[
						'rlp_project' => $localProject,
						'rle_title' => 'Zebra',
						'rle_date_created' => '20100101000000',
						'rle_date_updated' => '20180817000000',
						'rle_deleted' => 0,
					],
					[
						'rlp_project' => 'https://example.org',
						'rle_title' => 'Moose',
						'rle_date_created' => '20100101000000',
						'rle_date_updated' => '20180818000000',
						'rle_deleted' => 0,
					],
					[
						'rlp_project' => $localProject,
						'rle_title' => 'Ant',
						'rle_date_created' => '20100101000000',
						'rle_date_updated' => '20180819000000',
						'rle_deleted' => 0,
					],
				],
			],
			[
				'rl_is_default' => 0,
				'rl_name' => 'wiki ids',
				'rl_description' => '',
				'rl_date_created' => '20170913205936',
				'rl_date_updated' => '20170913205936',
				'rl_deleted' => 0,
				'entries' => [
					[
						'rlp_project' => 'https://en.example.org',
						'rle_title' => 'Eagle',
						'rle_date_created' => '20100101000000',
						'rle_date_updated' => '20180817000000',
						'rle_deleted' => 0,
					],
					[
						'rlp_project' => 'https://de.example.org',
						'rle_title' => 'Bear',
						'rle_date_created' => '20100101000000',
						'rle_date_updated' => '20180818000000',
						'rle_deleted' => 0,
					],
					[
						'rlp_project' => 'https://fr.example.org',
						'rle_title' => 'Wolf',
						'rle_date_created' => '20100101000000',
						'rle_date_updated' => '20180819000000',
						'rle_deleted' => 0,
					],
				],
			],
		] );

		self::$user2ListId = $listIds[0];
	}

	public function testApiQuery(): void {
		ConvertibleTimestamp::setFakeTime( '2018-09-13T20:59:36Z' );

		foreach ( self::apiQueryCases() as [ $apiParams, $expected, $message ] ) {
			$this->assertApiQuery( $apiParams, $expected, $message );
		}
	}

	private function assertApiQuery( array $apiParams, array $expected, string $message ): void {
		$result = $this->doApiRequest(
			array_merge( $this->apiParams, $apiParams ),
			null,
			false,
			$this->getTestSysop()->getAuthority()
		);

		unset( $result[0]['query']['readinglists-synctimestamp'] );
		$this->assertEquals( $expected, $result[0], $message );
	}

	private static function apiQueryCases(): array {
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
			[
				[
					'rlesort' => 'updated',
					'rledir' => 'descending',
					'rlelists' => "1|2|3",
					'rleprojects' => 'foo|foo3',
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
								'id' => 5,
								'project' => 'foo3',
								'title' => 'Dolphin',
								'created' => '2010-01-01T00:00:00Z',
								'updated' => '2018-10-01T00:00:00Z',
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
				'Filter entries by projects for specific lists',
			],
			[
				[
					'rlechangedsince' => '2018-09-15T12:31:19Z',
					'rleprojects' => 'foo1|foo3',
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
						],
					]
				],
				'Filter changed entries by projects',
			],
		];
	}

	public function testTitleWithUnderscoresPaginationUsesRawContinueToken(): void {
		ConvertibleTimestamp::setFakeTime( '2018-09-13T20:59:36Z' );

		$firstPage = $this->doApiRequest(
			array_merge( $this->apiParams, [
				'rlelists' => (string)self::$user2ListId,
				'rlesort' => 'name',
				'rledir' => 'ascending',
				'rlelimit' => 1,
			] ),
			null,
			false,
			$this->user2
		);

		$firstPageEntries = $firstPage[0]['query']['readinglistentries'];
		$this->assertCount( 1, $firstPageEntries );
		$this->assertSame( 'Apple pie', $firstPageEntries[0]['title'] );

		$secondPage = $this->doApiRequest(
			array_merge( $this->apiParams, [
				'rlelists' => (string)self::$user2ListId,
				'rlesort' => 'name',
				'rledir' => 'ascending',
				'rlelimit' => 1,
				'rlecontinue' => $firstPage[0]['continue']['rlecontinue'],
			] ),
			null,
			false,
			$this->user2
		);

		$secondPageEntries = $secondPage[0]['query']['readinglistentries'];
		$this->assertCount( 1, $secondPageEntries );
		$this->assertSame(
			'Banana_split|' . $secondPageEntries[0]['id'],
			$firstPage[0]['continue']['rlecontinue']
		);
		$this->assertSame( 'Banana split', $secondPageEntries[0]['title'] );
	}

	public function testApiQueryProjectsFilterAcceptsLocalProject(): void {
		$localProject = LocalProjectHelper::getLocalProject();

		$result = $this->doApiRequest(
			array_merge( $this->apiParams, [
				'rlesort' => 'name',
				'rledir' => 'ascending',
				'rleprojects' => '@local',
			] ),
			null,
			false,
			$this->user2
		);

		$entries = $result[0]['query']['readinglistentries'];
		$this->assertSame(
			[
				$localProject . ':Ant',
				$localProject . ':Zebra',
			],
			array_map( static function ( $entry ) {
				return $entry['project'] . ':' . $entry['title'];
			}, $entries )
		);
	}

	public function testApiQueryProjectsFilterAcceptsWikiIds(): void {
		$conf = new SiteConfiguration();
		$conf->suffixes = [ 'wiki' ];
		$conf->settings = [
			'wgServer' => [
				'enwiki' => '//en.example.org',
				'dewiki' => '//de.example.org',
			],
			'wgCanonicalServer' => [
				'enwiki' => 'https://en.example.org',
				'dewiki' => 'https://de.example.org',
			],
			'wgArticlePath' => [
				'enwiki' => '/wiki/$1',
				'dewiki' => '/wiki/$1',
			],
		];
		$this->setMwGlobals( 'wgConf', $conf );

		$result = $this->doApiRequest(
			array_merge( $this->apiParams, [
				'rlesort' => 'name',
				'rledir' => 'ascending',
				'rleprojects' => 'enwiki|dewiki',
			] ),
			null,
			false,
			$this->user2
		);

		$entries = $result[0]['query']['readinglistentries'];
		$this->assertSame(
			[
				'https://de.example.org:Bear',
				'https://en.example.org:Eagle',
			],
			array_map( static function ( $entry ) {
				return $entry['project'] . ':' . $entry['title'];
			}, $entries )
		);
	}

	public function testApiQueryEntriesFromAllLists(): void {
		ConvertibleTimestamp::setFakeTime( '2018-09-13T20:59:36Z' );

		foreach ( self::apiQueryEntriesFromAllListsCases() as [ $apiParams, $expected, $message ] ) {
			$this->assertApiQuery( $apiParams, $expected, $message );
		}
	}

	private static function apiQueryEntriesFromAllListsCases(): array {
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
