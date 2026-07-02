const api = require( 'ext.readingLists.api' );

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
		label.textContent = mw.msg( `readinglists-${ ( !isSaved ? 'add' : 'remove' ) }-bookmark` );

		// The following messages are used here:
		// * tooltip-ca-bookmark-add
		// * tooltip-ca-bookmark-remove
		bookmark.title = mw.msg( `tooltip-ca-bookmark-${ ( !isSaved ? 'add' : 'remove' ) }` );
	}

	/**
	 * Updates the bookmark button text and display an added/removed notification
	 *
	 * @param {boolean} isSaved
	 */
	function updateBookmarkStatus( isSaved ) {
		// The following messages are used here:
		// * readinglists-browser-add-entry-success
		// * readinglists-browser-remove-entry-success
		const msg = mw.message(
			`readinglists-browser-${ ( isSaved ? 'add' : 'remove' ) }-entry-success`,
			mw.config.get( 'wgTitle' ),
			`Special:ReadingLists/${ mw.user.getName() }`,
			mw.msg( 'readinglists-default-title' )
		);

		const popoverStorageKey = 'readinglists-saved-pages-dialog-seen';

		if ( isSaved && !mw.storage.get( popoverStorageKey ) ) {
			initSavedPagesOnboardingPopover();
		} else {
			// The following CSS classes are used here:
			// * mw-notification-tag-saved
			// * mw-notification-type-success
			// * mw-notification-type-notice
			mw.notify( msg, {
				tag: 'saved',
				type: isSaved ? 'success' : 'notice'
			} );
		}

		/**
		 * Fires when the page saved status has changed.
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
			'readinglists-saved-pages-dialog-seen',
			config.titleMsgKey,
			config.bodyMsgKey,
			config.bannerImagePath,
			config.moduleName
		);
	}

	/**
	 * Handles frontend logic for the api.createEntry() function
	 *
	 * @param {string} listId
	 * @return {Promise<void>}
	 */
	async function addPageToReadingList( listId ) {
		await api.createEntry( listId, mw.config.get( 'wgPageName' ) );

		updateBookmarkStatus( true );
	}

	/**
	 * Handles frontend logic for removing a page from a reading list
	 *
	 * @param {string} pageTitle
	 * @return {Promise<void>}
	 */
	async function removePageFromReadingList( pageTitle ) {
		try {
			await api.deleteEntryByPageTitle( pageTitle );
		} catch ( err ) {
			if ( err !== 'readinglists-db-error-list-entry-deleted' ) {
				throw err;
			}
		}

		updateBookmarkStatus( false );
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
	 * Update the bookmarkList menu item URL to point to the user's default list,
	 * to be called once the user has saved their first page. This will update the reading
	 * list icon visible besides the user menu dropdown and the saved pages menu item that
	 * is shown at lower resolutions.
	 *
	 * @param {string} listId
	 */
	function updateBookmarkListMenuUrl( listId ) {
		const bookmarkUrl = mw.util.getUrl( `Special:ReadingLists/${ mw.user.getName() }/${ listId }` );
		const links = document.querySelectorAll( '#pt-readinglists a, #pt-readinglists-2 a' );

		for ( let i = 0; i < links.length; i++ ) {
			links[ i ].href = bookmarkUrl;
		}
	}

	/**
	 * Binds a click listener to the bookmark element
	 */
	async function bindClickListener() {
		bookmark.addEventListener( 'click', async ( event ) => {
			event.preventDefault();

			// Use the current listId from the bookmark dataset,
			// or get it from setup if not available
			let currentListId = bookmark.dataset.mwListId;

			if ( !currentListId ) {
				try {
					const { setup: { list: { id } } } = await api.setup();
					currentListId = bookmark.dataset.mwListId = id;
					updateBookmarkListMenuUrl( currentListId );
				} catch ( err ) {
					// The following messages are used here:
					// * readinglists-browser-error-intro
					// * readinglists-db-error-list-entry-deleted
					mw.notify(
						mw.msg( 'readinglists-browser-error-intro', getErrorMessage( err ) ),
						{ tag: 'saved', type: 'error' }
					);

					throw err;
				}
			}

			const inCustomList = bookmark.dataset.mwInCustomList === '1';
			const pageTitle = mw.config.get( 'wgPageName' );

			try {
				if ( bookmark.dataset.mwSaved !== '1' ) {
					await addPageToReadingList( currentListId );
				} else {
					if ( inCustomList ) {
						const confirmed = await confirmUnsaveFromCustomList( bookmark );
						if ( !confirmed ) {
							return;
						}
					}
					await removePageFromReadingList( pageTitle, currentListId );
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

	if ( mw.storage.get( storageKey ) ) {
		return;
	}

	// T421942 - we don't want these dialogues to overlap, so give precedence to the user homepage
	if ( !mw.user.options.get( 'growthexperiments-tour-homepage-discovery' ) ) {
		return;
	}

	setTimeout( () => {
		mw.requestIdleCallback( () => {
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
		}, { timeout: 2000 } );
	}, 1000 );
}

module.exports = { initBookmark, initOnboardingPopover };
