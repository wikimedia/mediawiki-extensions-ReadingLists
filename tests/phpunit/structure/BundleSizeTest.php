<?php

namespace MediaWiki\Extension\ReadingLists\Tests;

/**
 * @group ReadingLists
 */
class BundleSizeTest extends \MediaWiki\Tests\Structure\BundleSizeTestBase {

	/** @inheritDoc */
	public static function getBundleSizeConfigData(): string {
		return dirname( __DIR__, 3 ) . '/bundlesize.config.json';
	}
}
