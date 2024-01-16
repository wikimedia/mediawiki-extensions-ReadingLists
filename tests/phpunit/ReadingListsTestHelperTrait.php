<?php

namespace MediaWiki\Extension\ReadingLists\Tests;

use ApiUsageException;

trait ReadingListsTestHelperTrait {

	/**
	 * Creates reading_list rows from the given data, with some magic fields:
	 * - missing user ids will be added automatically
	 * - 'entries' (array of rows for reading_list_entry) willbe converted into their own rows
	 * @param int $userId Th central ID of the list owner
	 * @param array[] $lists Array of rows for reading_list, with some magic fields
	 * @return array The list IDs
	 */
	private function addLists( $userId, array $lists ) {
		$listIds = [];
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
				$list['rl_date_created'] = $this->db->timestamp( $list['rl_date_created'] );
			}
			if ( isset( $list['rl_date_updated'] ) ) {
				$list['rl_date_updated'] = $this->db->timestamp( $list['rl_date_updated'] );
			}
			$this->db->insert( 'reading_list', $list, __METHOD__ );
			$listId = $this->db->insertId();
			if ( $entries !== null ) {
				$this->addListEntries( $listId, $list['rl_user_id'], $entries );
			}
			$listIds[] = $listId;
		}
		return $listIds;
	}

	/**
	 * Creates reading_list_entry rows from the given data, with some magic fields:
	 * - missing list ids will be filled automatically
	 * - 'rlp_project' will be handled appropriately
	 * @param int $listId The list to add entries to
	 * @param int $userId Th central ID of the list owner
	 * @param array[] $entries Array of rows for reading_list_entry, with some magic fields
	 * @return array The list entry IDs
	 */
	private function addListEntries( $listId, $userId, array $entries ) {
		$entryIds = [];
		foreach ( $entries as $entry ) {
			if ( !isset( $entry['rle_rl_id'] ) ) {
				$entry['rle_rl_id'] = $listId;
			}
			if ( !isset( $entry['rle_user_id'] ) ) {
				$entry['rle_user_id'] = $userId;
			}
			if ( isset( $entry['rlp_project'] ) ) {
				list( $projectId ) = $this->addProjects( [ $entry['rlp_project'] ] );
				unset( $entry['rlp_project'] );
				$entry['rle_rlp_id'] = $projectId;
			}
			if ( isset( $entry['rle_date_created'] ) ) {
				$entry['rle_date_created'] = $this->db->timestamp( $entry['rle_date_created'] );
			}
			if ( isset( $entry['rle_date_updated'] ) ) {
				$entry['rle_date_updated'] = $this->db->timestamp( $entry['rle_date_updated'] );
			}
			$this->db->insert( 'reading_list_entry', $entry, __METHOD__ );
			$entryId = $this->db->insertId();
			$entryIds[] = $entryId;
		}
		$entryCount = $this->db->newSelectQueryBuilder()
			->select( '*' )
			->from( 'reading_list_entry' )
			->where( [ 'rle_rl_id' => $listId ] )
			->caller( __METHOD__ )->fetchRowCount();
		$this->db->newUpdateQueryBuilder()
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
			$this->db->insert(
				'reading_list_project',
				[ 'rlp_project' => $project ],
				__METHOD__,
				[ 'IGNORE' ]
			);
			$projectId = $this->db->affectedRows()
				? $this->db->insertId()
				: $this->db->newSelectQueryBuilder()
					->select( 'rlp_id' )
					->from( 'reading_list_project' )
					->where( [ 'rlp_project' => $project ] )
					->caller( __METHOD__ )->fetchField();
			$ids[] = $projectId;
		}
		return $ids;
	}

	private function readingListsSetup() {
		$apiParams['command'] = 'setup';
		$apiParams['action']  = 'readinglists';
		$apiParams['format']  = 'json';
		$this->doApiRequestWithToken( $apiParams, null, $this->user );
	}

	private function readingListsTeardown() {
		$apiParams['command'] = 'teardown';
		$apiParams['action']  = 'readinglists';
		$apiParams['format']  = 'json';
		$this->doApiRequestWithToken( $apiParams, null, $this->user );
	}

	/**
	 * If $expectedErrorMessage is null, verify that the callback does not throw a usage error.
	 * If it isn't, verify that it throws that error.
	 * @param string $expectedErrorMessage
	 * @param callable $callback
	 * @param array $params
	 * @return mixed The return value of the callback, or null if there was an exception.
	 */
	private function assertApiUsage( $expectedErrorMessage, callable $callback, array $params = [] ) {
		try {
			$ret = call_user_func_array( $callback, $params );
		} catch ( ApiUsageException $e ) {
			$errorMessage = $e->getMessageObject()->getKey();
			if ( $expectedErrorMessage ) {
				$this->assertEquals( $expectedErrorMessage, $errorMessage,
					"Wrong exception (expected '$expectedErrorMessage', got '$errorMessage')" );
				return null;
			} else {
				$this->fail( "Unexpected exception '$errorMessage'" );
			}
		}
		if ( $expectedErrorMessage ) {
			$this->fail( "Expected exception '$errorMessage' missing" );
		}
		// Make sure there is at least one assertion so the test won't be marked risky.
		$this->assertTrue( true );
		return $ret;
	}
}
