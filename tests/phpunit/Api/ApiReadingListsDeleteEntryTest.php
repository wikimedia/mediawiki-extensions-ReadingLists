<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Api;

use MediaWiki\Extension\ReadingLists\Tests\ReadingListsTestHelperTrait;
use MediaWiki\MediaWikiServices;
use MediaWiki\Tests\Api\ApiTestCase;
use MediaWiki\User\User;

/**
 * TODO: Create a test provider that pass the apiParams also test project recognize
 *
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

	public function testDeleteEntryByProjectAndTitle() {
		$localProject = $this->getLocalProject();
		$this->addProjects( [ $localProject ] );
		$listIds = $this->addLists( $this->user->mId, [
			[
				'rl_is_default' => 1,
				'rl_name' => 'Favorite Dogs',
				'rl_date_created' => '20200101000000',
				'rl_date_updated' => '20250101000000',
				'rl_deleted' => 0,
			],
			[
				'rl_is_default' => 0,
				'rl_name' => 'Favorite Cats',
				'rl_date_created' => '20200101000000',
				'rl_date_updated' => '20250101000000',
				'rl_deleted' => 0,
			],
		] );

		$entryIds = $this->addListEntries( $listIds[0], $this->user->mId, [
			[
				'rlp_project' => $localProject,
				'rle_title' => 'Snoopy',
				'rle_date_created' => '20200102000000',
				'rle_date_updated' => '20250102000000',
				'rle_deleted' => 0,
			],
			[
				'rlp_project' => $localProject,
				'rle_title' => "Santa's Little Helper",
				'rle_date_created' => '20200103000000',
				'rle_date_updated' => '20250103000000',
				'rle_deleted' => 0,
			],
		] );
		$entryIds = array_merge( $entryIds, $this->addListEntries( $listIds[1], $this->user->mId, [
			[
				'rlp_project' => $localProject,
				'rle_title' => 'Garfield',
				'rle_date_created' => '20200103000000',
				'rle_date_updated' => '20250103000000',
				'rle_deleted' => 0,
			],
			[
				'rlp_project' => $localProject,
				'rle_title' => 'Snowball_II',
				'rle_date_created' => '20200104000000',
				'rle_date_updated' => '20250105000000',
				'rle_deleted' => 0,
			],
		] ) );

		$this->apiParams['project'] = $localProject;
		$this->apiParams['title'] = 'Snowball II';
		$result = $this->doApiRequestWithToken( $this->apiParams, null, $this->user );
		$this->assertEquals( "Success", $result[0]['deleteentry']['result'] );

		$this->apiParams['project'] = $localProject;
		$this->apiParams['title'] = 'Snoopy';
		$result = $this->doApiRequestWithToken( $this->apiParams, null, $this->user );
		$this->assertEquals( "Success", $result[0]['deleteentry']['result'] );

		$deletedById = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'rle_id', 'rle_deleted' ] )
			->from( 'reading_list_entry' )
			->where( [ 'rle_id' => $entryIds ] )
			->caller( __METHOD__ )
			->fetchResultSet();
		$rows = [];
		foreach ( $deletedById as $row ) {
			$rows[$row->rle_id] = $row->rle_deleted;
		}

		$this->assertSame( '1', $rows[$entryIds[0]], 'Snoopy should be deleted' );
		$this->assertSame( '0', $rows[$entryIds[1]], "Santa's little helper should not be deleted" );
		$this->assertSame( '0', $rows[$entryIds[2]], 'Garfield should not be deleted' );
		$this->assertSame( '1', $rows[$entryIds[3]], 'Snowball II should be deleted' );
	}

	public function testDeleteEntryRequiresProjectAndTitleTogether() {
		$this->apiParams['project'] = $this->getLocalProject();
		$this->assertApiUsage(
			'apierror-missingparam',
			function () {
				$this->doApiRequestWithToken( $this->apiParams, null, $this->user );
			}
		);
	}

	public function testDeleteEntryRequiresTitleAndProjectTogether() {
		$this->apiParams['title'] = 'Dog';
		$this->assertApiUsage(
			'apierror-missingparam',
			function () {
				$this->doApiRequestWithToken( $this->apiParams, null, $this->user );
			}
		);
	}

	public function testDeleteEntryByRemoteProjectAndTitleSkipsLocalValidation() {
		$this->addProjects( [ 'https://remote.example.org' ] );
		$listIds = $this->addLists( $this->user->mId, [
			[
				'rl_is_default' => 1,
				'rl_name' => 'Remote Pages',
				'rl_date_created' => '20200101000000',
				'rl_date_updated' => '20250101000000',
				'rl_deleted' => 0,
			],
		] );

		$entryIds = $this->addListEntries( $listIds[0], $this->user->mId, [
			[
				'rlp_project' => 'https://remote.example.org',
				'rle_title' => 'Remote#Title',
				'rle_date_created' => '20200102000000',
				'rle_date_updated' => '20250102000000',
				'rle_deleted' => 0,
			],
		] );

		$this->apiParams['project'] = 'https://remote.example.org';
		$this->apiParams['title'] = 'Remote#Title';
		$result = $this->doApiRequestWithToken( $this->apiParams, null, $this->user );
		$this->assertEquals( "Success", $result[0]['deleteentry']['result'] );

		$deleted = $this->getDb()->newSelectQueryBuilder()
			->select( 'rle_deleted' )
			->from( 'reading_list_entry' )
			->where( [ 'rle_id' => $entryIds[0] ] )
			->caller( __METHOD__ )
			->fetchField();
		$this->assertSame( '1', $deleted );
	}

	protected function tearDown(): void {
		$this->readingListsTeardown();
		parent::tearDown();
	}

	private function getLocalProject(): string {
		$urlUtils = MediaWikiServices::getInstance()->getUrlUtils();
		$parts = $urlUtils->parse( $urlUtils->getCanonicalServer() );
		$parts['port'] = null;
		return $urlUtils->assemble( $parts );
	}
}
