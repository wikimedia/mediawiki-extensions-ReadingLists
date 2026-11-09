<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Integration;

use MediaWiki\MainConfigNames;
use MediaWiki\Request\FauxRequest;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Tests\Specials\SpecialPageTestBase;

/**
 * @covers \MediaWiki\Extension\ReadingLists\SpecialSaved
 */
class SpecialSavedScriptPathTest extends SpecialPageTestBase {

	protected function newSpecialPage() {
		return $this->getServiceContainer()->getSpecialPageFactory()->getPage( 'Saved' );
	}

	/**
	 * @dataProvider provideScriptPathRedirects
	 */
	public function testRedirectsFromScriptPathToArticlePath(
		?string $subPage,
		array $requestParams,
		?string $expectedSubPage,
		array $expectedQueryParams
	): void {
		$scriptPath = $this->getServiceContainer()->getMainConfig()->get( MainConfigNames::Script );
		$request = new FauxRequest( $requestParams );
		$request->setRequestURL( $scriptPath );

		[ , $response ] = $this->executeSpecialPage( $subPage, $request );

		$baseUrl = SpecialPage::getTitleFor( 'Saved', $expectedSubPage ?? false )->getLocalURL();
		$expectedUrl = $expectedQueryParams ? wfAppendQuery( $baseUrl, $expectedQueryParams ) : $baseUrl;

		$this->assertSame( 301, $response->getStatusCode() );
		$this->assertSame( $expectedUrl, $response->getHeader( 'Location' ) );
	}

	public static function provideScriptPathRedirects(): array {
		return [
			'no subpage, no query params' => [ null, [], null, [] ],
			'with limport' => [
				null,
				[ 'limport' => 'https://example.org/list.json' ],
				null,
				[ 'limport' => 'https://example.org/list.json' ],
			],
			'with lexport' => [
				null,
				[ 'lexport' => '42' ],
				null,
				[ 'lexport' => '42' ],
			],
			'with subpage' => [ '42', [], '42', [] ],
			'with subpage and limport' => [
				'42',
				[ 'limport' => 'https://example.org/list.json' ],
				'42',
				[ 'limport' => 'https://example.org/list.json' ],
			],
			'empty limport is forwarded' => [ null, [ 'limport' => '' ], null, [ 'limport' => '' ] ],
			'unrelated params are forwarded' => [ null, [ 'foo' => 'bar' ], null, [ 'foo' => 'bar' ] ],
			'title param is not forwarded' => [ null, [ 'title' => 'Special:Saved' ], null, [] ],
		];
	}
}
