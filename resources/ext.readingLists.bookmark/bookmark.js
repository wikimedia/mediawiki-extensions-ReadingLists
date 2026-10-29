const api = require( 'ext.readingLists.api' );
const ONBOARDING_STORAGE_KEY = 'readinglists-saved-pages-dialog-seen';

// this needs to match AUTOSAVE_STORAGE_KEY in BookmarkCta.vue
const AUTOSAVE_STORAGE_KEY = 'readinglists-cta-autosave';

const { ReadingListsCustomLists } = require( './config.json' );
const { getCollectionUrl } = api;

function getErrorMessage( err ) {
	if ( typeof err === 'string' ) {
		return mw.msg( err );
	}

	if ( err && typeof err.message === 'string' ) {
		return err.message;
	}

	return String( err );
}

function initBookmark( bookmark, isMinerva, eventSource ) {
	// Assumes last <span> element is the label.
	// Even if there is no label defined, the <span> must exist.
	const label = bookmark.lastElementChild;
	const icon = bookmark.querySelector( isMinerva ? '.minerva-icon' : '.vector-icon' );
	const pageName = mw.config.get( 'wgPageName' );

	let iconSolid = isMinerva ? [ 'minerva-icon--bookmark' ] : [];
	let iconOutline = isMinerva ? [ 'minerva-icon--bookmarkOutline' ] : [];
	if ( !isMinerva ) {
		iconSolid = [ 'mw-ui-icon-bookmark', 'mw-ui-icon-wikimedia-bookmark' ];
		iconOutline = [ 'mw-ui-icon-bookmarkOutline', 'mw-ui-icon-wikimedia-bookmarkOutline' ];
	}

	/**
	 * Updates the bookmark button text and icon
	 *
	 * @param {boolean} isSaved
	 */
	function setBookmarkStatus( isSaved ) {
		if ( isSaved ) {
			bookmark.dataset.mwSaved = '1';
		} else {
			delete bookmark.dataset.mwSaved;
		}

		if ( icon !== null ) {
			// The following CSS classes are used here:
			// * mw-ui-icon-bookmark
			// * mw-ui-icon-bookmarkOutline
			// * minerva-icon--bookmark
			// * minerva-icon--bookmarkOutline
			icon.classList.remove( ...( isSaved ? iconOutline : iconSolid ) );

			// The following CSS classes are used here:
			// * mw-ui-icon-bookmark
			// * mw-ui-icon-bookmarkOutline
			// * minerva-icon--bookmark
			// * minerva-icon--bookmarkOutline
			icon.classList.add( ...( isSaved ? iconSolid : iconOutline ) );
		}

		// The following messages are used here:
		// * readinglists-add-bookmark
		// * readinglists-remove-bookmark
		// * readinglists-manage-bookmark
		// * tooltip-ca-bookmark-add
		// * tooltip-ca-bookmark-remove
		// * tooltip-ca-bookmark-manage
		if ( ReadingListsCustomLists && isSaved ) {
			label.textContent = mw.msg( 'readinglists-manage-bookmark' );
			bookmark.title = mw.msg( 'tooltip-ca-bookmark-manage' );
		} else {
			label.textContent = mw.msg( `readinglists-${ ( !isSaved ? 'add' : 'remove' ) }-bookmark` );
			bookmark.title = mw.msg( `tooltip-ca-bookmark-${ ( !isSaved ? 'add' : 'remove' ) }` );
		}
	}

	/**
	 * Updates the bookmark button text via a hook and may display an added/removed notification or
	 * the onboarding dialog.
	 *
	 * @param {boolean} isSaved Whether the article is now saved to a reading list
	 */
	function updateBookmarkStatus( isSaved ) {
		/**
		 * Fires when the page saved status has changed.
		 *
		 * @deprecated Use readingLists.bookmark.change instead.
		 *
		 * @event readingLists.bookmark.edit
		 * @memberof mw.Hooks
		 * @param {boolean} isSaved
		 * @param {null} entryId Deprecated, always null.
		 * @param {null} listPageCount Deprecated, always null.
		 * @param {string} eventSource
		 */
		mw.hook( 'readingLists.bookmark.edit' ).fire( isSaved, null, null, eventSource );

		/**
		 * Fires when the page saved status has been updated.
		 *
		 * @event readingLists.bookmark.change
		 * @memberof mw.Hooks
		 * @param {boolean} isSaved
		 * @param {string} eventSource
		 */
		mw.hook( 'readingLists.bookmark.change' ).fire( isSaved, eventSource );
	}

	let activeNotification;
	/**
	 * Maybe show an mw.notification or the onboarding dialog after a user has saved or unsaved
	 * a page.
	 *
	 * An mw.notification is not needed in some cases, like when the user just saves a page to
	 * their default list, because they already see a success message in the bookmark popover.
	 *
	 * @param {boolean} isSaved Whether the article is now saved to a reading list
	 * @param {boolean} showNotification Whether to show an mw.notification
	 * @param {boolean|null} [isError] Whether an error occurred.
	 * @param {number|null} [listId] The ID of the collection the article was added to
	 * @param {string|null} [listName] The name of the collection the article was added to
	 */
	function showPostChangeMessaging( isSaved, showNotification, isError, listId = null, listName = null ) {
		const homepageDiscoveryPreference = isMinerva ?
			'homepage_mobile_discovery_notice_seen' :
			'growthexperiments-tour-homepage-discovery';

		if ( isError ) {
			mw.notify( mw.msg( 'readinglists-browser-error' ), {
				type: 'error'
			} ).then( ( notification ) => {
				activeNotification = notification;
			} );
			return;
		}
		// Show the onboarding popover only if the user has saved an article, if they haven't seen
		// the popover before, and if the homepage discovery popover has been dismissed
		// to ensure the popovers don't overlap (T421942).
		// If GrowthExperiments is unavailable, set a fallback for the preference.
		// If the onboarding popover is not displayed, show a confirmation toast notification.
		if (
			isSaved &&
			!mw.storage.get( ONBOARDING_STORAGE_KEY ) &&
			mw.user.options.get( homepageDiscoveryPreference, 1 )
		) {
			initSavedPagesOnboardingPopover();
		} else if ( showNotification ) {
			const route = getCollectionUrl( listId, listName );
			const listNameParam = listName || mw.msg( 'readinglists-default-title' );

			// The following messages are used here:
			// * readinglists-browser-add-entry-success
			// * readinglists-browser-remove-entry-success
			// * readinglists-customlists-add-entry-success
			const msg = mw.message(
				`readinglists-${ ( listId ? 'customlists' : 'browser' ) }-${ ( isSaved ? 'add' : 'remove' ) }-entry-success`,
				mw.config.get( 'wgTitle' ),
				route,
				listNameParam
			);

			// The following CSS classes are used here:
			// * mw-notification-tag-saved
			// * mw-notification-type-success
			// * mw-notification-type-notice
			mw.notify( msg, {
				tag: 'saved',
				type: isSaved ? 'success' : 'notice'
			} ).then( ( notification ) => {
				activeNotification = notification;
			} );
		}
	}

	function initSavedPagesOnboardingPopover() {
		const skinConfig = {
			minerva: {
				anchorSelector: '.minerva-user-menu',
				titleMsgKey: 'readinglists-mobile-onboarding-saved-pages-title',
				bodyMsgKey: 'readinglists-mobile-onboarding-saved-pages-text',
				bannerImagePath: null,
				moduleName: 'ext.readingLists.onboarding.mobile'
			},
			'vector-2022': {
				anchorSelector: '#pt-readinglists-2',
				titleMsgKey: 'readinglists-onboarding-saved-pages-title',
				bodyMsgKey: 'readinglists-onboarding-saved-pages-text',
				bannerImagePath: mw.config.get( 'wgExtensionAssetsPath' ) +
					'/ReadingLists/resources/assets/onboarding-saved-list.svg',
				moduleName: 'ext.readingLists.onboarding.desktop'
			}
		};

		const skinName = mw.config.get( 'skin' );
		const config = skinConfig[ skinName ];
		if ( !config ) {
			return;
		}

		initOnboardingPopover(
			config.anchorSelector,
			ONBOARDING_STORAGE_KEY,
			config.titleMsgKey,
			config.bodyMsgKey,
			config.bannerImagePath,
			config.moduleName
		);
	}

	/**
	 * Handles frontend logic for the api.createEntry() function
	 *
	 * @return {Promise<void>}
	 */
	async function addPageToReadingList() {
		// Close any existing notifications.
		if ( activeNotification ) {
			activeNotification.close();
		}

		// Save the page and change the bookmark button.
		await api.saveToDefaultList( pageName );
		updateBookmarkStatus( true );

		if ( ReadingListsCustomLists ) {
			const { isSaved, showNotification, isError, listId, listName } = await launchBookmarkPopover( true );

			// Handle case where user immediately unsaves page.
			if ( !isSaved ) {
				updateBookmarkStatus( isSaved );
			}

			showPostChangeMessaging( isSaved, showNotification, isError, listId, listName );
		} else {
			showPostChangeMessaging( true, true );
		}
	}

	/**
	 * Saves the page if the user is returning from login or create account
	 * via the logged-out bookmark CTA button.
	 *
	 * @param {string} pageNameToSave
	 * @return {Promise<void>}
	 */
	async function saveAfterCta( pageNameToSave ) {
		mw.storage.session.remove( AUTOSAVE_STORAGE_KEY );

		if ( pageNameToSave !== pageName || bookmark.dataset.mwSaved === '1' ) {
			return;
		}

		try {
			await addPageToReadingList();
		} catch ( err ) {
			mw.log.error( 'Failed to save page after log in or account creation:', err );
		}
	}

	/**
	 * Handles frontend logic for removing a page from a reading list
	 *
	 * @return {Promise<void>}
	 */
	async function removePageFromReadingList() {
		const inCustomList = bookmark.dataset.mwInCustomList === '1';

		if ( inCustomList ) {
			const confirmed = await confirmUnsaveFromCustomList( bookmark );
			if ( !confirmed ) {
				return;
			}
		}

		try {
			await api.deleteEntryByPageTitle( pageName );
		} catch ( err ) {
			if ( err !== 'readinglists-db-error-list-entry-deleted' ) {
				throw err;
			}
		}

		updateBookmarkStatus( false );
		showPostChangeMessaging( false, true );
	}

	/**
	 * Handle bookmark clicks when the page is currently saved.
	 */
	async function configurePageInReadingList() {
		// Close any existing notifications.
		if ( activeNotification ) {
			activeNotification.close();
		}

		const { isSaved, showNotification, isError, listId, listName } = await launchBookmarkPopover( false );

		// If the page has been unsaved, update the bookmark button.
		if ( !isSaved ) {
			updateBookmarkStatus( isSaved );
		}

		// Show notifications (e.g. page added to collection, page unsaved from all collections).
		showPostChangeMessaging( isSaved, showNotification, isError, listId, listName );
	}

	/**
	 * Shows the save/un-save popover.
	 *
	 * @param {boolean} isNewSave Whether the page was just saved for the first time
	 * @return {Promise<boolean>} Returns whether an mw.notification should display on dismiss.
	 */
	async function launchBookmarkPopover( isNewSave ) {
		const listsWithPage = await api.getListsWithTitle( pageName );
		const collectionsWithPage = listsWithPage.filter( ( list ) => !list.default );

		return await mw.loader.using( [ 'ext.readingLists.bookmark.bookmarkPopover' ] )
			.then( () => {
				const bookmarkPopoverModule = require( 'ext.readingLists.bookmark.bookmarkPopover' );
				return bookmarkPopoverModule.initBookmarkPopover( isNewSave, collectionsWithPage );
			} )
			.catch( ( error ) => {
				mw.log.error( 'Error loading ext.readingLists.bookmark.bookmarkPopover module:', error );
				// Fall back to the mw.notification. The page remains saved regardless of the flow.
				return { isSaved: true, showNotification: true };
			} );
	}

	/**
	 * Shows the confirmation popover for unsaving a page from a custom reading list
	 *
	 * @param {Element} anchorElement
	 * @return {Promise<boolean>}
	 */
	async function confirmUnsaveFromCustomList( anchorElement ) {
		await mw.loader.using( 'ext.readingLists.bookmark.confirmPopover' );
		const confirmPopoverModule = require( 'ext.readingLists.bookmark.confirmPopover' );

		return confirmPopoverModule.confirmUnsaveFromCustomList( anchorElement, isMinerva );
	}

	/**
	 * Preloads the confirmation popover for unsaving a page
	 * that is in at least one custom reading list, to avoid
	 * delay loading the popover when the user clicks the unsave button.
	 */
	function preloadConfirmDialog() {
		mw.requestIdleCallback( () => {
			mw.loader.using( 'ext.readingLists.bookmark.confirmPopover' );
		}, { timeout: 1000 } );
	}

	/**
	 * Binds a click listener to the bookmark element
	 */
	async function bindClickListener() {
		let isProcessing = false;
		bookmark.addEventListener( 'click', async ( event ) => {
			event.preventDefault();

			if ( isProcessing ) {
				return;
			}
			isProcessing = true;

			try {
				if ( bookmark.dataset.mwSaved !== '1' ) {
					await addPageToReadingList();
				} else if ( ReadingListsCustomLists ) {
					await configurePageInReadingList();
				} else {
					await removePageFromReadingList();
				}
			} catch ( err ) {
				// The following messages are used here:
				// * readinglists-browser-error-intro
				// * readinglists-db-error-list-entry-deleted
				mw.notify(
					mw.msg( 'readinglists-browser-error-intro', getErrorMessage( err ) ),
					{ tag: 'saved', type: 'error' }
				);

				throw err;
			} finally {
				isProcessing = false;
			}
		} );
	}

	function init() {
		setBookmarkStatus( bookmark.dataset.mwSaved === '1' );

		if ( bookmark.dataset.mwInCustomList === '1' ) {
			const anchorElement = document.querySelector( '#ca-bookmark' );
			preloadConfirmDialog( anchorElement );
		}

		bindClickListener();

		mw.hook( 'readingLists.bookmark.edit' ).add( ( newSaved ) => {
			setBookmarkStatus( newSaved );
		} );

		const pageNameToSave = mw.storage.session.get( AUTOSAVE_STORAGE_KEY );
		if ( !pageNameToSave ) {
			return;
		}

		saveAfterCta( pageNameToSave );
	}

	init();
}

/**
 * Initializes the onboarding popover by loading the appropriate skin-specific module.
 *
 * @param {string} anchorSelector CSS selector for the element to anchor popover to.
 * @param {string} storageKey Local storage key for popover display status.
 * @param {string} titleMsgKey i18n message key for popover title.
 * @param {string} bodyMsgKey i18n message key for popover body text.
 * @param {string|null} bannerImagePath Path to banner image (desktop only, null for mobile).
 * @param {string} moduleName Resource loader module name to load.
 */
function initOnboardingPopover(
	anchorSelector,
	storageKey,
	titleMsgKey,
	bodyMsgKey,
	bannerImagePath,
	moduleName
) {
	const targetElement = document.querySelector( anchorSelector );

	if ( !targetElement ) {
		return;
	}

	mw.loader.using( moduleName ).then( () => {
		const mountAppFn = mw.loader.require( moduleName );
		try {
			mountAppFn( {
				target: targetElement,
				storageKey,
				titleMsgKey,
				bodyMsgKey,
				bannerImagePath
			} ).catch( ( error ) => {
				mw.log.error( 'Failed to mount onboarding popover:', error );
			} );
		} catch ( error ) {
			mw.log.error( 'Failed to mount onboarding popover:', error );
		}
	} );
}

module.exports = { initBookmark, initOnboardingPopover };
