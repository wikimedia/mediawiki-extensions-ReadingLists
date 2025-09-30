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
use stdClass;
use Wikimedia\Rdbms\LBFactory;

/**
 * Sets up reading lists for the logged-in user
 */
class SetupHandler extends Handler {
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
			$this->config,
			$this->centralIdLookup,
			$this->logger,
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
	 * @return Response
	 */
	public function execute() {
		$this->checkAuthority( $this->getAuthority() );

		try {
			$this->getRepository()->setupForUser();
		} catch ( ReadingListRepositoryException $e ) {
			$this->die( $e->getMessageObject() );
		}

		// For historical compatibility, the response must be an empty object.
		// The equivalent Action API endpoint returns the default list, but this list is not
		// included the equivalent RESTBase response, and that's the contract this endpoint
		// was created to match.
		return $this->getResponseFactory()->createJson( new stdClass() );
	}

	/**
	 * @return array|array[]
	 */
	public function getParamSettings() {
		return $this->getReadingListsTokenParamDefinition();
	}

	/**
	 * @return array[]
	 */
	public function getBodyParamSettings(): array {
		return $this->getTokenParamDefinition();
	}
}
