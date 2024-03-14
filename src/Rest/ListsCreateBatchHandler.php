<?php

namespace MediaWiki\Extension\ReadingLists\Rest;

use MediaWiki\Config\Config;
use MediaWiki\Extension\ReadingLists\ReadingListRepositoryException;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\Validator\Validator;
use MediaWiki\User\CentralId\CentralIdLookup;
use Psr\Log\LoggerInterface;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\Rdbms\LBFactory;

/**
 * Handle POST requests to /readinglists/v0/lists/batch
 *
 * Creates reading lists
 */
class ListsCreateBatchHandler extends Handler {
	use ReadingListsHandlerTrait;
	use ReadingListsTokenAwareHandlerTrait;

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
			$this->getAuthority()->getUser(),
			$this->dbProvider,
			$this->config,
			$this->centralIdLookup,
			$this->logger
		);
	}

	/**
	 * @inheritDoc
	 */
	public function validate( Validator $restValidator ) {
		parent::validate( $restValidator );
		$this->validateToken();
	}

	/**
	 * @return array|Response
	 */
	public function execute() {
		$result = [];
		$repository = $this->getRepository();
		$this->checkAuthority( $this->getAuthority() );

		$validatedBody = $this->getValidatedBody() ?? [];
		$batch = $validatedBody['batch'];

		$listData = $listIds = [];
		foreach ( $this->getBatchOps( $batch ) as $op ) {
			$description = $op['description'] ?? '';
			$this->requireAtLeastOneBatchParameter( $op, 'name' );
			try {
				$list = $repository->addList( $op['name'], $description );
			} catch ( ReadingListRepositoryException $e ) {
				$this->die( $e->getMessageObject() );
			}
			$listIds[] = (object)[ 'id' => (int)$list->rl_id ];
			$listData[] = $this->getListFromRow( $list );
		}
		$result['batch'] = $listIds;
		$result['lists'] = $listData;

		return $this->getResponseFactory()->createJson( $result );
	}

	/**
	 * @return array[]
	 */
	public function getParamSettings() {
		return [
				// TODO: consider additional validation on "batch", once we have that capability.
				'batch' => [
					self::PARAM_SOURCE => 'body',
					ParamValidator::PARAM_TYPE => 'array',
					ParamValidator::PARAM_REQUIRED => true,
				],
			] + $this->getTokenParamDefinition() + $this->getReadingListsTokenParamDefinition();
	}
}
