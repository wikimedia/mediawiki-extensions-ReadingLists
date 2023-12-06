<?php

namespace MediaWiki\Extension\ReadingLists\Rest;

use MediaWiki\Config\Config;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\TokenAwareHandlerTrait;
use MediaWiki\Rest\Validator\BodyValidator;
use MediaWiki\Rest\Validator\JsonBodyValidator;
use MediaWiki\Rest\Validator\UnsupportedContentTypeBodyValidator;
use MediaWiki\Rest\Validator\Validator;
use MediaWiki\User\CentralId\CentralIdLookup;
use stdClass;
use Wikimedia\Rdbms\LBFactory;

/**
 * Tears down reading lists for the logged-in user
 */
class TeardownHandler extends Handler {
	use ReadingListsHandlerTrait;
	use TokenAwareHandlerTrait, ReadingListsTokenAwareHandlerTrait {
		ReadingListsTokenAwareHandlerTrait::getToken insteadof TokenAwareHandlerTrait;
	}

	private LBFactory $dbProvider;

	private Config $config;

	private CentralIdLookup $centralIdLookup;

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
	}

	/**
	 * Create the repository data access object instance.
	 *
	 * @return void
	 */
	public function postInitSetup() {
		$this->createRepository(
			$this->getAuthority()->getUser(), $this->dbProvider, $this->config, $this->centralIdLookup
		);
	}

	/**
	 * @return array|Response
	 * @throws HttpException
	 */
	public function execute() {
		$this->getRepository()->teardownForUser();

		// For historical compatibility, response must be an empty object.
		return $this->getResponseFactory()->createJson( new stdClass() );
	}

	/**
	 * @inheritDoc
	 */
	public function getBodyValidator( $contentType ): BodyValidator {
		if ( $contentType !== 'application/json' ) {
			return new UnsupportedContentTypeBodyValidator( $contentType );
		}

		return new JsonBodyValidator( $this->getTokenParamDefinition() );
	}

	/**
	 * @inheritDoc
	 */
	public function validate( Validator $restValidator ) {
		parent::validate( $restValidator );
		$this->validateToken();
	}

	/**
	 * @return array|array[]
	 */
	public function getParamSettings() {
		return [] + $this->getTokenParamSettings();
	}
}
