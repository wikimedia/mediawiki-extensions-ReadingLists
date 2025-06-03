const isMinerva = mw.config.get( 'skin' ) === 'minerva';
const bookmark = document.querySelector( isMinerva ? '#ca-bookmark' : '#ca-bookmark > a' );

if ( bookmark === null ) {
	throw new Error( 'Bookmark not found' );
}

const api = require( 'ext.readingLists.api' );

const num = bookmark.children.length;
let label = null;
let icon = null;

if ( num === 0 ) {
	label = bookmark;
} else if ( num === 1 ) {
	label = bookmark.children[ 0 ];
} else {
	label = bookmark.children[ 1 ];
	icon = bookmark.children[ 0 ];
}

const iconPrefix = isMinerva ? 'minerva-icon--' : 'mw-ui-icon-';
const iconSolid = iconPrefix + 'bookmark';
const iconOutline = iconPrefix + 'bookmarkOutline';

/**
 * Updates the bookmark button text and display an added/removed notification
 *
 * @param {boolean} isSaved
 * @param {number} listId
 */
function setBookmarkStatus( isSaved, listId ) {
	if ( icon !== null ) {
		// The following CSS classes are used here:
		// * mw-ui-icon-bookmark
		// * mw-ui-icon-bookmarkOutline
		// * minerva-icon--bookmark
		// * minerva-icon--bookmarkOutline
		icon.classList.remove( isSaved ? iconOutline : iconSolid );

		// The following CSS classes are used here:
		// * mw-ui-icon-bookmark
		// * mw-ui-icon-bookmarkOutline
		// * minerva-icon--bookmark
		// * minerva-icon--bookmarkOutline
		icon.classList.add( isSaved ? iconSolid : iconOutline );
	}

	// The following messages are used here:
	// * readinglists-add-bookmark
	// * readinglists-remove-bookmark
	label.textContent = mw.msg( `readinglists-${ ( !isSaved ? 'add' : 'remove' ) }-bookmark` );

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

	mw.notification.notify( msg, { tag: 'saved', type: isSaved ? 'success' : 'info' } );
}

/**
 * Handles frontend logic for the api.createEntry() function
 *
 * @param {number} listId
 * @return {Promise<void>}
 */
async function addPageToReadingList( listId ) {
	const { createentry: { entry: { id } } } = await api.createEntry( listId, mw.config.get( 'wgPageName' ) );

	bookmark.dataset.mwEntryId = id;
	setBookmarkStatus( true, listId );
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

	delete bookmark.dataset.mwEntryId;
	setBookmarkStatus( false, listId );
}

bookmark.addEventListener( 'click', async ( event ) => {
	event.preventDefault();

	let listId = bookmark.dataset.mwListId;

	if ( !listId ) {
		try {
			const { setup: { list: { id } } } = await api.setup();
			listId = bookmark.dataset.mwListId = id;
		} catch ( err ) {
			mw.notification.notify(
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
		mw.notification.notify(
			mw.msg( 'readinglists-browser-error-intro', err ),
			{ tag: 'saved', type: 'error' }
		);

		throw err;
	}
} );
