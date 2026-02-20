<?php

namespace MediaWiki\Extension\ReadingLists\Tests;

use MediaWiki\Extension\ReadingLists\HookHandler;

/**
 * @covers \MediaWiki\Extension\ReadingLists\HookHandler
 */
class HookHandlerTest extends \MediaWikiUnitTestCase {

	/**
	 * @dataProvider provideIsSkinSupported
	 */
	public function testIsSkinSupported( string $skinName, bool $expected ) {
		$this->assertSame( $expected, HookHandler::isSkinSupported( $skinName ) );
	}

	public static function provideIsSkinSupported(): array {
		return [
			'vector-2022 is supported' => [ 'vector-2022', true ],
			'minerva is supported' => [ 'minerva', true ],
			'cologneblue is not supported' => [ 'cologneblue', false ],
			'modern is not supported' => [ 'modern', false ],
			'monobook is not supported' => [ 'monobook', false ],
			'timeless is not supported' => [ 'timeless', false ],
			'vector (legacy) is not supported' => [ 'vector', false ],
			'empty string is not supported' => [ '', false ],
		];
	}
}
