<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Integration\Maintenance;

use MediaWiki\Extension\ReadingLists\Maintenance\PopulateProjectsFromSiteMatrix;
use MediaWiki\Extension\SiteMatrix\SiteMatrix;

class TestablePopulateProjectsFromSiteMatrix extends PopulateProjectsFromSiteMatrix {

	public bool $isTesting = false;

	private SiteMatrix $siteMatrix;

	public function __construct( SiteMatrix $siteMatrix ) {
		$this->siteMatrix = $siteMatrix;
		parent::__construct();
	}

	protected function getSiteMatrix(): SiteMatrix {
		return $this->siteMatrix;
	}
}
