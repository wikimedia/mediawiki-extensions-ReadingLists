<?php

namespace MediaWiki\Extension\ReadingLists\Rest;

use LogicException;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\TokenAwareHandlerTrait;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Adds csrf token handling for Reading Lists.
 *
 * The Reading Lists REST endpoints replaced endpoints of the same contract in RESTBase.
 * They therefore have to accept csrf tokens in an unusual way: as a query parameter
 * named "csrf_token". They will also accept tokens in the usual way: as a body
 * parameter named "token". If callers can eventually convert to the usual way, then this
 * class can be removed in favor of using only the core TokenAwareHandlerTrait.
 *
 * Handlers that use this trait should:
 * 1) override getBodyValidator() to use getTokenParamDefinition()
 * 2) extend validate() to call validateToken()
 * 3) extend getParamSettings() to include the return value from getTokenParamSettings()
 *
 * @see Handler::requireSafeAgainstCsrf()
 * @see TokenAwareHandlerTrait
 *
 * @package MediaWiki\Rest
 */
trait ReadingListsTokenAwareHandlerTrait {
	use TokenAwareHandlerTrait;

	/**
	 * Returns the definition for the token parameter, to be used in getParamSettings().
	 *
	 * @return array[]
	 */
	public function getReadingListsTokenParamDefinition() {
		return [
			'csrf_token' => [
				Handler::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
			]
		];
	}

	/**
	 * Determines the CSRF token to be used, possibly taking it from the request.
	 *
	 * Returns an empty string if the request isn't known to be safe and
	 * no token was supplied by the client.
	 * Returns null if the session provider is safe against CSRF (and thus no token
	 * is needed)
	 *
	 * @return string|null
	 */
	protected function getToken(): ?string {
		if ( !$this instanceof Handler ) {
			// Don't use die() because this is a code structure exception meant for developers,
			// not a caller-facing exception associated with any particular request.
			throw new LogicException( 'This trait must be used on handler classes.' );
		}

		if ( $this->getSession()->getProvider()->safeAgainstCsrf() ) {
			return null;
		}

		$body = $this->getValidatedBody();
		if ( !empty( $body['token'] ) ) {
			return $body['token'];
		}

		$params = $this->getValidatedParams();
		if ( isset( $params['csrf_token'] ) ) {
			return $params['csrf_token'];
		}

		return '';
	}
}
