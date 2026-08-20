<?php

namespace MediaWiki\Extension\ReadingLists;

use MediaWiki\Api\ApiQuerySiteinfo;
use MediaWiki\Api\Hook\APIQuerySiteInfoGeneralInfoHook;
use MediaWiki\Config\Config;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\BetaFeatures\BetaFeatures;
use MediaWiki\Extension\ReadingLists\Service\BookmarkEntryLookupResult;
use MediaWiki\Extension\ReadingLists\Service\BookmarkEntryLookupService;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\ResourceLoader\Hook\ResourceLoaderGetConfigVarsHook;
use MediaWiki\Skin\Hook\SkinTemplateNavigation__UniversalHook;
use MediaWiki\Skin\SkinTemplate;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\CentralId\CentralIdLookupFactory;
use MediaWiki\User\Options\UserOptionsManager;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityUtils;
use Wikimedia\ArrayUtils\ArrayUtils;

/**
 * Static entry points for hooks.
 */
class HookHandler implements
	APIQuerySiteInfoGeneralInfoHook,
	SkinTemplateNavigation__UniversalHook,
	ResourceLoaderGetConfigVarsHook
{

	private const SURVEY_NAME = 'ReadingLists beta feature survey';
	private const SURVEY_QUESTION = 'readinglists-betafeature-quicksurvey-question';
	private const SURVEY_ANSWER_POSITIVE = 'readinglists-betafeature-quicksurvey-answer-positive';
	private const SURVEY_ANSWER_NEGATIVE = 'readinglists-betafeature-quicksurvey-answer-negative';

	public function __construct(
		private readonly Config $config,
		private readonly BookmarkEntryLookupService $bookmarkEntryLookupService,
		private readonly UserOptionsManager $userOptionsManager,
		private readonly CentralIdLookupFactory $centralIdLookupFactory,
		private readonly UserIdentityUtils $userIdentityUtils
	) {
	}

	/**
	 * Handler for SkinTemplateNavigation::Universal hook.
	 * Adds "Notifications" items to the notifications content navigation.
	 * SkinTemplate automatically merges these into the personal tools for older skins.
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/SkinTemplateNavigation::Universal
	 * @param SkinTemplate $sktemplate
	 * @param array &$links Array of URLs to append to.
	 */
	public function onSkinTemplateNavigation__Universal( $sktemplate, &$links ): void {
		if ( !self::isSkinSupported( $sktemplate->getSkinName() ) ) {
			return;
		}

		$user = $sktemplate->getUser();
		$readingListsEnabledForUser = $this->isReadingListsEnabledForUser( $user );
		$inAccountCreationCta = !$user->isRegistered() &&
			$sktemplate->getSkinName() === 'minerva' &&
			$this->config->get( 'ReadingListsMinervaCTA' );

		// Show bookmark to logged-in opted into ReadingList
		// Show bookmark to logged-out users in MinervaNeue when account creation CTA is enabled
		if ( $readingListsEnabledForUser ) {
			$this->addSpecialPageLinkToUserMenu( $user, $sktemplate, $links );
		} elseif ( !$inAccountCreationCta ) {
			// Hide bookmark for everyone else
			return;
		}

		$output = $sktemplate->getOutput();
		$output->addModuleStyles( 'ext.readingLists.bookmark.icons' );
		$output->addModuleStyles( 'ext.readingLists.bookmark.styles' );

		if ( !$output->isArticle() ) {
			return;
		}

		// NOTE: Non-existent pages still have a Title object.
		// It should be rare that the Title is null here, but we should still check.
		$title = $output->getTitle();
		if ( !$title || $title->getNamespace() !== NS_MAIN ) {
			return;
		}

		$isSaved = false;
		$hasCustomListEntry = false;

		if ( $readingListsEnabledForUser ) {
			$centralId = $this->centralIdLookupFactory->getLookup()
				->centralIdFromLocalUser( $user );

			if ( $centralId ) {
				$status = $this->bookmarkEntryLookupService->getBookmarkEntryLookupStatus(
					$title,
					$centralId
				);
				// if there is a failure during lookup, then render page not saved
				if ( $status->isOK() ) {
					/** @var BookmarkEntryLookupResult $lookupResult */
					$lookupResult = $status->getValue();
					$isSaved = $lookupResult->isSaved();
					$hasCustomListEntry = $lookupResult->hasCustomListEntry();
				}
			}
		}

		$links['views']['bookmark'] = [
			'text' => $sktemplate->msg(
				'readinglists-' . ( $isSaved ? 'remove' : 'add' ) . '-bookmark'
			)->text(),
			'icon' => $isSaved ? 'bookmark' : 'bookmarkOutline',
			'href' => '#',
			'data-mw-saved' => $isSaved ? 1 : null,
			'data-mw-in-custom-list' => $hasCustomListEntry ? 1 : null,
			'link-class' => 'reading-lists-bookmark',
			'single-id' => $isSaved ? 'ca-bookmark-remove' : 'ca-bookmark-add',
			'tooltiponly' => true,
		];

		if ( $readingListsEnabledForUser ) {
			$output->addModules( 'ext.readingLists.bookmark' );

			// Move watch link to top of menu
			$actionMenu = $links['actions'] ?? [];
			if ( isset( $actionMenu['watch'] ) ) {
				$watchLink = [ 'watch' => $actionMenu['watch'] ];
				unset( $actionMenu['watch'] );
				$links['actions'] = $watchLink + $actionMenu;
			}
			if ( isset( $actionMenu['unwatch'] ) ) {
				$unwatchLink = [ 'unwatch' => $actionMenu['unwatch'] ];
				unset( $actionMenu['unwatch'] );
				$links['actions'] = $unwatchLink + $actionMenu;
			}
		}

		if ( $inAccountCreationCta ) {
			$output->addModules( 'ext.readingLists.bookmark.anonymous' );
		}
	}

	private function addSpecialPageLinkToUserMenu(
		UserIdentity $user,
		SkinTemplate $sktemplate,
		array &$links
	): void {
		$userMenu = $links['user-menu'] ?? [];

		// Insert readinglists after 'mytalk', or after 'sandbox' if present.
		// Reference: T413413.
		$insertAfter = 'mytalk';
		if ( isset( $userMenu['sandbox'] ) ) {
			$insertAfter = 'sandbox';
		}

		$userName = $user->getName();
		$specialPageUrl = SpecialPage::getTitleFor( 'ReadingLists', $userName )->getLinkURL();

		$readingListsArray = [
			'readinglists' => [
				'text' => $sktemplate->msg( 'readinglists-menu-item' )->text(),
				'href' => $specialPageUrl,
				'icon' => 'bookmarkList',
			],
		];
		if ( array_key_exists( $insertAfter, $links['user-menu'] ) ) {
			$links['user-menu'] = ArrayUtils::insertAfter( $userMenu, $readingListsArray, $insertAfter );
		} else {
			$links['user-menu'] = $readingListsArray + $userMenu;
		}
	}

	private function isReadingListsEnabledForUser( UserIdentity $user ): bool {
		if ( $this->userIdentityUtils->isTemp( $user ) ) {
			return false;
		}

		if ( $this->config->get( 'ReadingListsEnabled' ) ) {
			return $user->isRegistered();
		}

		$betaFeatureIsAvailable = $this->config->get( 'ReadingListBetaFeature' ) &&
			ExtensionRegistry::getInstance()->isLoaded( 'BetaFeatures' );

		if ( $betaFeatureIsAvailable ) {
			return BetaFeatures::isFeatureEnabled( $user, Constants::PREF_KEY_BETA_FEATURES );
		}

		return false;
	}

	/**
	 * Show the reading list and bookmark if the skin is Vector 2022 or Minerva.
	 * @see https://phabricator.wikimedia.org/T395332
	 * @param string $skinName
	 * @return bool
	 */
	public static function isSkinSupported( $skinName ) {
		return $skinName === 'vector-2022' || $skinName === 'minerva';
	}

	/**
	 * Add configuration data to the siteinfo API output.
	 * Used by the RESTBase proxy for help messages in the Swagger doc.
	 * @param ApiQuerySiteinfo $module
	 * @param array &$result
	 */
	public function onAPIQuerySiteInfoGeneralInfo( $module, &$result ) {
		$result['readinglists-config'] = [
			'maxListsPerUser' => $this->config->get( 'ReadingListsMaxListsPerUser' ),
			'maxEntriesPerList' => $this->config->get( 'ReadingListsMaxEntriesPerList' ),
			'deletedRetentionDays' => $this->config->get( 'ReadingListsDeletedRetentionDays' ),
		];
	}

	/**
	 * Return whether the beta feature survey is enabled.
	 *
	 * @return bool
	 */
	private function isBetaSurveyEnabled() {
		return $this->config->get( 'ReadingListsEnableBetaQuickSurvey' );
	}

	/** @inheritDoc */
	public function onResourceLoaderGetConfigVars( array &$vars, $skin, Config $config ): void {
		$vars['wgReadingListsEnableBetaQuickSurvey'] = $this->isBetaSurveyEnabled();
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
		if ( $type === 'signup' && $isFromReadingListsAccountCreationCta ) {
			// For the account creation reading list cta, add a URL parameter that will
			// be used to send an account_created event.
			$returnToQueryArray['readingListsAccountJustCreated'] = '1';

			// Turn off the homepage mobile discovery popover from GrowthExperiments.
			$user = RequestContext::getMain()->getUser();
			if ( $user && $user->isRegistered() ) {
				$this->userOptionsManager->setOption( $user, 'homepage_mobile_discovery_notice_seen', 1 );
				$this->userOptionsManager->saveOptions( $user );
			}
		}

		$returnToQuery = wfArrayToCgi( $returnToQueryArray );
		return true;
	}

	/**
	 * Configure QuickSurveys.
	 *
	 * @param array &$surveys
	 */
	public function onQuickSurveysEnabled( &$surveys ) {
		$enabled = $this->isBetaSurveyEnabled();

		$surveys[] = [
			'name' => self::SURVEY_NAME,
			'type' => 'internal',
			'enabled' => $enabled,
			'questions' => [
				[
					'name' => 'enjoyment',
					'question' => self::SURVEY_QUESTION,
					'layout' => 'single-answer',
					'answers' => [
						[ 'label' => self::SURVEY_ANSWER_POSITIVE ],
						[ 'label' => self::SURVEY_ANSWER_NEGATIVE ]
					],
					'shuffleAnswersDisplay' => false,
				],
			],
			"embedElementId" => "~",
			// Audience logic will be handled in a Vue component.
			'audience' => [],
			'privacyPolicy' => 'readinglists-betafeature-quicksurvey-privacy-policy',
			'coverage' => 100,
			'platforms' => [
				'desktop',
				'mobile'
			],
		];
	}
}
