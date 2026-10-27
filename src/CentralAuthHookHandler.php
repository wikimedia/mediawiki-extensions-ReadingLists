<?php

namespace MediaWiki\Extension\ReadingLists;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\CentralAuth\Hooks\CentralAuthPostLoginRedirectHook;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\User\Options\UserOptionsManager;

/**
 * This handler is separated from the main HookHandler to allow use of type
 * validation via the hook interface, without a hard dependency on CentralAuth.
 * It is important that this class only be referenced/loaded by code
 * controlled by the CentralAuth extension, to keep it a soft dependency.
 */
class CentralAuthHookHandler implements CentralAuthPostLoginRedirectHook {

	public function __construct(
		private readonly UserOptionsManager $userOptionsManager,
		private readonly ExtensionRegistry $extensionRegistry,
	) {
	}

	/** @inheritDoc */
	public function onCentralAuthPostLoginRedirect(
		string &$returnTo,
		string &$returnToQuery,
		bool $_unused1,
		string $type,
		string &$_unused2
	): bool {
		$returnToQueryArray = wfCgiToArray( $returnToQuery );
		$isFromReadingListsAccountCreationCta = array_key_exists(
			'readingListsAccountCreationCta',
			$returnToQueryArray
		);
		unset( $returnToQueryArray['readingListsAccountCreationCta'] );

		// If the URL parameter is present, the user came from the account creation CTA.
		if ( $isFromReadingListsAccountCreationCta ) {
			// For the account creation reading list cta, add a URL parameter that will
			// be used to send an account_created event.
			$returnToQueryArray['readingListsAccountJustCreated'] = '1';

			// Turn off the homepage mobile discovery popover from GrowthExperiments.
			$context = RequestContext::getMain();
			$user = $context->getUser();
			if (
				$this->extensionRegistry->isLoaded( 'GrowthExperiments' ) &&
				$user && $user->isRegistered()
			) {
				$preference = $context->getSkinName() === 'minerva' ?
					'homepage_mobile_discovery_notice_seen' :
					'growthexperiments-tour-homepage-discovery';
				$this->userOptionsManager->setOption( $user, $preference, 1 );
				$this->userOptionsManager->saveOptions( $user );
			}
		}

		$returnToQuery = wfArrayToCgi( $returnToQueryArray );
		return true;
	}
}
