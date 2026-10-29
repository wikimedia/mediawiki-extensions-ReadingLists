<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Api;

use MediaWiki\Api\ApiUsageException;
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
	 * @dataProvider provideCreateModes
	 */
	public function testCreateWithRejectDuplicates( bool $batch ) {
		$params = [ 'name' => 'cats', 'description' => 'Meow!' ];
		if ( $batch ) {
			$params = [ 'batch' => json_encode( [ $params ] ) ];
		}
		$params['rejectduplicates'] = true;
		$result = $this->doApiRequestWithToken( $this->apiParams + $params, null, $this->user );
		$list = $batch ? $result[0]['create']['lists'][0] : $result[0]['create']['list'];

		$this->assertSame( 'Success', $result[0]['create']['result'] );
		$this->assertSame( 'cats', $list['name'] );
		$this->assertSame( 'Meow!', $list['description'] );
		$this->assertFalse( $list['duplicate'] );
	}

	/**
	 * @dataProvider provideCreateModes
	 */
	public function testRejectDuplicatePreservesExistingList( bool $batch ) {
		[ $listId ] = $this->addLists( $this->user->getId(), [ [
			'rl_name' => 'cats',
			'rl_description' => 'Meow!',
			'rl_date_created' => '20240101120000',
			'rl_date_updated' => '20240101120000',
		] ] );
		$repository = $this->getServiceContainer()->getService( 'ReadingLists.ReadingListRepositoryFactory' )
			->create( $this->user->getId() );
		$originalList = $repository->selectValidList( $listId );
		$params = [ 'name' => 'cats' ];
		if ( $batch ) {
			$params = [ 'batch' => json_encode( [ $params ] ) ];
		}
		$params['rejectduplicates'] = true;

		$this->assertApiUsage( 'readinglists-db-error-duplicate-list',
			fn () => $this->doApiRequestWithToken( $this->apiParams + $params, null, $this->user )
		);

		$this->assertEquals( $originalList, $repository->selectValidList( $listId ) );
	}

	/**
	 * @dataProvider provideCreateModes
	 */
	public function testCreateMergesDuplicatesByDefault( bool $batch ) {
		$original = $this->doApiRequestWithToken(
			$this->apiParams + [ 'name' => 'cats', 'description' => 'Meow!' ], null, $this->user
		);
		$params = [ 'name' => 'cats' ];
		if ( $batch ) {
			$params = [ 'batch' => json_encode( [ $params ] ) ];
		}

		$result = $this->doApiRequestWithToken( $this->apiParams + $params, null, $this->user );
		$list = $batch ? $result[0]['create']['lists'][0] : $result[0]['create']['list'];

		$this->assertSame( 'Success', $result[0]['create']['result'] );
		$this->assertSame( $original[0]['create']['list']['id'], $list['id'] );
		$this->assertTrue( $list['duplicate'] );
		$this->assertSame( '', $list['description'] );
	}

	public static function provideCreateModes() {
		return [
			'single' => [ false ],
			'batch' => [ true ],
		];
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

	/**
	 * @dataProvider provideBlankNameParams
	 */
	public function testCreateBlankName( array $params ) {
		$this->expectException( ApiUsageException::class );
		$this->expectExceptionMessage( 'The list name cannot be empty or contain only whitespace.' );
		$this->doApiRequestWithToken( $this->apiParams + $params, null, $this->user );
	}

	public static function provideBlankNameParams() {
		return [
			'empty name' => [ [ 'name' => '' ] ],
			'whitespace name' => [ [ 'name' => " \t\n " ] ],
			'empty name in batch' => [ [ 'batch' => json_encode( [ [ 'name' => '' ] ] ) ] ],
			'whitespace name in batch' => [ [ 'batch' => json_encode( [ [ 'name' => '   ' ] ] ) ] ],
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
