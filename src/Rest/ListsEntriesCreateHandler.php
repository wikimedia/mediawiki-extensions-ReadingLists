<?php

namespace MediaWiki\Extension\ReadingLists\Rest;

use MediaWiki\Config\Config;
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
use Wikimedia\Rdbms\LBFactory;

/**
 * Handle POST requests to /readinglists/v0/lists/{id}/entries
 *
 * Gets reading list entries
 */
class ListsEntriesCreateHandler extends SimpleHandler {
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

		$validatedBody = $this->getValidatedBody() ?? [];
		$project = $validatedBody['project'];
		$title = $validatedBody['title'];

		return $this->createListEntry( $id, $project, $title, $this->getRepository() );
	}

	/**
	 * @return array[]
	 */
	public function getParamSettings() {
		return [
			'id' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
				Handler::PARAM_SOURCE => 'path',
				NumericDef::PARAM_MIN => 1,
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
				'project' => [
					self::PARAM_SOURCE => 'body',
					ParamValidator::PARAM_TYPE => 'string',
					ParamValidator::PARAM_REQUIRED => true,
				],
				'title' => [
					self::PARAM_SOURCE => 'body',
					ParamValidator::PARAM_TYPE => 'string',
					ParamValidator::PARAM_REQUIRED => true,
				]
			] + $this->getTokenParamDefinition()
		);
	}
}
