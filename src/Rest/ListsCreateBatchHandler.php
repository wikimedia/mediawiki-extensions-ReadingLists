<?php

namespace MediaWiki\Extension\ReadingLists\Rest;

use MediaWiki\Config\Config;
use MediaWiki\Extension\ReadingLists\ReadingListRepositoryException;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\Validator\Validator;
use MediaWiki\User\CentralId\CentralIdLookup;
use Psr\Log\LoggerInterface;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\Rdbms\LBFactory;

/**
 * Handle POST requests to /{module}/lists/batch
 *
 * Creates reading lists
 */
class ListsCreateBatchHandler extends Handler {
	use ReadingListsHandlerTrait;
	use ReadingListsTokenAwareHandlerTrait;

	private readonly LoggerInterface $logger;

	public function __construct(
		private readonly LBFactory $dbProvider,
		private readonly Config $config,
		private readonly CentralIdLookup $centralIdLookup,
	) {
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
		try {
			parent::validate( $restValidator );
			$this->validateToken();
		} catch ( LocalizedHttpException $e ) {
			// Add fields expected by WMF mobile apps
			$this->die( $e->getMessageValue(), [], $e->getCode(), $e->getErrorData() );
		}
	}

	/**
	 * Disable extraneous body fields detection.
	 *
	 * @param Validator $restValidator
	 */
	protected function detectExtraneousBodyFields( Validator $restValidator ) {
		// No-op to disable extraneous body fields detection
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
	public function getParamSettings(): array {
		return $this->getReadingListsTokenParamDefinition();
	}

	/**
	 * @return array[]
	 */
	public function getBodyParamSettings(): array {
		return [
			// TODO: consider additional validation on "batch", once we have that capability.
			'batch' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'array',
				ParamValidator::PARAM_REQUIRED => true,
			],
		] + $this->getTokenParamDefinition();
	}
}
