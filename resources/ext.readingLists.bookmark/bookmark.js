const api = require( 'ext.readingLists.api' );

module.exports = function initBookmark( bookmark, isMinerva ) {
	// Assumes last <span> element is the label. Even if there is no label defined, the <span> must exist.
	const label = bookmark.lastElementChild;
	const icon = bookmark.querySelector( isMinerva ? '.minerva-icon' : '.vector-icon' );

	let iconSolid = isMinerva ? [ 'minerva-icon--bookmark' ] : [];
	let iconOutline = isMinerva ? [ 'minerva-icon--bookmarkOutline' ] : [];
	if ( !isMinerva ) {
		iconSolid = [ 'mw-ui-icon-bookmark', 'mw-ui-icon-wikimedia-bookmark' ];
		iconOutline = [ 'mw-ui-icon-bookmarkOutline', 'mw-ui-icon-wikimedia-bookmarkOutline' ];
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
	 * Updates the bookmark button text and display an added/removed notification
	 *
	 * @param {boolean} isSaved
	 * @param {number} listId
	 * @param {number} entryId
	 */
	function updateBookmarkStatus( isSaved, listId, entryId ) {
		// The following messages are used here:
		// * readinglists-browser-add-entry-success
		// * readinglists-browser-remove-entry-success
		const msg = mw.message(
			`readinglists-browser-${ ( isSaved ? 'add' : 'remove' ) }-entry-success`,
			mw.config.get( 'wgPageName' ),
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

		mw.notify( msg, { tag: 'saved', type: isSaved ? 'success' : 'info' } );

		/**
		 * Fires when the page saved status has changed.
		 *
		 * @event ~'readingLists.bookmark.edit'
		 * @memberof Hooks
		 * @param {boolean} isSaved
		 * @param {number} entryId
		 */
		mw.hook( 'readingLists.bookmark.edit' ).fire( isSaved, entryId );
	}

	/**
	 * Handles frontend logic for the api.createEntry() function
	 *
	 * @param {number} listId
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
	 * @param {number} listId
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

			let listId = bookmark.dataset.mwListId;

			if ( !listId ) {
				try {
					const { setup: { list: { id } } } = await api.setup();
					listId = bookmark.dataset.mwListId = id;
				} catch ( err ) {
					mw.notify(
						mw.msg( 'readinglists-browser-error-intro', err ),
						{ tag: 'saved', type: 'error' }
					);

					throw err;
				}
			}

			const entryId = bookmark.dataset.mwEntryId;

			try {
				if ( !entryId ) {
					await addPageToReadingList( listId );
				} else {
					await removePageFromReadingList( entryId, listId );
				}
			} catch ( err ) {
				mw.notify(
					mw.msg( 'readinglists-browser-error-intro', err ),
					{ tag: 'saved', type: 'error' }
				);

				throw err;
			}
		} );
	}

	function init() {
		// TODO: fix race condition when stickyHeader.js is still updating bookmark data attributes
		// resulting in an incorrect isSaved value
		const isSaved = !!bookmark.dataset.mwEntryId;
		setBookmarkStatus( isSaved, bookmark.dataset.mwEntryId );
		bindClickListener();

		mw.hook( 'readingLists.bookmark.edit' ).add( ( newSaved, entryId ) => {
			setBookmarkStatus( newSaved, entryId );
		} );
	}

	init();
};
