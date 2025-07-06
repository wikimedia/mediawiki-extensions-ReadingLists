<?php

namespace MediaWiki\Extension\ReadingLists\Rest;

use MediaWiki\Config\Config;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\ReadingLists\Doc\ReadingListEntryRow;
use MediaWiki\Extension\ReadingLists\Doc\ReadingListEntryRowWithMergeFlag;
use MediaWiki\Extension\ReadingLists\Doc\ReadingListRow;
use MediaWiki\Extension\ReadingLists\Doc\ReadingListRowWithMergeFlag;
use MediaWiki\Extension\ReadingLists\ReadingListRepository;
use MediaWiki\Extension\ReadingLists\ReadingListRepositoryException;
use MediaWiki\Permissions\Authority;
use MediaWiki\Rest\Validator\Validator;
use MediaWiki\Title\Title;
use MediaWiki\User\CentralId\CentralIdLookup;
use MediaWiki\User\UserIdentity;
use Psr\Log\LoggerInterface;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\NumericDef;
use Wikimedia\Rdbms\LBFactory;

/**
 * Trait to make the ReadingListRepository data access object available to
 * ReadingLists REST handlers, and other helper code.
 */
trait ReadingListsHandlerTrait {
	use RestUtilTrait;

	private ?ReadingListRepository $repository = null;

	private static int $MAX_BATCH_SIZE = 500;

	/**
	 * Map of sort/dir keywords used by the API to sort/dir keywords used by the repo.
	 * @var string[]
	 */
	private static array $sortParamMap = [
		'name' => ReadingListRepository::SORT_BY_NAME,
		'updated' => ReadingListRepository::SORT_BY_UPDATED,
		'ascending' => ReadingListRepository::SORT_DIR_ASC,
		'descending' => ReadingListRepository::SORT_DIR_DESC,
	];

	/**
	 * @param UserIdentity $user
	 * @param LBFactory $dbProvider
	 * @param Config $config
	 * @param CentralIdLookup $centralIdLookup
	 * @param LoggerInterface $logger
	 * @return ReadingListRepository
	 */
	private function createRepository(
		UserIdentity $user, LBFactory $dbProvider, Config $config,
		CentralIdLookup $centralIdLookup, LoggerInterface $logger
	) {
		$centralId = $centralIdLookup->centralIdFromLocalUser( $user, CentralIdLookup::AUDIENCE_RAW );
		$repository = new ReadingListRepository( $centralId, $dbProvider );
		$repository->setLimits(
			$config->get( 'ReadingListsMaxListsPerUser' ),
			$config->get( 'ReadingListsMaxEntriesPerList' )
		);
		$repository->setLogger( $logger );
		return $repository;
	}

	/**
	 * @return ?ReadingListRepository
	 */
	protected function getRepository(): ?ReadingListRepository {
		return $this->repository;
	}

	/**
	 * Convert a list record from ReadingListRepository into an array suitable for adding to
	 * the API result.
	 * @param ReadingListRow|ReadingListRowWithMergeFlag $row
	 * @return array
	 */
	private function getListFromRow( $row ) {
		// The "default" value was not included in the old RESTBase responses, but it was included
		// in the old Action API responses. We get it for free, so we include it.
		$item = [
			'id' => (int)$row->rl_id,
			'name' => $row->rl_name,
			'default' => (bool)$row->rl_is_default,
			'description' => $row->rl_description,
			'created' => wfTimestamp( TS_ISO_8601, $row->rl_date_created ),
			'updated' => wfTimestamp( TS_ISO_8601, $row->rl_date_updated ),
		];
		// @phan-suppress-next-line MediaWikiNoIssetIfDefined
		if ( isset( $row->merged ) ) {
			$item['duplicate'] = (bool)$row->merged;
		}
		if ( $row->rl_deleted ) {
			$item['deleted'] = (bool)$row->rl_deleted;
		}
		return $item;
	}

	/**
	 * Convert a list entry record from ReadingListRepository into an array suitable for adding to
	 * the API result.
	 * @param ReadingListEntryRow|ReadingListEntryRowWithMergeFlag $row
	 * @return array
	 */
	protected function getListEntryFromRow( $row ) {
		$item = [
			'id' => (int)$row->rle_id,
			'listId' => (int)$row->rle_rl_id,
			'project' => $row->rlp_project,
			'title' => $row->rle_title,
			'created' => wfTimestamp( TS_ISO_8601, $row->rle_date_created ),
			'updated' => wfTimestamp( TS_ISO_8601, $row->rle_date_updated ),
		];
		// @phan-suppress-next-line MediaWikiNoIssetIfDefined
		if ( isset( $row->merged ) ) {
			$item['duplicate'] = (bool)$row->merged;
		}
		if ( $row->rle_deleted ) {
			$item['deleted'] = true;
		}
		return $item;
	}

	/**
	 * Extract continuation data from item position and serialize it into a string.
	 * @param array $item Result item to continue from.
	 * @param string $sort One of the SORT_BY_* constants.
	 * @param string $name The item name
	 * @return string
	 */
	protected function makeNext( array $item, string $sort, string $name ): string {
		if ( $sort === ReadingListRepository::SORT_BY_NAME ) {
			$next = $name . '|' . $item['id'];
		} else {
			$next = $item['updated'] . '|' . $item['id'];
		}
		return $next;
	}

	/**
	 * Recover continuation data after it has been roundtripped to the client.
	 *
	 * @param string|null $encodedNext Continuation parameter returned by the client.
	 * @param string $sort One of the SORT_BY_* constants.
	 * @return null|int|string[]
	 *   Continuation token format is:
	 *   - null if there was no continuation parameter, OR
	 *   - id for lists/pages/{project}/{title} OR
	 *   - [ name, id ] when sorting by name OR
	 *   - [ date_updated, id ] when sorting by updated time
	 */
	protected function decodeNext( $encodedNext, $sort ) {
		if ( !$encodedNext ) {
			return null;
		}

		// Continue token format is '<name|timestamp>|<id>'; name can contain '|'.
		// We don't deal with the lists/pages/{project}/{title} case herein. This function is
		// overridden in that handler.
		$separatorPosition = strrpos( $encodedNext, '|' );
		$this->dieIf( $separatorPosition === false, 'apierror-badcontinue' );
		$continue = [
			substr( $encodedNext, 0, $separatorPosition ),
			substr( $encodedNext, $separatorPosition + 1 ),
		];
		$this->dieIf( $continue[1] !== (string)(int)$continue[1], 'apierror-badcontinue' );
		$continue[1] = (int)$continue[1];
		if ( $sort === ReadingListRepository::SORT_BY_UPDATED ) {
			$this->dieIf(
				wfTimestamp( TS_MW, $continue[0] ) === false,
				'apierror-badcontinue'
			);
		}
		return $continue;
	}

	/**
	 * Decode, validate and normalize the 'batch' parameter of write APIs.
	 * @param array $batch Decoded value of the 'batch' parameter.
	 * @return array[] One operation, typically a flat associative array.
	 */
	protected function getBatchOps( $batch ) {
		// TODO: consider alternatives to referencing the global services instance.
		//  RequestInterface doesn't provide normalizeUnicode(), so we can't use the
		//  $this->getRequest() method on Handler.
		$request = RequestContext::getMain()->getRequest();

		// Must be a real array, and not empty.
		if ( !is_array( $batch ) || $batch !== array_values( $batch ) || !$batch ) {
			// TODO: reconsider this once we have the ability to do deeper json body validation.
			//  This may become redundant and unnecessary.
			if ( json_last_error() ) {
				$jsonError = json_last_error_msg();
				$this->die( 'apierror-readinglists-batch-invalid-json', [ wfEscapeWikiText( $jsonError ) ] );
			}
			$this->die( 'apierror-readinglists-batch-invalid-structure' );
		}

		$i = 0;
		foreach ( $batch as &$op ) {
			if ( ++$i > self::$MAX_BATCH_SIZE ) {
				$this->die( 'apierror-readinglists-batch-toomanyvalues', [ self::$MAX_BATCH_SIZE ] );
			}
			// Each batch operation must be an associative array with scalar fields.
			if (
				!is_array( $op )
				|| array_values( $op ) === $op
				|| array_filter( $op, 'is_scalar' ) !== $op
			) {
				$this->die( 'apierror-readinglists-batch-invalid-structure' );
			}
			// JSON-escaped characters might have skipped WebRequest's normalization, repeat it.
			array_walk_recursive( $op, static function ( &$value ) use ( $request ) {
				if ( is_string( $value ) ) {
					$value = $request->normalizeUnicode( $value );
				}
			} );
		}
		return $batch;
	}

	/**
	 * Validate a single operation in the 'batch' parameter of write APIs. Works the same way as
	 * requireAtLeastOneParameter.
	 * @param array $op
	 * @param string ...$param
	 */
	protected function requireAtLeastOneBatchParameter( array $op, ...$param ) {
		$intersection = array_intersect(
			array_keys( array_filter( $op, static function ( $val ) {
				return $val !== null && $val !== false;
			} ) ),
			$param
		);

		if ( count( $intersection ) == 0 ) {
			$mv = MessageValue::new( 'apierror-readinglists-batch-missingparam-at-least-one-of' )
				->textListParams( $param )
				->numParams( count( $param ) );
			$this->die( $mv );
		}
	}

	/**
	 * Get common sorting/paging related params for getParamSettings().
	 * @return array[]
	 */
	public function getSortParamSettings( int $maxLimit, string $defaultSort ) {
		return [
			'sort' => [
				Validator::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_DEFAULT => $defaultSort,
				ParamValidator::PARAM_TYPE => [ 'name', 'updated' ],
				ParamValidator::PARAM_REQUIRED => false,
			],
			'dir' => [
				Validator::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_DEFAULT => 'ascending',
				ParamValidator::PARAM_TYPE => [ 'ascending', 'descending' ],
				ParamValidator::PARAM_REQUIRED => false,
			],
			'limit' => [
				Validator::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_DEFAULT => 10,
				NumericDef::PARAM_MIN => 1,
				NumericDef::PARAM_MAX => $maxLimit,
			],
		];
	}

	/**
	 * @param Authority $authority
	 */
	private function checkAuthority( Authority $authority ) {
		if ( !$authority->isNamed() ) {
			$this->die( 'rest-permission-denied-anon' );
		}

		if ( !$authority->isAllowed( 'viewmyprivateinfo' ) ) {
			$this->die( 'rest-permission-error', [ 'viewmyprivateinfo' ] );
		}
	}

	/**
	 * @param int $id the list to update
	 * @param string $project
	 * @param string $title
	 * @param ?ReadingListRepository $repository
	 * @return array
	 */
	public function createListEntry(
		int $id, string $project, string $title, ?ReadingListRepository $repository
	) {
		// Lists can contain titles from other wikis, and we have no idea of the exact title
		// validation rules used there; but in practice it's unlikely the rules would differ,
		// and allowing things like <> or # in the title could result in vulnerabilities in
		// clients that assume they are getting something sane. So let's validate anyway.
		// We do not normalize, that would contain too much local logic (e.g. title case), and
		// clients are expected to submit already normalized titles (that they got from the API)
		// anyway.
		if ( !Title::newFromText( $title ) ) {
			$this->die( 'apierror-invalidtitle', [ wfEscapeWikiText( $title ) ] );
		}

		try {
			$entry = $repository->addListEntry( $id, $project, $title );
		} catch ( ReadingListRepositoryException $e ) {
			$this->die( $e->getMessageObject() );
		}

		return [
			'id' => (int)$entry->rle_id,
			'entry' => $this->getListEntryFromRow( $entry ),
		];
	}
}
