<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Unit\Rest;

use MediaWiki\Extension\ReadingLists\Rest\ReadingListsTokenAwareHandlerTrait;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\TokenAwareHandlerTrait;
use MediaWiki\Session\Session;
use MediaWiki\Session\SessionProvider;
use MediaWiki\Session\Token;
use MediaWiki\User\User;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\ReadingLists\Rest\ReadingListsTokenAwareHandlerTrait
 */
class ReadingListsTokenAwareHandlerTraitTest extends MediaWikiUnitTestCase {

	/**
	 * @dataProvider provideGetToken
	 */
	public function testGetToken(
		array $params,
		array $body,
		?string $secret,
		bool $safeAgainstCsrf,
		?string $expected
	) {
		$handler = $this->getHandler( $params, $body, $secret, $safeAgainstCsrf );
		$this->assertEquals( $expected, $handler->getToken() );
	}

	public static function provideGetToken() {
		$secret = 'foo';
		$token = strval( new Token( $secret, '' ) );

		foreach ( [
					  'missing token, not CSRF-safe' => [
						  'params' => [],
						  'body' => [],
						  'expected' => '',
					  ],
					  'missing token, CSRF-safe' => [
						  'params' => [],
						  'body' => [],
						  'safeAgainstCsrf' => true,
						  'expected' => null,
					  ],
					  'body token, not CSRF-safe' => [
						  'params' => [],
						  'body' => [ 'token' => $token ],
						  'expected' => $token,
					  ],
					  'body token, CSRF-safe' => [
						  'params' => [],
						  'body' => [ 'token' => $token ],
						  'safeAgainstCsrf' => true,
						  'expected' => null,
					  ],
					  'param token, not CSRF-safe' => [
						  'params' => [ 'csrf_token' => $token ],
						  'body' => [],
						  'expected' => $token,
					  ],
					  'param token, CSRF-safe' => [
						  'params' => [ 'csrf_token' => $token ],
						  'body' => [],
						  'safeAgainstCsrf' => true,
						  'expected' => null,
					  ],
					  'body and param tokens, not CSRF-safe' => [
						  'params' => [ 'csrf_token' => $token ],
						  'body' => [ 'token' => $token ],
						  'expected' => $token,
					  ],
					  'body and param tokens, CSRF-safe' => [
						  'params' => [ 'csrf_token' => $token ],
						  'body' => [ 'token' => $token ],
						  'safeAgainstCsrf' => true,
						  'expected' => null,
					  ],
				  ] as $name => $test ) {
			yield $name => array_merge( [
				'params' => null,
				'body' => null,
				'secret' => $secret,
				'safeAgainstCsrf' => false,
				'expected' => null,
			], $test );
		}
	}

	private function getHandler(
		array $params,
		array $body,
		?string $secret,
		bool $safeAgainstCsrf
	) {
		$session = $this->createNoOpMock( Session::class,
			[ 'getProvider', 'isPersistent', 'hasToken', 'getToken', 'getUser' ] );
		$sessionProvider = $this->createNoOpMock( SessionProvider::class, [ 'safeAgainstCsrf' ] );
		$sessionProvider->method( 'safeAgainstCsrf' )->willReturn( $safeAgainstCsrf );
		$session->method( 'getProvider' )->willReturn( $sessionProvider );
		$session->method( 'isPersistent' )->willReturn( true );
		$session->method( 'hasToken' )->willReturn( $secret !== null );
		$session->method( 'getToken' )->willReturn( new Token( $secret, '' ) );
		$user = $this->createNoOpMock( User::class, [ 'isAnon' ] );
		$session->method( 'getUser' )->willReturn( $user );

		// PHPUnit can't mock a class and a trait at the same time
		return new class( $session, $params, $body ) extends Handler {
			use TokenAwareHandlerTrait, ReadingListsTokenAwareHandlerTrait {
				ReadingListsTokenAwareHandlerTrait::getToken as public;
				ReadingListsTokenAwareHandlerTrait::getToken insteadof TokenAwareHandlerTrait;
			}

			private Session $session;
			private array $validatedParams;
			private array $validatedBody;

			public function __construct( Session $session, array $validatedParams, array $validatedBody ) {
				$this->session = $session;
				$this->validatedParams = $validatedParams;
				$this->validatedBody = $validatedBody;
			}

			public function execute() {
			}

			public function getSession(): Session {
				return $this->session;
			}

			public function getValidatedParams() {
				return $this->validatedParams;
			}

			public function getValidatedBody() {
				return $this->validatedBody;
			}
		};
	}

}
