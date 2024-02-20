<?php

namespace MediaWiki\Extension\ReadingLists\Rest;

use MediaWiki\Config\Config;
use MediaWiki\Extension\ReadingLists\ReadingListRepository;
use MediaWiki\Extension\ReadingLists\ReadingListRepositoryException;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\Validator\JsonBodyValidator;
use MediaWiki\Rest\Validator\Validator;
use MediaWiki\User\CentralId\CentralIdLookup;
use Psr\Log\LoggerInterface;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\StringDef;
use Wikimedia\Rdbms\LBFactory;

/**
 * Handle POST requests to /readinglists/v0/lists
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
	 * @inheritDoc
	 */
	public function getBodyValidator( $contentType ) {
		if ( $contentType !== 'application/json' ) {
			throw new HttpException( "Unsupported Content-Type",
				415,
				[ 'content_type' => $contentType ]
			);
		}

		return new JsonBodyValidator( [
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
			] + $this->getTokenParamDefinition()
		);
	}
}
