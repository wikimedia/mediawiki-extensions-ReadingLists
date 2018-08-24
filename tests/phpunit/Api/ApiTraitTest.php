<?php

namespace MediaWiki\Extensions\ReadingLists\Api;

use ApiMessage;
use ApiUsageException;
use FauxRequest;
use PHPUnit\Framework\TestCase;
use RequestContext;
use StatusValue;
use Title;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \MediaWiki\Extensions\ReadingLists\Api\ApiTrait
 */
class ApiTraitTest extends TestCase {
	/** @var ApiTrait */
	private $api;

	public function setUp() {
		$request = new FauxRequest();
		$context = RequestContext::newExtraneousContext( Title::newMainPage() );
		$context->setRequest( $request );
		$this->api = $this->getMockBuilder( ApiTrait::class )
			->setMethods( [ 'getContext', 'dieWithError', 'encodeParamName' ] )
			->disableOriginalConstructor()
			->getMockForTrait();
		$this->api->expects( $this->any() )
			->method( 'getContext' )
			->willReturn( $context );
		$this->api->expects( $this->any() )
			->method( 'dieWithError' )
			->willReturnCallback( function ( $msg ) {
				throw new ApiUsageException( null,
					StatusValue::newFatal( ApiMessage::create( $msg ) ) );
			} );
		$this->api->expects( $this->any() )
			->method( 'encodeParamName' )
			->willReturnArgument( 0 );
	}

	/**
	 * @dataProvider provideGetBatchOps
	 */
	public function testGetBatchOps( $rawBatch, $expectedBatch, $errorMessage ) {
		$batch = $this->assertApiUsage( $errorMessage,
			[ TestingAccessWrapper::newFromObject( $this->api ), 'getBatchOps' ], [ $rawBatch ] );
		if ( $batch !== null ) {
			$this->assertSame( $expectedBatch, $batch );
		}
	}

	public function provideGetBatchOps() {
		$thousandObjects = '[' . implode( ',', array_fill( 0, 1000, '{"foo":"bar"}' ) ) . ']';
		return [
			// batch input as JSON string, cleaned batch, error message or null
			'invalid JSON' => [ '!', [], 'apierror-readinglists-batch-invalid-json' ],
			'not an array' => [ '1', [], 'apierror-readinglists-batch-invalid-structure' ],
			'not an array #2' => [ '{"foo":"bar"}', [], 'apierror-readinglists-batch-invalid-structure' ],
			'empty array' => [ '[]', [], 'apierror-readinglists-batch-invalid-structure' ],
			'does not contain objects' => [ '[1]', [], 'apierror-readinglists-batch-invalid-structure' ],
			'does not contain arrays #2' => [ '[["bar"]]', [],
				'apierror-readinglists-batch-invalid-structure' ],
			'objects have non-scalar values' => [ '[{"foo":[]}]', [],
				'apierror-readinglists-batch-invalid-structure' ],
			'too many items' => [ $thousandObjects, [], 'apierror-readinglists-batch-toomanyvalues' ],
			'OK' => [ '[{"foo":"bar"}]', [ [ 'foo' => 'bar' ] ], null ],
			'OK but needs to be normalized' => [ '[{"foo":"a\u0301"}]', [ [ 'foo' => 'á' ] ], null ],
		];
	}

	/**
	 * @dataProvider provideRequireAtLeastOneBatchParameter
	 */
	public function testRequireAtLeastOneBatchParameter( array $op, array $params, $errorMessage ) {
		array_unshift( $params, $op );
		$this->assertApiUsage( $errorMessage, [ TestingAccessWrapper::newFromObject( $this->api ),
				'requireAtLeastOneBatchParameter' ], $params );
	}

	public function provideRequireAtLeastOneBatchParameter() {
		$missing = 'apierror-readinglists-batch-missingparam-at-least-one-of';
		return [
			// operation, list of alternate-required fields, expected error or null
			'required parameter present' => [ [ 'foo' => 1, 'bar' => 2 ], [ 'foo' ], null ],
			'required parameter absent' => [ [ 'foo' => 1, 'bar' => 2 ], [ 'baz' ], $missing ],
			'two required parameters present' => [ [ 'foo' => 1, 'bar' => 2 ], [ 'foo', 'bar' ], null ],
			'one required parameters present' => [ [ 'foo' => 1 ], [ 'foo', 'bar' ], null ],
			'no required parameters present' => [ [ 'baz' => 1, 'boom' => 2 ], [ 'foo', 'bar' ], $missing ],
		];
	}

	/**
	 * If $expectedErrorMessage is null, verify that the callback does not throw a usage error.
	 * If it isn't, verify that it throws that error.
	 * @param string $expectedErrorMessage
	 * @param callable $callback
	 * @return mixed The return value of the callback, or null if there was an exception.
	 */
	private function assertApiUsage( $expectedErrorMessage, callable $callback, array $params = [] ) {
		try {
			$ret = call_user_func_array( $callback, $params );
		} catch ( ApiUsageException $e ) {
			$errorMessage = $e->getMessageObject()->getKey();
			if ( $expectedErrorMessage ) {
				$this->assertEquals( $expectedErrorMessage, $errorMessage,
					"Wrong exception (expected '$expectedErrorMessage', got '$errorMessage')" );
				return null;
			} else {
				$this->fail( "Unexpected exception '$errorMessage'" );
			}
		}
		if ( $expectedErrorMessage ) {
			$this->fail( "Expected exception '$errorMessage' missing" );
		}
		// Make sure there is at least one assertion so the test won't be marked risky.
		$this->assertTrue( true );
		return $ret;
	}
}
