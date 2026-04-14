<?php

namespace MediaWiki\Extension\ReadingLists\Tests;

use MediaWiki\Extension\ReadingLists\ReadingListRepository;
use MediaWiki\MediaWikiServices;
use MediaWikiIntegrationTestCase;
use Wikimedia\Rdbms\LBFactory;

/**
 * @group Database
 * @covers \MediaWiki\Extension\ReadingLists\ReadingListEntryTitleMigrationService
 */
class ReadingListEntryTitleMigrationServiceTest extends MediaWikiIntegrationTestCase {

	use ReadingListsTestHelperTrait;

	/** @var LBFactory */
	private $lbFactory;

	public function setUp(): void {
		parent::setUp();
		$this->lbFactory = $this->getServiceContainer()->getDBLoadBalancerFactory();
	}

	private function getReadingListRepository( ?int $centralId = null ): ReadingListRepository {
		return new ReadingListRepository( $centralId, $this->lbFactory );
	}

	public function testMigrateNormalizeEntryTitles_spaceOnlyUpdatesTitle() {
		$this->addProjects( [ 'dummy' ] );
		$urlUtils = MediaWikiServices::getInstance()->getUrlUtils();
		$parts = $urlUtils->parse( $urlUtils->getCanonicalServer() );
		$parts['port'] = null;
		$localProject = $urlUtils->assemble( $parts );
		$this->addProjects( [ $localProject ] );
		[ $listId ] = $this->addLists( 1, [
			[
				'rl_name' => 'migrate-space',
				'rl_deleted' => '0',
			],
		] );
		[ $entryId ] = $this->addListEntries( $listId, 1, [
			[
				'rlp_project' => $localProject,
				'rle_title' => 'Foo Bar',
				'rle_date_created' => '20100101000000',
				'rle_date_updated' => '20150101000000',
				'rle_deleted' => 0,
			],
		] );

		$repository = $this->getReadingListRepository( 1 );
		$stats = $repository->migrateNormalizeEntryTitles( null, 50 );

		$this->assertSame( 1, $stats['updated'] );
		$this->assertSame( 0, $stats['soft_deleted'] );
		$this->assertSame( 0, $stats['blocked_by_soft_deleted'] );

		$row = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'rle_title', 'rle_deleted' ] )
			->from( 'reading_list_entry' )
			->where( [ 'rle_id' => $entryId ] )
			->caller( __METHOD__ )->fetchRow();
		$this->assertSame( 'Foo_Bar', $row->rle_title );
		$this->assertSame( '0', $row->rle_deleted );

		$rlSize = $this->getDb()->newSelectQueryBuilder()
			->select( 'rl_size' )
			->from( 'reading_list' )
			->where( [ 'rl_id' => $listId ] )
			->caller( __METHOD__ )->fetchField();
		$this->assertSame( '1', $rlSize );
	}

	public function testMigrateNormalizeEntryTitles_softDeletesSpaceDuplicate() {
		$this->addProjects( [ 'dummy' ] );
		$urlUtils = MediaWikiServices::getInstance()->getUrlUtils();
		$parts = $urlUtils->parse( $urlUtils->getCanonicalServer() );
		$parts['port'] = null;
		$localProject = $urlUtils->assemble( $parts );
		$this->addProjects( [ $localProject ] );
		[ $listId ] = $this->addLists( 1, [
			[
				'rl_name' => 'migrate-dup',
				'rl_deleted' => '0',
			],
		] );
		[ $spaceEntryId, $underscoreEntryId ] = $this->addListEntries( $listId, 1, [
			[
				'rlp_project' => $localProject,
				'rle_title' => 'Foo Bar',
				'rle_date_created' => '20100101000000',
				'rle_date_updated' => '20150101000000',
				'rle_deleted' => 0,
			],
			[
				'rlp_project' => $localProject,
				'rle_title' => 'Foo_Bar',
				'rle_date_created' => '20100101000000',
				'rle_date_updated' => '20150101000000',
				'rle_deleted' => 0,
			],
		] );

		$repository = $this->getReadingListRepository( 1 );
		$stats = $repository->migrateNormalizeEntryTitles( null, 50 );

		$this->assertSame( 0, $stats['updated'] );
		$this->assertSame( 1, $stats['soft_deleted'] );
		$this->assertSame( 0, $stats['blocked_by_soft_deleted'] );

		$rows = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'rle_id', 'rle_title', 'rle_deleted' ] )
			->from( 'reading_list_entry' )
			->where( [ 'rle_id' => [ $spaceEntryId, $underscoreEntryId ] ] )
			->caller( __METHOD__ )->fetchResultSet();
		$byId = [];
		foreach ( $rows as $row ) {
			$byId[(int)$row->rle_id] = $row;
		}
		$this->assertSame( '1', $byId[$spaceEntryId]->rle_deleted );
		$this->assertSame( 'Foo Bar', $byId[$spaceEntryId]->rle_title );
		$this->assertSame( '0', $byId[$underscoreEntryId]->rle_deleted );
		$this->assertSame( 'Foo_Bar', $byId[$underscoreEntryId]->rle_title );

		$rlSize = $this->getDb()->newSelectQueryBuilder()
			->select( 'rl_size' )
			->from( 'reading_list' )
			->where( [ 'rl_id' => $listId ] )
			->caller( __METHOD__ )->fetchField();
		$this->assertSame( '1', $rlSize );
	}

	public function testMigrateNormalizeEntryTitles_skipsSoftDeletedBlocker() {
		$this->addProjects( [ 'dummy' ] );
		$urlUtils = MediaWikiServices::getInstance()->getUrlUtils();
		$parts = $urlUtils->parse( $urlUtils->getCanonicalServer() );
		$parts['port'] = null;
		$localProject = $urlUtils->assemble( $parts );
		$this->addProjects( [ $localProject ] );
		[ $listId ] = $this->addLists( 1, [
			[
				'rl_name' => 'migrate-blocker',
				'rl_deleted' => '0',
			],
		] );
		[ $tombstoneId, $activeSpaceId ] = $this->addListEntries( $listId, 1, [
			[
				'rlp_project' => $localProject,
				'rle_title' => 'Foo_Bar',
				'rle_date_created' => '20100101000000',
				'rle_date_updated' => '20150101000000',
				'rle_deleted' => 1,
			],
			[
				'rlp_project' => $localProject,
				'rle_title' => 'Foo Bar',
				'rle_date_created' => '20100101000000',
				'rle_date_updated' => '20150101000000',
				'rle_deleted' => 0,
			],
		] );

		$this->getDb()->newUpdateQueryBuilder()
			->update( 'reading_list' )
			->set( [ 'rl_size' => 1 ] )
			->where( [ 'rl_id' => $listId ] )
			->caller( __METHOD__ )->execute();

		$repository = $this->getReadingListRepository( 1 );
		$stats = $repository->migrateNormalizeEntryTitles( null, 50 );

		$this->assertSame( 0, $stats['updated'] );
		$this->assertSame( 0, $stats['soft_deleted'] );
		$this->assertSame( 1, $stats['blocked_by_soft_deleted'] );
		$this->assertSame( 1, $stats['skipped'] );

		$tombstoneRow = $this->getDb()->newSelectQueryBuilder()
			->select( 'rle_id' )
			->from( 'reading_list_entry' )
			->where( [ 'rle_id' => $tombstoneId ] )
			->caller( __METHOD__ )->fetchField();
		$this->assertSame( (string)$tombstoneId, $tombstoneRow );

		$activeRow = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'rle_title', 'rle_deleted' ] )
			->from( 'reading_list_entry' )
			->where( [ 'rle_id' => $activeSpaceId ] )
			->caller( __METHOD__ )->fetchRow();
		$this->assertSame( 'Foo Bar', $activeRow->rle_title );
		$this->assertSame( '0', $activeRow->rle_deleted );

		$rlSize = $this->getDb()->newSelectQueryBuilder()
			->select( 'rl_size' )
			->from( 'reading_list' )
			->where( [ 'rl_id' => $listId ] )
			->caller( __METHOD__ )->fetchField();
		$this->assertSame( '1', $rlSize );
	}

	public function testMigrateNormalizeEntryTitles_dryRunDoesNotWrite() {
		$this->addProjects( [ 'dummy' ] );
		$urlUtils = MediaWikiServices::getInstance()->getUrlUtils();
		$parts = $urlUtils->parse( $urlUtils->getCanonicalServer() );
		$parts['port'] = null;
		$localProject = $urlUtils->assemble( $parts );
		$this->addProjects( [ $localProject ] );
		[ $listId ] = $this->addLists( 1, [
			[
				'rl_name' => 'migrate-dry',
				'rl_deleted' => '0',
			],
		] );
		[ $entryId ] = $this->addListEntries( $listId, 1, [
			[
				'rlp_project' => $localProject,
				'rle_title' => 'Foo Bar',
				'rle_date_created' => '20100101000000',
				'rle_date_updated' => '20150101000000',
				'rle_deleted' => 0,
			],
		] );

		$repository = $this->getReadingListRepository( 1 );
		$stats = $repository->migrateNormalizeEntryTitles( null, 50, true, null );

		$this->assertSame( 1, $stats['updated'] );

		$row = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'rle_title' ] )
			->from( 'reading_list_entry' )
			->where( [ 'rle_id' => $entryId ] )
			->caller( __METHOD__ )->fetchRow();
		$this->assertSame( 'Foo Bar', $row->rle_title );
	}

	public function testMigrateNormalizeEntryTitles_limitProcessesFirstRowsOnly() {
		$this->addProjects( [ 'dummy' ] );
		$urlUtils = MediaWikiServices::getInstance()->getUrlUtils();
		$parts = $urlUtils->parse( $urlUtils->getCanonicalServer() );
		$parts['port'] = null;
		$localProject = $urlUtils->assemble( $parts );
		$this->addProjects( [ $localProject ] );
		[ $listId ] = $this->addLists( 1, [
			[
				'rl_name' => 'migrate-limit',
				'rl_deleted' => '0',
			],
		] );
		[ $firstId, $secondId ] = $this->addListEntries( $listId, 1, [
			[
				'rlp_project' => $localProject,
				'rle_title' => 'One Two',
				'rle_date_created' => '20100101000000',
				'rle_date_updated' => '20150101000000',
				'rle_deleted' => 0,
			],
			[
				'rlp_project' => $localProject,
				'rle_title' => 'Three Four',
				'rle_date_created' => '20100101000000',
				'rle_date_updated' => '20150101000000',
				'rle_deleted' => 0,
			],
		] );

		$repository = $this->getReadingListRepository( 1 );
		$stats = $repository->migrateNormalizeEntryTitles( null, 50, false, 1 );

		$this->assertSame( 1, $stats['updated'] );

		$first = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'rle_title' ] )
			->from( 'reading_list_entry' )
			->where( [ 'rle_id' => $firstId ] )
			->caller( __METHOD__ )->fetchRow();
		$second = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'rle_title' ] )
			->from( 'reading_list_entry' )
			->where( [ 'rle_id' => $secondId ] )
			->caller( __METHOD__ )->fetchRow();

		$this->assertSame( 'One_Two', $first->rle_title );
		$this->assertSame( 'Three Four', $second->rle_title );
	}
}
