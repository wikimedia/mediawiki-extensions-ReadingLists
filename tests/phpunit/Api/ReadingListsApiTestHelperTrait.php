<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Api;

use MediaWiki\Extension\ReadingLists\Tests\ReadingListsTestHelperTrait;
use MediaWiki\User\User;

/**
 * @mixin \MediaWiki\Tests\Api\ApiTestCase
 */
trait ReadingListsApiTestHelperTrait {
	use ReadingListsTestHelperTrait;

	private function readingListsSetup( User $user ): int {
		$this->overrideConfigValue( 'CentralIdLookupProvider', 'local' );

		$apiParams['command'] = 'setup';
		$apiParams['action']  = 'readinglists';
		$apiParams['format']  = 'json';
		$this->addProjects( [ 'test' ] );
		$result = $this->doApiRequestWithToken( $apiParams, null, $user );
		return $result[0]['setup']['list']['id'];
	}

	private function readingListsTeardown( User $user ): void {
		$apiParams['command'] = 'teardown';
		$apiParams['action']  = 'readinglists';
		$apiParams['format']  = 'json';
		$this->doApiRequestWithToken( $apiParams, null, $user );
	}
}
