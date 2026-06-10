<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Integration\Maintenance;

use MediaWiki\Extension\ReadingLists\Utils;
use MediaWiki\Extension\SiteMatrix\SiteMatrix;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Wikimedia\Rdbms\IDatabase;

require_once __DIR__ . '/../../../../maintenance/populateProjectsFromSiteMatrix.php';
require_once __DIR__ . '/TestablePopulateProjectsFromSiteMatrix.php';

/**
 * @covers \MediaWiki\Extension\ReadingLists\Maintenance\PopulateProjectsFromSiteMatrix
 * @group Database
 * @group ReadingLists
 */
class PopulateProjectsFromSiteMatrixTest extends MaintenanceBaseTestCase {

	/** @var SiteMatrix&MockObject */
	private $siteMatrix;

	protected function getMaintenanceClass() {
		return TestablePopulateProjectsFromSiteMatrix::class;
	}

	protected function createMaintenance() {
		$this->siteMatrix = $this->createMock( SiteMatrix::class );
		return new TestablePopulateProjectsFromSiteMatrix( $this->siteMatrix );
	}

	public function testPopulatesRegularAndSpecialSites(): void {
		$this->configureSiteMatrix();

		$this->maintenance->execute();

		$projects = $this->getProjects();
		$this->assertContains( 'https://en.wikipedia.org', $projects );
		$this->assertContains( 'https://ban.wikipedia.org', $projects );
		$this->assertNotContains( 'https://private.wikipedia.org', $projects );
		$this->assertStringContainsString( 'inserted 2 projects', $this->getActualOutputForAssertion() );
	}

	public function testDryRunListsProjectsWithoutWriting(): void {
		$this->configureSiteMatrix();
		$this->maintenance->setOption( 'dry-run', true );

		$this->maintenance->execute();

		$projects = $this->getProjects();
		$this->assertNotContains( 'https://en.wikipedia.org', $projects );
		$this->assertNotContains( 'https://ban.wikipedia.org', $projects );

		$output = $this->getActualOutputForAssertion();
		$this->assertStringContainsString( 'would insert 2 projects', $output );
		$this->assertStringContainsString( 'https://en.wikipedia.org', $output );
		$this->assertStringContainsString( 'https://ban.wikipedia.org', $output );
		$this->assertStringNotContainsString( 'https://private.wikipedia.org', $output );
	}

	public function testExistingProjectsAreNotReportedByDryRun(): void {
		$this->configureSiteMatrix();
		$this->insertProject( 'https://en.wikipedia.org' );
		$this->maintenance->setOption( 'dry-run', true );

		$this->maintenance->execute();

		$output = $this->getActualOutputForAssertion();
		$this->assertStringContainsString( 'would insert 1 projects', $output );
		$this->assertStringNotContainsString( 'https://en.wikipedia.org', $output );
		$this->assertStringContainsString( 'https://ban.wikipedia.org', $output );
	}

	public function testExistingProjectsWithTrailingWhitespaceAreNotReportedByDryRun(): void {
		$this->configureSiteMatrix();
		$this->insertProject( 'https://en.wikipedia.org ' );
		$this->maintenance->setOption( 'dry-run', true );

		$this->maintenance->execute();

		$output = $this->getActualOutputForAssertion();
		$this->assertStringContainsString( 'would insert 1 projects', $output );
		$this->assertStringNotContainsString( "https://en.wikipedia.org\n", $output );
		$this->assertStringContainsString( 'https://ban.wikipedia.org', $output );
	}

	public function testCanBeRerunWithoutDuplicateInsert(): void {
		$this->configureSiteMatrix();

		$this->maintenance->execute();
		$this->maintenance->execute();

		$this->assertSame( 2, $this->getProjectCount() );
		$this->assertStringContainsString( 'inserted 0 projects', $this->getActualOutputForAssertion() );
	}

	public function testDryRunAfterPopulateDoesNotReportSameProjectsAsMissing(): void {
		$this->configureSiteMatrix();

		$this->maintenance->execute();
		$this->maintenance->setOption( 'dry-run', true );
		$this->maintenance->execute();

		$output = $this->getActualOutputForAssertion();
		$this->assertStringContainsString( 'inserted 2 projects', $output );
		$this->assertStringContainsString( 'would insert 0 projects', $output );
	}

	public function testVerboseOutputIncludesDatabaseAndInsertDiagnostics(): void {
		$this->configureSiteMatrix();
		$this->maintenance->setOption( 'verbose', true );

		$this->maintenance->execute();

		$output = $this->getActualOutputForAssertion();
		$this->assertStringContainsString( 'database domain ID:', $output );
		$this->assertStringContainsString( 'projects to insert: 2', $output );
		$this->assertStringContainsString(
			'insert https://ban.wikipedia.org affected rows: 1',
			$output
		);
		$this->assertStringContainsString( 'transaction committed; replication wait', $output );
	}

	private function configureSiteMatrix(): void {
		$this->siteMatrix->method( 'getSites' )->willReturn( [ 'wiki' ] );
		$this->siteMatrix->method( 'getLangList' )->willReturn( [ 'en' ] );
		$this->siteMatrix->method( 'exist' )->willReturnMap( [
			[ 'en', 'wiki', true ],
		] );
		$this->siteMatrix->method( 'getSpecials' )->willReturn( [
			[ 'ban', 'wiki' ],
			[ 'private', 'wiki' ],
		] );
		$this->siteMatrix->method( 'getDBName' )->willReturnMap( [
			[ 'en', 'wiki', 'enwiki' ],
			[ 'ban', 'wiki', 'banwiki' ],
			[ 'private', 'wiki', 'privatewiki' ],
		] );
		$this->siteMatrix->method( 'getCanonicalUrl' )->willReturnMap( [
			[ 'en', 'wiki', 'https://en.wikipedia.org' ],
			[ 'ban', 'wiki', 'https://ban.wikipedia.org' ],
			[ 'private', 'wiki', 'https://private.wikipedia.org' ],
		] );
		$this->siteMatrix->method( 'isPrivate' )->willReturnCallback(
			static fn ( string $dbName ): bool => $dbName === 'privatewiki'
		);
	}

	private function getDbw(): IDatabase {
		return $this->getServiceContainer()->getDBLoadBalancerFactory()->getPrimaryDatabase(
			Utils::VIRTUAL_DOMAIN
		);
	}

	private function getProjects(): array {
		return $this->getDbw()->newSelectQueryBuilder()
			->select( 'rlp_project' )
			->from( 'reading_list_project' )
			->caller( __METHOD__ )
			->fetchFieldValues();
	}

	private function getProjectCount(): int {
		return (int)$this->getDbw()->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'reading_list_project' )
			->caller( __METHOD__ )
			->fetchField();
	}

	private function insertProject( string $project ): void {
		$this->getDbw()->newInsertQueryBuilder()
			->insertInto( 'reading_list_project' )
			->row( [ 'rlp_project' => $project ] )
			->caller( __METHOD__ )
			->execute();
	}
}
