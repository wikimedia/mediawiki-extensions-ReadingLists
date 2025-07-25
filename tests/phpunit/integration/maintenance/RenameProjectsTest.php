<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Maintenance;

use MediaWiki\Extension\ReadingLists\Maintenance\RenameProjects;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;

require_once __DIR__ . '/../../../../maintenance/renameProjects.php';

/**
 * @covers \MediaWiki\Extension\ReadingLists\Maintenance\RenameProjects
 * @group Database
 * @group ReadingLists
 */
class RenameProjectsTest extends MaintenanceBaseTestCase {

	protected function getMaintenanceClass() {
		return RenameProjects::class;
	}

	protected function setUp(): void {
		parent::setUp();

		$this->insertTestProjects();
	}

	private function insertTestProjects(): void {
		$dbw = $this->getDb();
		$dbw->insert(
			'reading_list_project',
			[
				[ 'rlp_id' => 1, 'rlp_project' => 'https://ar.wikipedia.beta.wmflabs.org' ],
				[ 'rlp_id' => 2, 'rlp_project' => 'https://de.wikipedia.beta.wmflabs.org' ],
				[ 'rlp_id' => 3, 'rlp_project' => 'https://en.wikipedia.beta.wmflabs.org' ],
				[ 'rlp_id' => 4, 'rlp_project' => 'https://fr.wikipedia.beta.wmfdev.org' ],
			],
			__METHOD__
		);
	}

	public function testRenameProjects() {
		$this->maintenance->loadWithArgv( [
			'--from', 'beta.wmflabs.org',
			'--to', 'beta.wmcloud.org',
			'--batch-size', 2
		] );
		$this->maintenance->execute();

		$dbr = $this->getDb();
		$projects = $dbr->selectFieldValues(
			'reading_list_project',
			'rlp_project',
			[],
			__METHOD__
		);

		$this->assertContains( 'https://ar.wikipedia.beta.wmcloud.org', $projects );
		$this->assertContains( 'https://de.wikipedia.beta.wmcloud.org', $projects );
		$this->assertContains( 'https://en.wikipedia.beta.wmcloud.org', $projects );
		$this->assertContains( 'https://fr.wikipedia.beta.wmfdev.org', $projects );

		$this->assertNotContains( 'https://ar.wikipedia.beta.wmflabs.org', $projects );
		$this->assertNotContains( 'https://de.wikipedia.beta.wmflabs.org', $projects );
		$this->assertNotContains( 'https://en.wikipedia.beta.wmflabs.org', $projects );
	}

	public function testRenameProjectsWithFullUrl() {
		$this->maintenance->loadWithArgv( [
			'--from', 'https://ar.wikipedia.beta.wmflabs.org',
			'--to', 'https://ar.wikipedia.beta.wmcloud.org',
		] );
		$this->maintenance->execute();

		$dbr = $this->getDb();
		$projects = $dbr->selectFieldValues(
			'reading_list_project',
			'rlp_project',
			[],
			__METHOD__
		);

		$this->assertContains( 'https://ar.wikipedia.beta.wmcloud.org', $projects );
		$this->assertContains( 'https://de.wikipedia.beta.wmflabs.org', $projects );
		$this->assertContains( 'https://en.wikipedia.beta.wmflabs.org', $projects );
		$this->assertContains( 'https://fr.wikipedia.beta.wmfdev.org', $projects );

		$this->assertNotContains( 'https://ar.wikipedia.beta.wmflabs.org', $projects );
	}

	public function testRenameProjectsWithDryRun() {
		$this->maintenance->loadWithArgv( [
			'--from', 'beta.wmflabs.org',
			'--to', 'beta.wmcloud.org',
			'--dry-run'
		] );
		$this->maintenance->execute();

		$dbr = $this->getDb();
		$projects = $dbr->selectFieldValues(
			'reading_list_project',
			'rlp_project',
			[],
			__METHOD__
		);

		$this->assertContains( 'https://ar.wikipedia.beta.wmflabs.org', $projects );
		$this->assertContains( 'https://de.wikipedia.beta.wmflabs.org', $projects );
		$this->assertContains( 'https://en.wikipedia.beta.wmflabs.org', $projects );
		$this->assertContains( 'https://fr.wikipedia.beta.wmfdev.org', $projects );

		$this->assertNotContains( 'https://ar.wikipedia.beta.wmcloud.org', $projects );
		$this->assertNotContains( 'https://de.wikipedia.beta.wmcloud.org', $projects );
		$this->assertNotContains( 'https://en.wikipedia.beta.wmcloud.org', $projects );
	}
}
