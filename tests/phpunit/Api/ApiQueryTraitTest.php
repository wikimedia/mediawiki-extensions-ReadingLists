<?php

namespace MediaWiki\Extensions\ReadingLists\Tests\Api;

use ApiMessage;
use ApiUsageException;
use FauxRequest;
use MediaWiki\Extensions\ReadingLists\Api\ApiQueryReadingListEntries;
use MediaWiki\Extensions\ReadingLists\Api\ApiQueryReadingLists;
use MediaWiki\Extensions\ReadingLists\ReadingListRepository;
use MediaWiki\Extensions\ReadingLists\Tests\ReadingListsTestHelperTrait;
use PHPUnit\Framework\TestCase;
use RequestContext;
use SebastianBergmann\Exporter\Exporter;
use StatusValue;
use Title;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \MediaWiki\Extensions\ReadingLists\Api\ApiQueryTrait
 * @group  medium
 * @group  API
 */
class ApiQueryTraitTest extends TestCase {

	use ReadingListsTestHelperTrait;

	/** @var ApiTrait */
	private $rlApi;
	/** @var ApiQueryReadingListEntries */
	private $rleApi;

	public function setUp(): void {
		$request = new FauxRequest();
		$context = RequestContext::newExtraneousContext( Title::newMainPage() );
		$context->setRequest( $request );
		$this->rlApi = $this->getMockBuilder( ApiQueryReadingLists::class )
			->onlyMethods( [ 'getContext', 'dieContinueUsageIf', 'dieWithError', 'encodeParamName' ] )
			->disableOriginalConstructor()
			->getMock();
		$this->rlApi->expects( $this->any() )
			->method( 'getContext' )
			->willReturn( $context );
		$this->rlApi->expects( $this->any() )
			->method( 'dieWithError' )
			->willReturnCallback( static function ( $msg ) {
				throw new ApiUsageException( null,
					StatusValue::newFatal( ApiMessage::create( $msg ) ) );
			} );
		$this->rlApi->expects( $this->any() )
			->method( 'dieWithError' )
			->willReturnCallback( function ( $condition ) {
				if ( $condition ) {
					$this->dieWithError( 'apierror-badcontinue' );
				}
			} );
		$this->rlApi->expects( $this->any() )
			->method( 'encodeParamName' )
			->willReturnArgument( 0 );
		// Mock Reading Lists Entries API to test encoded continuation parameter
		$this->rleApi = $this->getMockBuilder( ApiQueryReadingListEntries::class )
			->disableOriginalConstructor()
			->getMock();
	}

	/**
	 * @dataProvider encodeContinuationParameterProvider
	 */
	public function testEncodeContinuationParameter( $prefix, $item, $mode, $sort, $expected ) {
		$this->rlApi->prefix = $prefix;
		if ( $prefix === 'rl' ) {
			$encodedParam = $this->assertApiUsage( null,
				[ TestingAccessWrapper::newFromObject( $this->rlApi ), 'encodeContinuationParameter' ],
				[ $item, $mode, $sort ] );
		} else {
			$encodedParam = $this->assertApiUsage( null,
				[ TestingAccessWrapper::newFromObject( $this->rleApi ), 'encodeContinuationParameter' ],
				[ $item, $mode, $sort ] );
		}

		$this->assertEquals( $expected, $encodedParam );
	}

	public function encodeContinuationParameterProvider() {
		$rlItem = [ 'id' => 1, 'name' => 'foo', 'updated' => '2018-12-01T00:00:00Z' ];

		$rleItem = [ 'id' => 2, 'title' => 'Foo', 'updated' => '2018-12-01T00:00:00Z' ];

		return [
			[ 'rl', $rlItem, 'page', ReadingListRepository::SORT_BY_NAME, '1' ],
			[ 'rl', $rlItem, null, ReadingListRepository::SORT_BY_NAME, 'foo|1' ],
			[ 'rl', $rlItem, null, ReadingListRepository::SORT_BY_UPDATED, '2018-12-01T00:00:00Z|1' ],
			[ 'rle', $rleItem, null, ReadingListRepository::SORT_BY_NAME, 'Foo|2' ],
		];
	}

	/**
	 * @dataProvider decodeContinuationParameterProvider
	 */
	public function testDecodeContinuationParameter( $continue, $mode, $sort, $expected ) {
		$encodedParam = $this->assertApiUsage( null,
			[ TestingAccessWrapper::newFromObject( $this->rlApi ), 'decodeContinuationParameter' ],
			[ $continue, $mode, $sort ] );
		if ( $encodedParam !== null ) {
			$this->assertEquals( $expected, $encodedParam );
		}
	}

	public function decodeContinuationParameterProvider() {
		return [
			[ '1' , 'page', ReadingListRepository::SORT_BY_NAME, 1 ],
			[ 'foo|1' , null, ReadingListRepository::SORT_BY_NAME, [ 'foo', 1 ] ],
			[ '2018-12-01T00:00:00Z|1' , null, ReadingListRepository::SORT_BY_UPDATED,
				[ '2018-12-01T00:00:00Z', 1 ] ],
			[ 'Foo|2' , null, ReadingListRepository::SORT_BY_NAME, [ 'Foo', 2 ] ],
		];
	}

	public function testGetAllowedSortParams() {
		$expected = [ 'sort' => [], 'dir' => [], 'limit' => [], 'continue' => [] ];
		$actual = $this->assertApiUsage( null,
			[ TestingAccessWrapper::newFromObject( $this->rlApi ), 'getAllowedSortParams' ] );
		$this->assertIsArray( $actual );
		$this->assertSubset( $expected, $actual );
	}

	/**
	 * Non-crappy implementation of assertArraySubset.
	 * @param array $subset
	 * @param array $array
	 */
	private function assertSubset( array $subset, array $array ) {
		foreach ( $array as $key => $value ) {
			if ( is_numeric( $key ) ) {
				$pos = array_search( $value, $subset, true );
				if ( $pos !== false && is_numeric( $pos ) ) {
					unset( $subset[$pos] );
				}
			} else {
				unset( $subset[$key] );
			}
		}
		if ( $subset ) {
			$exporter = new Exporter();
			$this->fail( 'Failed asserting that ' . $exporter->export( $subset ) . ' is a subset of '
				. $exporter->export( $array ) );
		}
	}

}
