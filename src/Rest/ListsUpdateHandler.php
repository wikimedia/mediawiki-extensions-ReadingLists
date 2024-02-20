<?php

namespace MediaWiki\Extension\ReadingLists\Rest;

use MediaWiki\Config\Config;
use MediaWiki\Extension\ReadingLists\ReadingListRepository;
use MediaWiki\Extension\ReadingLists\ReadingListRepositoryException;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Rest\Validator\JsonBodyValidator;
use MediaWiki\Rest\Validator\Validator;
use MediaWiki\User\CentralId\CentralIdLookup;
use Psr\Log\LoggerInterface;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\NumericDef;
use Wikimedia\ParamValidator\TypeDef\StringDef;
use Wikimedia\Rdbms\LBFactory;

/**
 * Handle PUT requests to /readinglists/v0/lists/{id}
 *
 * Updates reading lists
 */
class ListsUpdateHandler extends SimpleHandler {
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
			$this->getAuthority()->getUser(), $this->dbProvider, $this->config, $this->centralIdLookup, $this->logger
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
	 * @param int $id the list to update
	 * @return array
	 */
	public function run( int $id ) {
		$this->checkAuthority( $this->getAuthority() );

		$params = $this->getValidatedBody() ?? [];
		$this->requireAtLeastOneParameter( $params, 'name', 'description' );
		try {
			$list = $this->getRepository()->updateList( $id, $params['name'], $params['description'] );
		} catch ( ReadingListRepositoryException $e ) {
			$this->die( $e->getMessageObject() );
		}

		$listData = $this->getListFromRow( $list );
		return [
			'list' => $listData,
		];
	}

	/**
	 * @return array[]
	 */
	public function getParamSettings() {
		return [
			'id' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
				NumericDef::PARAM_MIN => 1,
				Handler::PARAM_SOURCE => 'path',
			],
		];
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
					ParamValidator::PARAM_REQUIRED => false,
					StringDef::PARAM_MAX_BYTES => ReadingListRepository::$fieldLength['rl_name'],
				],
				'description' => [
					self::PARAM_SOURCE => 'body',
					ParamValidator::PARAM_TYPE => 'string',
					ParamValidator::PARAM_REQUIRED => false,
					StringDef::PARAM_MAX_BYTES => ReadingListRepository::$fieldLength['rl_description'],
				]
			] + $this->getTokenParamDefinition()
		);
	}
}
