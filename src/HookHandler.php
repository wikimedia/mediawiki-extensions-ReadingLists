<?php

namespace MediaWiki\Extension\ReadingLists;

use MediaWiki\Api\ApiQuerySiteinfo;
use MediaWiki\Api\Hook\APIQuerySiteInfoGeneralInfoHook;
use MediaWiki\Config\Config;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\BetaFeatures\BetaFeatures;
use MediaWiki\Extension\ReadingLists\Service\BookmarkEntryLookupService;
use MediaWiki\Extension\TestKitchen\Sdk\ExperimentManager;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\ResourceLoader\Hook\ResourceLoaderGetConfigVarsHook;
use MediaWiki\Skin\Hook\SkinTemplateNavigation__UniversalHook;
use MediaWiki\Skin\SkinTemplate;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\CentralId\CentralIdLookupFactory;
use MediaWiki\User\Options\UserOptionsLookup;
use MediaWiki\User\Options\UserOptionsManager;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityUtils;
use MediaWiki\WikiMap\WikiMap;
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
		private readonly ReadingListRepositoryFactory $readingListRepositoryFactory,
		private readonly BookmarkEntryLookupService $bookmarkEntryLookupService,
		private readonly UserOptionsLookup $userOptionsLookup,
		private readonly UserOptionsManager $userOptionsManager,
		private readonly CentralIdLookupFactory $centralIdLookupFactory,
		private readonly UserIdentityUtils $userIdentityUtils,
		private ?ExperimentManager $experimentManager = null
	) {
	}

	public function setExperimentManager( ExperimentManager $experimentManager ): void {
		$this->experimentManager = $experimentManager;
	}

	/**
	 * Get whether the user is assigned a group in an experiment.
	 *
	 * @param string $experimentName
	 * @param string $group
	 * @return bool|null
	 */
	public function isAssignedGroup( $experimentName, $group ) {
		if ( !$this->experimentManager ) {
			return null;
		}
		$experiment = $this->experimentManager->getExperiment( $experimentName );
		return $experiment->isAssignedGroup( $group );
	}

	/**
	 * Adds a hidden preference, accessed via api. The preference indicates user eligibility
	 * for showing the ReadingLists bookmark icon button in supported skins.
	 *
	 * @param User $user User whose preferences are being modified.
	 * @param array[] &$preferences Preferences description array, to be fed to a HTMLForm object.
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public function onGetPreferences( $user, &$preferences ) {
		$preferences += [
			'readinglists-web-ui-enabled' => [
				'type' => 'api',
			],
		];
	}

	/**
	 * Handler for SkinTemplateNavigation::Universal hook.
	 * Adds "Notifications" items to the notifications content navigation.
	 * SkinTemplate automatically merges these into the personal tools for older skins.
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/SkinTemplateNavigation::Universal
	 * @param SkinTemplate $sktemplate
	 * @param array &$links Array of URLs to append to.
	 * @throws ReadingListRepositoryException
	 */
	public function onSkinTemplateNavigation__Universal( $sktemplate, &$links ): void {
		if ( !self::isSkinSupported( $sktemplate->getSkinName() ) ) {
			return;
		}

		$user = $sktemplate->getUser();
		$readingListsEnabledForUser = $this->isReadingListsEnabledForUser( $user );
		$inAccountCreationCtaTreatment = false;
		if ( $readingListsEnabledForUser ) {
			$this->addSpecialPageLinkToUserMenu( $user, $sktemplate, $links );
		} elseif ( $user->isRegistered() ) {
			// Don't show bookmark to logged-in or temp users not opted into ReadingLists.
			return;
		} elseif (
			$sktemplate->getSkinName() !== 'minerva' ||
			!$this->isAssignedGroup( 'account-creation-reading-list-cta', 'treatment' )
		) {
			// Don't show bookmark for logged-out users not in the CTA experiment treatment group.
			// Experiment is MinervaNeue-only.
			return;
		} else {
			$inAccountCreationCtaTreatment = true;
		}

		$centralId = $this->centralIdLookupFactory->getLookup()
			->centralIdFromLocalUser( $user );

		$repository = null;
		$defaultListId = null;
		if ( $centralId ) {
			$repository = $this->readingListRepositoryFactory->create( $centralId );
			// The default list is created lazily when the user saves their first page
			// (the client-side bookmark code calls the setup API if no list exists yet),
			// so there is no need to eagerly create it here on page view. See T427944.
			$defaultListId = $repository->getDefaultListIdForUser() ?: null;
		}

		$output = $sktemplate->getOutput();
		$output->addModuleStyles( 'ext.readingLists.bookmark.icons' );

		if ( !$output->isArticle() ) {
			return;
		}

		// NOTE: Non-existent pages still have a Title object.
		// It should be rare that the Title is null here, but we should still check.
		$title = $output->getTitle();
		if ( !$title || $title->getNamespace() !== NS_MAIN ) {
			return;
		}

		$list = null;
		$matchingList = null;
		$hasCustomListEntry = false;

		if ( $repository !== null && $defaultListId !== null ) {
			$list = $repository->selectValidList( $defaultListId );
			$status = $this->bookmarkEntryLookupService->getBookmarkEntryStatus(
				$title,
				$centralId
			);
			// On failure, fall through with no match so the button
			// renders in its default "unsaved" state rather than disappearing.
			$matchingList = $status->isOK() ? $status->getValue() : null;

			if ( $matchingList !== null ) {
				$listsByPage = $repository->getListsByPage(
					'@local',
					$title->getPrefixedDBkey(),
					2,
					null,
					false
				);
				foreach ( $listsByPage as $pageList ) {
					if ( $matchingList === null || $pageList->rl_is_default ) {
						$matchingList = $pageList;
					}
					if ( !$pageList->rl_is_default ) {
						$hasCustomListEntry = true;
					}
				}
			}
		}

		// If the list id is null, then list setup occurs async in bookmark.js.
		// When a user saves their first page, these attributes are updated accordingly
		// after list setup.
		$links['views']['bookmark'] = [
			'text' => $sktemplate->msg(
				'readinglists-' . ( $matchingList === null ? 'add' : 'remove' ) . '-bookmark'
			)->text(),
			'icon' => $matchingList === null ? 'bookmarkOutline' : 'bookmark',
			'href' => '#',
			'data-mw-list-id' => $list ? $list->rl_id : null,
			'data-mw-saved' => $matchingList !== null ? 1 : null,
			'data-mw-in-custom-list' => $hasCustomListEntry ? 1 : null,
			'link-class' => 'reading-lists-bookmark',
			'single-id' => $matchingList === null ? 'ca-bookmark-add' : 'ca-bookmark-remove',
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

		if ( $inAccountCreationCtaTreatment ) {
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

		$links['user-menu'] = ArrayUtils::insertAfter( $userMenu, [
			'readinglists' => [
				'text' => $sktemplate->msg( 'readinglists-menu-item' )->text(),
				'href' => $specialPageUrl,
				'icon' => 'bookmarkList',
			],
		], $insertAfter );
	}

	private function isReadingListsEnabledForUser( UserIdentity $user ): bool {
		if ( $this->userIdentityUtils->isTemp( $user ) ) {
			return false;
		}

		$betaFeatureIsAvailable = $this->config->get( 'ReadingListBetaFeature' ) &&
			ExtensionRegistry::getInstance()->isLoaded( 'BetaFeatures' );

		if ( $betaFeatureIsAvailable ) {
			return BetaFeatures::isFeatureEnabled( $user, Constants::PREF_KEY_BETA_FEATURES );
		}

		$hiddenPreferenceEnabled = $this->userOptionsLookup->getOption(
			$user,
			Constants::PREF_KEY_WEB_UI_ENABLED
		) === '1';

		$wikiId = WikiMap::getCurrentWikiId();
		// NOTE: These need to be the same as the experiment names
		// defined in WikimediaEvents, in readingListAB.js.
		$experimentName = $wikiId === 'enwiki'
			? 'we-3-3-4-reading-list-test1-en'
			: 'we-3-3-4-reading-list-test1';
		$inReadingListABTreatment = $this->isAssignedGroup( $experimentName, 'treatment' );

		return $hiddenPreferenceEnabled && $inReadingListABTreatment;
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
			// For the experiment (account-creation-reading-list-cta), add a URL parameter that will
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
