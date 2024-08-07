<?php

namespace MediaWiki\Extension\ReadingLists\Rest;

use MediaWiki\Config\Config;
use MediaWiki\Extension\ReadingLists\ReadingListRepository;
use MediaWiki\Extension\ReadingLists\ReadingListRepositoryException;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\Validator\Validator;
use MediaWiki\User\CentralId\CentralIdLookup;
use Psr\Log\LoggerInterface;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\StringDef;
use Wikimedia\Rdbms\LBFactory;

/**
 * Handle POST requests to /{module}/lists
 *
 * Creates reading lists
 */
class ListsCreateHandler extends Handler {
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
		try {
			parent::validate( $restValidator );
			$this->validateToken();
		} catch ( LocalizedHttpException $e ) {
			// Add fields expected by WMF mobile apps
			$this->die( $e->getMessageValue(), [], $e->getCode(), $e->getErrorData() );
		}
	}

	/**
	 * @return array|Response
	 */
	public function execute() {
		$result = [];
		$repository = $this->getRepository();
		$this->checkAuthority( $this->getAuthority() );

		$validatedBody = $this->getValidatedBody() ?? [];
		$name = $validatedBody['name'];
		$description = $validatedBody['description'];

		try {
			$list = $repository->addList( $name, $description );
		} catch ( ReadingListRepositoryException $e ) {
			$this->die( $e->getMessageObject() );
		}
		$listData = $this->getListFromRow( $list );
		$result['id'] = (int)$list->rl_id;
		$result['list'] = $listData;

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
				'name' => [
					self::PARAM_SOURCE => 'body',
					ParamValidator::PARAM_TYPE => 'string',
					ParamValidator::PARAM_REQUIRED => true,
					StringDef::PARAM_MAX_BYTES => ReadingListRepository::$fieldLength['rl_name'],
				],
				'description' => [
					self::PARAM_SOURCE => 'body',
					ParamValidator::PARAM_TYPE => 'string',
					ParamValidator::PARAM_REQUIRED => false,
					ParamValidator::PARAM_DEFAULT => '',
					StringDef::PARAM_MAX_BYTES => ReadingListRepository::$fieldLength['rl_description'],
				]
			] + $this->getTokenParamDefinition();
	}

	/**
	 * Override to disable extraneous body fields check.
	 *
	 * @param Validator $restValidator
	 */
	protected function detectExtraneousBodyFields( Validator $restValidator ) {
		// No-op to disable extraneous body fields check
	}
}
