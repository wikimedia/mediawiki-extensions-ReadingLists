const api = require( 'ext.readingLists.api' );

/**
 * @type {Object<string, number>}
 */
const currentReadingListSize = {};

module.exports = function initBookmark( bookmark, isMinerva, eventSource ) {
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

	const listCount = bookmark.dataset.mwListPageCount;
	const bookmarkListId = bookmark.dataset.mwListId;
	// Track initial size of list.
	if ( listCount && bookmarkListId ) {
		currentReadingListSize[ bookmarkListId ] = parseInt( bookmark.dataset.mwListPageCount, 10 );
	}

	/**
	 * Updates the bookmark button text and display an added/removed notification
	 *
	 * @param {boolean} isSaved
	 * @param {number} entryId
	 */
	function setBookmarkStatus( isSaved, entryId ) {
		// Update entryId data attribute
		if ( entryId ) {
			bookmark.dataset.mwEntryId = entryId;
		} else {
			delete bookmark.dataset.mwEntryId;
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
	}

	/**
	 * Updates the mw-list-page-count data attribute with the reading list page count
	 *
	 * @param {string} listId
	 * @param {boolean} isSaved
	 * @return {number} -1 if the reading list size is not known
	 */
	function updateListCount( listId, isSaved ) {
		if ( !currentReadingListSize[ listId ] ) {
			return -1;
		} else {
			currentReadingListSize[ listId ] += ( isSaved ? 1 : -1 );
			return currentReadingListSize[ listId ];
		}
	}

	/**
	 * Updates the bookmark button text and display an added/removed notification
	 *
	 * @param {boolean} isSaved
	 * @param {string} listId
	 * @param {number} entryId
	 */
	function updateBookmarkStatus( isSaved, listId, entryId ) {
		const listPageCount = updateListCount( listId, isSaved );

		// The following messages are used here:
		// * readinglists-browser-add-entry-success
		// * readinglists-browser-remove-entry-success
		const msg = mw.message(
			`readinglists-browser-${ ( isSaved ? 'add' : 'remove' ) }-entry-success`,
			mw.config.get( 'wgTitle' ),
			`Special:ReadingLists/${ mw.user.getName() }/${ listId }`,
			mw.msg( 'readinglists-default-title' )
		).parseDom();

		// Hide the page link icon in the notification
		if ( msg.length > 0 ) {
			const a = msg[ 0 ];

			if ( a.nodeType === Node.ELEMENT_NODE ) {
				a.classList.remove( 'external' );
			}
		}

		// The following CSS classes are used here:
		// * mw-notification-tag-saved
		// * mw-notification-type-success
		// * mw-notification-type-notice
		mw.notify( msg, {
			tag: 'saved',
			type: isSaved ? 'success' : 'notice'
		} );

		/**
		 * Fires when the page saved status has changed.
		 *
		 * @event readingLists.bookmark.edit
		 * @memberof mw.Hooks
		 * @param {boolean} isSaved
		 * @param {number} entryId
		 * @param {number} newListSize
		 * @param {string} eventSource
		 */
		mw.hook( 'readingLists.bookmark.edit' ).fire( isSaved, entryId, listPageCount, eventSource );
	}

	/**
	 * Handles frontend logic for the api.createEntry() function
	 *
	 * @param {string} listId
	 * @return {Promise<void>}
	 */
	async function addPageToReadingList( listId ) {
		const { createentry: { entry: { id } } } = await api.createEntry( listId, mw.config.get( 'wgPageName' ) );

		updateBookmarkStatus( true, listId, id );
	}

	/**
	 * Handles frontend logic for the api.deleteEntry() function
	 *
	 * @param {number} entryId
	 * @param {string} listId
	 * @return {Promise<void>}
	 */
	async function removePageFromReadingList( entryId, listId ) {
		try {
			await api.deleteEntry( entryId );
		} catch ( err ) {
			if ( err !== 'readinglists-db-error-list-entry-deleted' ) {
				throw err;
			}
		}

		updateBookmarkStatus( false, listId, null );
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
				} catch ( err ) {
					// The following messages are used here:
					// * readinglists-browser-error-intro
					// * readinglists-db-error-list-entry-deleted
					mw.notify(
						mw.msg( 'readinglists-browser-error-intro', mw.msg( err ) ),
						{ tag: 'saved', type: 'error' }
					);

					throw err;
				}
			}

			const entryId = bookmark.dataset.mwEntryId;

			try {
				if ( !entryId ) {
					await addPageToReadingList( currentListId );
				} else {
					await removePageFromReadingList( entryId, currentListId );
				}
			} catch ( err ) {
				// The following messages are used here:
				// * readinglists-browser-error-intro
				// * readinglists-db-error-list-entry-deleted
				mw.notify(
					mw.msg( 'readinglists-browser-error-intro', mw.msg( err ) ),
					{ tag: 'saved', type: 'error' }
				);

				throw err;
			}
		} );
	}

	function init() {
		const isSaved = !!bookmark.dataset.mwEntryId;
		setBookmarkStatus( isSaved, bookmark.dataset.mwEntryId );
		bindClickListener();

		mw.hook( 'readingLists.bookmark.edit' ).add( ( newSaved, entryId ) => {
			setBookmarkStatus( newSaved, entryId );
		} );
	}

	init();
};
