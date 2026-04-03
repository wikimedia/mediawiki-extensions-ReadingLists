<?php

namespace MediaWiki\Extension\ReadingLists\Tests;

use MediaWiki\Extension\ReadingLists\ReadingListEntryTitleNormalizer;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\ReadingLists\ReadingListEntryTitleNormalizer
 */
class ReadingListEntryTitleNormalizerTest extends MediaWikiIntegrationTestCase {

	private ReadingListEntryTitleNormalizer $titleNormalizer;

	protected function setUp(): void {
		parent::setUp();
		$this->titleNormalizer = new ReadingListEntryTitleNormalizer();
	}

	public function testNormalizeForStorageForLocalProject(): void {
		$this->assertSame(
			'Formula_one',
			$this->titleNormalizer->normalizeForStorage(
				'@local',
				'formula one',
				'https://en.wikipedia.org'
			)
		);
	}

	public function testNormalizeForStorageForCrossWikiProject(): void {
		$this->assertSame(
			'formula_one',
			$this->titleNormalizer->normalizeForStorage(
				'https://commons.wikimedia.org',
				'formula one',
				'https://en.wikipedia.org'
			)
		);
	}
}
