<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Integration\Maintenance;

use MediaWiki\Extension\ReadingLists\Maintenance\PopulateProjectsFromSiteMatrix;
use MediaWiki\Extension\SiteMatrix\SiteMatrix;

class TestablePopulateProjectsFromSiteMatrix extends PopulateProjectsFromSiteMatrix {

	public bool $isTesting = false;

	public function __construct(
		private readonly SiteMatrix $siteMatrix,
	) {
		parent::__construct();
	}

	protected function getSiteMatrix(): SiteMatrix {
		return $this->siteMatrix;
	}
}
