<?php

namespace MediaWiki\Extension\ReadingLists\Tests\Integration\Maintenance;

use MediaWiki\Extension\ReadingLists\Maintenance\RestoreUserReadingLists;
use MediaWiki\Extension\ReadingLists\ReadingListRepository;
use MediaWiki\Extension\ReadingLists\Tests\ReadingListsTestHelperTrait;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use Wikimedia\Timestamp\TimestampFormat;

require_once __DIR__ . '/../../../../maintenance/restoreUserReadingLists.php';

/**
 * @covers \MediaWiki\Extension\ReadingLists\Maintenance\RestoreUserReadingLists
 * @group Database
 * @group ReadingLists
 */
class RestoreUserReadingListsTest extends MaintenanceBaseTestCase {
	use ReadingListsTestHelperTrait;

	private const CENTRAL_ID = 12345;

	protected function getMaintenanceClass() {
		return RestoreUserReadingLists::class;
	}

	protected function setUp(): void {
		parent::setUp();
		// Fixed date so restored lists get predictable "-20260730" name suffixes.
		ConvertibleTimestamp::setFakeTime( '2026-07-30T12:00:00Z' );
	}

	public function testDryRunWithoutListIdsShowsCandidates(): void {
		[ $defaultListId, $customListId ] = $this->createTornDownLists();

		$output = $this->runScript( [], true );

		$this->assertSame( 0, $this->getActiveListCount() );
		$this->assertStringContainsString( (string)$defaultListId, $output );
		$this->assertStringContainsString( (string)$customListId, $output );
		$this->assertStringContainsString( 'deletion depth 1', $output );
		$this->assertStringContainsString( 'Dry run: no changes made.', $output );
	}

	public function testDryRunWithListIdsDoesNotRestore(): void {
		[ $defaultListId, $customListId ] = $this->createTornDownLists();

		$output = $this->runScript( [ $defaultListId, $customListId ], true );

		$this->assertSame( 0, $this->getActiveListCount() );
		$this->assertStringContainsString( 'Lists to restore: 2', $output );
		$this->assertStringContainsString( 'An empty default list will be created.', $output );
	}

	public function testCreatesDefaultWhenThereAreNoActiveLists(): void {
		[ $defaultListId, $customListId ] = $this->createTornDownLists();

		$output = $this->runScript( [ $defaultListId, $customListId ] );

		// Both lists come back as custom; the former default is renamed because
		// the newly created default list takes the name "default".
		$this->assertListState( $defaultListId, 'default-20260730' );
		$this->assertListState( $customListId, 'custom' );
		$newDefault = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'rl_name', 'rl_deleted' ] )
			->from( 'reading_list' )
			->where( [ 'rl_user_id' => self::CENTRAL_ID, 'rl_is_default' => 1 ] )
			->caller( __METHOD__ )
			->fetchRow();
		$this->assertSame( 'default', $newDefault->rl_name );
		$this->assertSame( '0', $newDefault->rl_deleted );
		$this->assertStringContainsString( 'Created a new empty default list.', $output );
	}

	public function testRestoresOnlySpecifiedListsAfterRepeatedTeardown(): void {
		ConvertibleTimestamp::setFakeTime( '2026-07-01T12:00:00Z' );
		$this->addProjects( [ 'dummy' ] );
		$repository = $this->newRepository();
		$defaultList = $repository->setupForUser();
		$selectedList = $repository->addList( 'selected' );
		$entry = $repository->addListEntry( $selectedList->rl_id, 'dummy', 'Selected_page' );
		$repository->teardownForUser();

		// Second teardown cycle: its custom list was deleted on purpose and
		// must not be restored.
		$repository->setupForUser();
		$intentionallyDeletedList = $repository->addList( 'unwanted' );
		$repository->deleteList( $intentionallyDeletedList->rl_id );
		$repository->teardownForUser();
		$activeDefault = $repository->setupForUser();

		ConvertibleTimestamp::setFakeTime( '2026-07-02T12:00:00Z' );
		$this->runScript( [ $defaultList->rl_id, $selectedList->rl_id ] );

		$this->assertListState( $defaultList->rl_id, 'default-20260702' );
		$this->assertListState( $selectedList->rl_id, 'selected' );
		$this->assertListState( $activeDefault->rl_id, 'default', true );

		$lists = $this->getListsById();
		$this->assertSame( '1', $lists[$intentionallyDeletedList->rl_id]->rl_deleted );
		$this->assertSame( '1', $lists[$selectedList->rl_id]->rl_size );

		// The restored entry was touched so sync clients re-download it.
		$entryUpdated = $this->getDb()->newSelectQueryBuilder()
			->select( 'rle_date_updated' )
			->from( 'reading_list_entry' )
			->where( [ 'rle_id' => $entry->rle_id ] )
			->caller( __METHOD__ )
			->fetchField();

		$this->assertSame( '20260702120000', wfTimestamp( TimestampFormat::MW, $entryUpdated ) );
	}

	public function testRestoresMultipleFormerDefaultsAsCustom(): void {
		$repository = $this->newRepository();
		$firstDefault = $repository->setupForUser();
		$repository->teardownForUser();
		$secondDefault = $repository->setupForUser();
		$repository->teardownForUser();

		$this->runScript( [ $firstDefault->rl_id, $secondDefault->rl_id ] );

		$this->assertListState( $firstDefault->rl_id, 'default-20260730' );
		$this->assertListState(
			$secondDefault->rl_id,
			'default-20260730-' . $secondDefault->rl_id
		);
	}

	public function testRestoresCustomListOnlyWhenActiveDefaultExists(): void {
		$repository = $this->newRepository();
		$repository->setupForUser();
		$customList = $repository->addList( 'custom' );
		$repository->teardownForUser();
		$activeDefault = $repository->setupForUser();

		$this->runScript( [ $customList->rl_id ] );

		$this->assertListState( $customList->rl_id, 'custom' );
		$this->assertListState( $activeDefault->rl_id, 'default', true );
	}

	public function testKeepsNonemptyActiveDefault(): void {
		$this->addProjects( [ 'dummy' ] );
		$repository = $this->newRepository();
		$defaultList = $repository->setupForUser();
		$customList = $repository->addList( 'custom' );
		$repository->teardownForUser();
		$activeDefault = $repository->setupForUser();
		$repository->addListEntry( $activeDefault->rl_id, 'dummy', 'New_page' );
		$activeCustomList = $repository->addList( 'custom' );

		$this->runScript( [ $defaultList->rl_id, $customList->rl_id ] );

		// Restored lists get suffixes; the user's current lists are untouched.
		$this->assertListState( $defaultList->rl_id, 'default-20260730' );
		$this->assertListState( $customList->rl_id, 'custom-20260730' );
		$this->assertListState( $activeDefault->rl_id, 'default', true );
		$this->assertListState( $activeCustomList->rl_id, 'custom' );
	}

	public function testSkipsActiveAndUnknownLists(): void {
		$repository = $this->newRepository();
		$repository->setupForUser();
		$customList = $repository->addList( 'custom' );
		$repository->teardownForUser();
		$activeDefault = $repository->setupForUser();

		$output = $this->runScript( [ $customList->rl_id, $activeDefault->rl_id, 99999 ] );

		$this->assertListState( $customList->rl_id, 'custom' );
		$this->assertStringContainsString(
			"List {$activeDefault->rl_id} is already active; skipping.",
			$output
		);
		$this->assertStringContainsString(
			'List 99999 was not found for central ID 12345; skipping.',
			$output
		);
		$this->assertStringContainsString( 'Lists to restore: 1', $output );
	}

	public function testDoesNotCreateDefaultWhenAllSelectedListsAreSkipped(): void {
		$this->createTornDownLists();

		$output = $this->runScript( [ 99999 ] );

		$this->assertSame( 0, $this->getActiveListCount() );
		$this->assertStringNotContainsString( 'Created a new empty default list.', $output );
		$this->assertStringContainsString( 'Lists to restore: 0', $output );
		$this->assertStringContainsString( 'Nothing to restore.', $output );
	}

	public function testDryRunDoesNotPlanDefaultWhenAllSelectedListsAreSkipped(): void {
		$this->createTornDownLists();

		$output = $this->runScript( [ 99999 ], true );

		$this->assertStringNotContainsString( 'An empty default list will be created.', $output );
		$this->assertStringContainsString( 'Lists to restore: 0', $output );
	}

	public function testRequiresListIdsForWrite(): void {
		$this->expectCallToFatalError();
		$this->expectOutputRegex( '/At least one list ID is required in --list-ids/' );
		$this->runScript();
	}

	public function testRejectsInvalidCentralId(): void {
		$this->maintenance->setOption( 'central-id', 'not-an-id' );

		$this->expectCallToFatalError();
		$this->expectOutputRegex( '/Central ID must be a positive integer\./' );
		$this->maintenance->execute();
	}

	/**
	 * Create a default and a custom list ("custom"), then tear the user down,
	 * soft-deleting both.
	 *
	 * @return array{int,int} IDs of the deleted default and custom list
	 */
	private function createTornDownLists(): array {
		$repository = $this->newRepository();
		$defaultList = $repository->setupForUser();
		$customList = $repository->addList( 'custom' );
		$repository->teardownForUser();
		return [ $defaultList->rl_id, $customList->rl_id ];
	}

	private function newRepository(): ReadingListRepository {
		$repository = new ReadingListRepository(
			self::CENTRAL_ID,
			$this->getServiceContainer()->getDBLoadBalancerFactory()
		);
		$repository->initializeProjectIfNeeded();
		return $repository;
	}

	/**
	 * Run the script for CENTRAL_ID and return its output.
	 *
	 * @param int[] $listIds
	 * @param bool $dryRun
	 * @return string
	 */
	private function runScript( array $listIds = [], bool $dryRun = false ): string {
		$this->maintenance->setOption( 'central-id', self::CENTRAL_ID );
		if ( $listIds ) {
			// Join with ", " to also cover the trimming of the comma-separated values.
			$this->maintenance->setOption( 'list-ids', implode( ', ', $listIds ) );
		}
		if ( $dryRun ) {
			$this->maintenance->setOption( 'dry-run', true );
		}
		$this->maintenance->execute();
		return $this->getActualOutputForAssertion();
	}

	/**
	 * Assert that a list is active (not deleted) with the given name.
	 *
	 * @param int $listId
	 * @param string $name
	 * @param bool $isDefault
	 */
	private function assertListState( $listId, string $name, bool $isDefault = false ): void {
		$row = $this->getListsById()[$listId];
		$this->assertSame( $name, $row->rl_name );
		$this->assertSame( '0', $row->rl_deleted );
		$this->assertSame( $isDefault ? '1' : '0', $row->rl_is_default );
	}

	/**
	 * @return array<int,object>
	 */
	private function getListsById(): array {
		$rows = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'rl_id', 'rl_name', 'rl_size', 'rl_is_default', 'rl_deleted' ] )
			->from( 'reading_list' )
			->where( [ 'rl_user_id' => self::CENTRAL_ID ] )
			->caller( __METHOD__ )
			->fetchResultSet();
		$lists = [];
		foreach ( $rows as $row ) {
			$lists[(int)$row->rl_id] = $row;
		}
		return $lists;
	}

	private function getActiveListCount(): int {
		return $this->getDb()->newSelectQueryBuilder()
			->select( '1' )
			->from( 'reading_list' )
			->where( [
				'rl_user_id' => self::CENTRAL_ID,
				'rl_deleted' => 0,
			] )
			->caller( __METHOD__ )
			->fetchRowCount();
	}
}
