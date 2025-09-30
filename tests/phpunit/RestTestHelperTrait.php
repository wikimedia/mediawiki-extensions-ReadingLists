<?php

namespace MediaWiki\Extension\ReadingLists\Tests;

use MediaWiki\Extension\ReadingLists\ReadingListRepository;
use MediaWiki\Extension\ReadingLists\Rest\SetupHandler;
use MediaWiki\Extension\ReadingLists\Rest\TeardownHandler;
use MediaWiki\Extension\ReadingLists\ReverseInterwikiLookup;
use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\Authority;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\RequestData;
use MediaWiki\Rest\RequestInterface;
use MediaWiki\Rest\ResponseInterface;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWiki\User\CentralId\CentralIdLookup;
use PHPUnit\Framework\MockObject\MockObject;
use Wikimedia\TestingAccessWrapper;

trait RestTestHelperTrait {
	use HandlerTestTrait;

	private ?Authority $privilegedAuthority = null;
	private ?Authority $unprivilegedAuthority = null;

	private function getAuthority( bool $privileged = true ): Authority {
		if ( $privileged ) {
			if ( !$this->privilegedAuthority ) {
				$this->privilegedAuthority = $this->mockRegisteredUltimateAuthority();
			}
			return $this->privilegedAuthority;
		} else {
			if ( !$this->unprivilegedAuthority ) {
				$this->unprivilegedAuthority = $this->mockAnonNullAuthority();
			}
			return $this->unprivilegedAuthority;
		}
	}

	/**
	 * @param bool $privileged Whether the authority used in the mock is privileged or not
	 * @return MockObject|CentralIdLookup
	 */
	private function getMockCentralIdLookup( bool $privileged = true ) {
		$centralIdLookup = $this->createNoOpMock( CentralIdLookup::class, [ 'centralIdFromLocalUser' ] );
		$centralIdLookup->method( 'centralIdFromLocalUser' )
			->willReturn( $this->getAuthority( $privileged )->getUser()->getId() );
		return $centralIdLookup;
	}

	/**
	 * @param string|null $prefix
	 * @return MockObject|ReverseInterwikiLookup
	 */
	private function getMockReverseInterwikiLookup( ?string $prefix ) {
		$lookup = $this->createNoOpMock( ReverseInterwikiLookup::class, [ 'lookup' ] );
		$lookup->method( 'lookup' )
			->willReturn( $prefix );
		return $lookup;
	}

	/**
	 * @param Handler $handler
	 * @param bool $privileged Whether the authority is privileged or not
	 * @return ReadingListRepository
	 */
	private function getReadingListRepository( Handler $handler, bool $privileged = true ) {
		$wrapper = TestingAccessWrapper::newFromObject( $handler );
		return $wrapper->createRepository(
			$this->getAuthority( $privileged )->getUser(),
			$wrapper->config,
			$wrapper->centralIdLookup,
			$wrapper->logger
		);
	}

	/**
	 * Executes the given Handler on the given request.
	 *
	 * @param Handler $handler
	 * @param RequestInterface $request
	 * @param bool $privileged Whether the authority is privileged or not
	 * @param bool $csrfSafe Whether the session is csrf safe or not
	 * @return ResponseInterface
	 */
	private function executeReadingListsHandler(
		Handler $handler,
		RequestInterface $request,
		bool $privileged = true,
		bool $csrfSafe = true
	) {
		return $this->executeHandler(
			$handler,
			$request,
			[],
			$this->createHookContainer(),
			[],
			[],
			$this->getAuthority( $privileged ),
			$this->getSession( $csrfSafe )
		);
	}

	/**
	 * Executes the given Handler on the given request, parses the response body as JSON,
	 *  and returns the result.
	 *
	 * @param Handler $handler
	 * @param RequestInterface $request
	 * @param bool $privileged Whether the authority is privileged or not
	 * @param bool $csrfSafe Whether the session is csrf safe or not
	 * @return array
	 */
	private function executeReadingListsHandlerAndGetBodyData(
		Handler $handler,
		RequestInterface $request,
		bool $privileged = true,
		bool $csrfSafe = true
	) {
		return $this->executeHandlerAndGetBodyData(
			$handler,
			$request,
			[],
			$this->createHookContainer(),
			[],
			[],
			$this->getAuthority( $privileged ),
			$this->getSession( $csrfSafe )
		);
	}

	/**
	 * @param mixed $value
	 */
	private function assertIsReadingListTimestamp( $value ) {
		$this->assertIsString( $value );
		$this->assertStringMatchesFormat( '%d-%d-%dT%d:%d:%dZ', $value );
	}

	/**
	 * Creates reading_list rows from the given data, with some magic fields:
	 * - missing user ids will be added automatically
	 * - 'entries' (array of rows for reading_list_entry) will be converted into their own rows
	 * @param int $userId The central ID of the list owner
	 * @param array[] $lists Array of rows for reading_list, with some magic fields
	 * @return array The list and entry IDs
	 */
	private function addLists( $userId, array $lists ) {
		$ids = [
			'lists' => [],
			'entries' => [],
		];

		foreach ( $lists as $list ) {
			if ( !isset( $list['rl_user_id'] ) ) {
				$list['rl_user_id'] = $userId;
			}
			$entries = null;
			if ( isset( $list['entries'] ) ) {
				$entries = $list['entries'];
				unset( $list['entries'] );
			}
			if ( isset( $list['rl_date_created'] ) ) {
				$list['rl_date_created'] = $this->getDb()->timestamp( $list['rl_date_created'] );
			}
			if ( isset( $list['rl_date_updated'] ) ) {
				$list['rl_date_updated'] = $this->getDb()->timestamp( $list['rl_date_updated'] );
			}
			$this->getDb()->newInsertQueryBuilder()
				->insertInto( 'reading_list' )
				->row( $list )
				->caller( __METHOD__ )
				->execute();
			$listId = $this->getDb()->insertId();
			if ( $entries !== null ) {
				$ids['entries'] += $this->addListEntries( $listId, $list['rl_user_id'], $entries );
			}
			$ids['lists'][$list['rl_name']] = $listId;
		}
		return $ids;
	}

	/**
	 * Creates reading_list_entry rows from the given data, with some magic fields:
	 * - missing list ids will be filled automatically
	 * - 'rlp_project' will be handled appropriately
	 * @param int $listId The list to add entries to
	 * @param int $userId The central ID of the list owner
	 * @param array[] $entries Array of rows for reading_list_entry, with some magic fields
	 * @return array The list entry IDs, indexed by entry title. Unreliable with duplicate titles.
	 */
	private function addListEntries( int $listId, int $userId, array $entries ): array {
		$entryIds = [];
		foreach ( $entries as $entry ) {
			if ( !isset( $entry['rle_rl_id'] ) ) {
				$entry['rle_rl_id'] = $listId;
			}
			if ( !isset( $entry['rle_user_id'] ) ) {
				$entry['rle_user_id'] = $userId;
			}
			if ( isset( $entry['rlp_project'] ) ) {
				[ $projectId ] = $this->addProjects( [ $entry['rlp_project'] ] );
				unset( $entry['rlp_project'] );
				$entry['rle_rlp_id'] = $projectId;
			}
			if ( isset( $entry['rle_date_created'] ) ) {
				$entry['rle_date_created'] = $this->getDb()->timestamp( $entry['rle_date_created'] );
			}
			if ( isset( $entry['rle_date_updated'] ) ) {
				$entry['rle_date_updated'] = $this->getDb()->timestamp( $entry['rle_date_updated'] );
			}
			$this->getDb()->newInsertQueryBuilder()
				->insertInto( 'reading_list_entry' )
				->row( $entry )
				->caller( __METHOD__ )
				->execute();
			$entryId = $this->getDb()->insertId();
			$entryIds[$entry['rle_title']] = $entryId;
		}
		$entryCount = $this->getDb()->newSelectQueryBuilder()
			->select( '*' )
			->from( 'reading_list_entry' )
			->where( [ 'rle_rl_id' => $listId ] )
			->caller( __METHOD__ )->fetchRowCount();
		$this->getDb()->newUpdateQueryBuilder()
			->update( 'reading_list' )
			->set( [ 'rl_size' => $entryCount ] )
			->where( [ 'rl_id' => $listId ] )
			->execute();
		return $entryIds;
	}

	/**
	 * Creates reading_list_project rows from the given data.
	 * @param string[] $projects
	 * @return int[] Project IDs
	 */
	private function addProjects( array $projects ) {
		$ids = [];
		foreach ( $projects as $project ) {
			$this->getDb()->newInsertQueryBuilder()
				->insertInto( 'reading_list_project' )
				->ignore()
				->row( [ 'rlp_project' => $project ] )
				->caller( __METHOD__ )
				->execute();
			$projectId = $this->getDb()->affectedRows()
				? $this->getDb()->insertId()
				: $this->getDb()->newSelectQueryBuilder()
					->select( 'rlp_id' )
					->from( 'reading_list_project' )
					->where( [ 'rlp_project' => $project ] )
					->caller( __METHOD__ )->fetchField();
			$ids[] = $projectId;
		}
		return $ids;
	}

	/**
	 * @param array $list
	 * @param int $listId
	 * @param string $name
	 * @param string $description
	 * @param bool $isDefault
	 * @return void
	 */
	private function checkReadingList(
		array $list, int $listId, string $name, string $description, bool $isDefault
	) {
		$this->assertIsArray( $list );
		$this->assertArrayHasKey( 'id', $list );
		$this->assertIsInt( $list['id'] );
		if ( $listId ) {
			$this->assertSame( $listId, $list['id'] );
		}

		$this->assertArrayHasKey( 'name', $list );
		$this->assertSame( $name, $list['name'] );
		$this->assertArrayHasKey( 'description', $list );
		$this->assertSame( $description, $list['description'] );
		$this->assertArrayHasKey( 'default', $list );
		$this->assertSame( $isDefault, $list['default'] );
		$this->assertArrayHasKey( 'created', $list );
		$this->assertIsReadingListTimestamp( $list['created'] );
		$this->assertArrayHasKey( 'updated', $list );
		$this->assertIsReadingListTimestamp( $list['updated'] );
	}

	/**
	 * @param array $entry
	 * @param int $entryId
	 * @param string $project
	 * @param string $title
	 * @return void
	 */
	private function checkReadingListEntry( array $entry, int $entryId, string $project, string $title ): void {
		$this->assertArrayHasKey( 'id', $entry );
		$this->assertSame( $entryId, $entry['id'] );
		$this->assertIsInt( $entry['id'] );
		$this->assertArrayHasKey( 'project', $entry );
		$this->assertSame( $entry['project'], $project );
		$this->assertArrayHasKey( 'title', $entry );
		$this->assertSame( $entry['title'], $title );
		$this->assertArrayHasKey( 'created', $entry );
		$this->assertIsReadingListTimestamp( $entry['created'] );
		$this->assertArrayHasKey( 'updated', $entry );
		$this->assertIsReadingListTimestamp( $entry['updated'] );
	}

	private function readingListsSetup(): object {
		$request = new RequestData();
		$services = $this->getServiceContainer();
		$handler = new SetupHandler(
			MediaWikiServices::getInstance()->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup()
		);

		$this->addProjects( [ 'test' ] );
		return $this->executeReadingListsHandler( $handler, $request );
	}

	private function readingListsTeardown(): object {
		$request = new RequestData();
		$services = $this->getServiceContainer();
		$handler = new TeardownHandler(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig(),
			$this->getMockCentralIdLookup() );
		return $this->executeReadingListsHandler( $handler, $request );
	}
}
