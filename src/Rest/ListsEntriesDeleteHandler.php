<?php

namespace MediaWiki\Extension\ReadingLists\Rest;

use MediaWiki\Config\Config;
use MediaWiki\Extension\ReadingLists\ReadingListRepositoryException;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Rest\Validator\Validator;
use MediaWiki\User\CentralId\CentralIdLookup;
use Psr\Log\LoggerInterface;
use stdClass;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\NumericDef;
use Wikimedia\Rdbms\LBFactory;

/**
 * Handle DELETE requests to /readinglists/v0/lists/{id}/entries
 *
 * Gets reading list entries
 */
class ListsEntriesDeleteHandler extends SimpleHandler {
	use ReadingListsHandlerTrait;

	private LBFactory $dbProvider;

	private Config $config;

	private CentralIdLookup $centralIdLookup;

	private LoggerInterface $logger;

	/**
	 * @param LBFactory $dbProvider
	 * @param Config $config
	 * @param CentralIdLookup $centralIdLookup
	 */
	public function __construct(
		LBFactory $dbProvider,
		Config $config,
		CentralIdLookup $centralIdLookup
	) {
		$this->dbProvider = $dbProvider;
		$this->config = $config;
		$this->centralIdLookup = $centralIdLookup;
		$this->logger = LoggerFactory::getInstance( 'readinglists' );
	}

	/**
	 * Create the repository data access object instance.
	 *
	 * @return void
	 */
	public function postInitSetup() {
		$this->repository = $this->createRepository(
			$this->getAuthority()->getUser(), $this->dbProvider, $this->config, $this->centralIdLookup, $this->logger
		);
	}

	/**
	 * @inheritDoc
	 */
	public function validate( Validator $restValidator ) {
		try {
			// We intentionally do not require a csrf token, to match RESTBase
			parent::validate( $restValidator );
		} catch ( LocalizedHttpException $e ) {
			// Add fields expected by WMF mobile apps
			$this->die( $e->getMessageValue(), [], $e->getCode(), $e->getErrorData() );
		}
	}

	/**
	 * @param int $id the list to update
	 * @param int $entryId the list entry to update
	 * @return Response
	 */
	public function run( int $id, int $entryId ) {
		$this->checkAuthority( $this->getAuthority() );

		$repository = $this->getRepository();

		try {
			$repository->deleteListEntry( $entryId );
		} catch ( ReadingListRepositoryException $e ) {
			$this->die( $e->getMessageObject() );
		}

		// Return value is expected to be an empty json object
		return $this->getResponseFactory()->createJson( new stdClass );
	}

	/**
	 * @return array[]
	 */
	public function getParamSettings() {
		return [
			// The original contract required an "id" parameter as part of the path.
			// It isn't actually used - entries have their own unique id sufficient to
			// identify and delete the entry. But we are obligated to include it.
			'id' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
				Handler::PARAM_SOURCE => 'path',
				NumericDef::PARAM_MIN => 1,
			],
			'entry_id' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
				Handler::PARAM_SOURCE => 'path',
				NumericDef::PARAM_MIN => 1,
			],
		];
	}
}
