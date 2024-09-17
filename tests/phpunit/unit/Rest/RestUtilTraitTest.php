<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Unit\Rest;

use MediaWiki\Extension\ReadingLists\Rest\RestUtilTrait;
use MediaWiki\Message\Message;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Rest\ResponseFactory;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiUnitTestCase;
use Throwable;
use Wikimedia\Message\ITextFormatter;
use Wikimedia\Message\MessageSpecifier;
use Wikimedia\Message\MessageValue;

/**
 * TODO: add additional tests for functions that test parameter combinations
 *
 * @covers \MediaWiki\Extension\ReadingLists\Rest\RestUtilTrait
 */
class RestUtilTraitTest extends MediaWikiUnitTestCase {
	use HandlerTestTrait;

	private function getHandler(): Handler {
		$formatter = new class() implements ITextFormatter {
			public function getLangCode() {
				return 'en';
			}

			public function format( MessageSpecifier $message ): string {
				return 'Formatted test error message';
			}
		};
		$responseFactory = new ResponseFactory( [ 'qqx' => $formatter ] );

		$handler = new class( $responseFactory ) extends Handler {
			// As of v2024.1.1, PhpStorm doesn't like this syntax, but PHP itself is fine with it.
			use RestUtilTrait {
				RestUtilTrait::die as public publicDie;
				RestUtilTrait::dieIf as public publicDieIf;
			}

			private ResponseFactory $testResponseFactory;

			public function __construct( ResponseFactory $rf ) {
				$this->testResponseFactory = $rf;
			}

			/**
			 * This is abstract in Handler and therefore must be implemented.
			 * It is never called in the tests, and can therefore be a no-op.
			 */
			public function execute() {
			}

			public function getResponseFactory(): ResponseFactory {
				return $this->testResponseFactory;
			}
		};

		$request = new RequestData( [ 'queryParams' => [] ] );
		$config = [
			'path' => '/foo'
		];
		$this->initHandler( $handler, $request, $config );

		return $handler;
	}

	/**
	 * @dataProvider provideDie
	 */
	public function testDie( $fnParams, $expected ) {
		$handler = $this->getHandler();

		// Don't use expectException(). We need to inspect the exception details.
		try {
			$handler->publicDie(
				$fnParams['msg'], $fnParams['params'], $fnParams['code'], $fnParams['errorData']
			);
		} catch ( LocalizedHttpException $e ) {
			$this->assertEquals( $expected['code'], $e->getCode() );
			$this->assertIsArray( $e->getErrorData() );
		} catch ( Throwable $e ) {
			// If we get here, this will fail. We're just using it to generate the message.
			$this->assertInstanceOf( LocalizedHttpException::class, $e );
		}
	}

	public static function provideDie() {
		return [
			[
				[
					'msg' => 'apierror-invalidparammix',
					'params' => [ 'foo', 'bar' ],
					'code' => 400,
					'errorData' => [ 'baz' => 'qux' ]
				],
				[
					'code' => 400,
					'errorData' => [ 'baz' => 'qux' ]
				]
			],
			[
				[
					'msg' => MessageValue::new( 'apierror-invalidparammix' )
						->textListParams( [ 'foo', 'bar' ] )
						->numParams( 2 ),
					'params' => [],
					'code' => 404,
					'errorData' => []
				],
				[
					'code' => 404,
					'errorData' => []
				]
			],
			[
				[
					'msg' => new Message( 'apierror-invalidparammix', [ 'foo', 'bar' ] ),
					'params' => [],
					'method' => 'get',
					'code' => 500,
					'errorData' => []
				],
				[
					'code' => 500,
					'errorData' => []
				]
			]
		];
	}

	public function testDieIf() {
		$msg = 'apierror-invalidparammix';
		$params = [ 'foo', 'bar' ];
		$code = 400;

		$handler = $this->getHandler();

		// Ensure we do not die if $condition is false.
		try {
			$handler->publicDieIf( false, $msg, $params, $code );
		} catch ( Throwable $e ) {
			$this->fail( $e->getMessage() );
		}

		// Ensure we die if $condition is true.
		$this->expectException( LocalizedHttpException::class );
		$handler->publicDieIf( true, $msg, $params, $code );
	}
}
