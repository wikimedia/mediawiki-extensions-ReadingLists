<?php

namespace MediaWiki\Extension\ReadingLists\Rest;

use MediaWiki\Config\Config;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Rest\Validator\Validator;
use MediaWiki\User\CentralId\CentralIdLookup;
use Psr\Log\LoggerInterface;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\NumericDef;
use Wikimedia\Rdbms\LBFactory;

/**
 * Handle POST requests to /{module}/lists/{id}/entries/batch
 *
 * Gets reading list entries
 */
class ListsEntriesCreateBatchHandler extends SimpleHandler {
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
	 * @param int $id the list to update
	 * @return array
	 */
	public function run( int $id ) {
		$this->checkAuthority( $this->getAuthority() );

		$validatedBody = $this->getValidatedBody() ?? [];
		$batch = $validatedBody['batch'];

		$result = [];
		$result['entries'] = [];
		$result['batch'] = [];
		foreach ( $this->getBatchOps( $batch ) as $op ) {
			$this->requireAtLeastOneBatchParameter( $op, 'project' );
			$this->requireAtLeastOneBatchParameter( $op, 'title' );
			$res = $this->createListEntry( $id, $op['project'], $op['title'], $this->getRepository() );
			$result['batch'][] = [ 'id' => $res['id'] ];
			$result['entries'][] = $res['entry'];
		}

		return $result;
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
			]
		] + $this->getReadingListsTokenParamDefinition();
	}

	/**
	 * @return array[]
	 */
	public function	getBodyParamSettings(): array {
		return [
			// TODO: consider additional validation on "batch", once we have that capability.
			'batch' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'array',
				ParamValidator::PARAM_REQUIRED => true,
			]
		] + $this->getTokenParamDefinition();
	}
}
