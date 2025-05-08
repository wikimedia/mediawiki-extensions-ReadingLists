const isMinerva = mw.config.get( 'skin' ) === 'minerva';
const bookmark = document.querySelector( isMinerva ? '#ca-bookmark' : '#ca-bookmark > a' );

if ( bookmark === null ) {
	throw new Error( 'Bookmark not found' );
}

const api = require( 'ext.readingLists.api' );

const hasIcon = bookmark.children.length > 1;
const icon = hasIcon ? bookmark.children[ 0 ] : null;
const label = hasIcon ? bookmark.children[ 1 ] : bookmark.children[ 0 ];

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
		// eslint-disable-next-line mediawiki/class-doc
		icon.classList.remove( isSaved ? iconOutline : iconSolid );
		// eslint-disable-next-line mediawiki/class-doc
		icon.classList.add( isSaved ? iconSolid : iconOutline );
	}

	// eslint-disable-next-line mediawiki/msg-doc
	label.textContent = mw.msg( `readinglists-${ ( isSaved ? 'add' : 'remove' ) }-bookmark` );
	// eslint-disable-next-line mediawiki/msg-doc
	const msg = mw.message(
		`readinglists-browser-${ ( isSaved ? 'add' : 'remove' ) }-entry-success`,
		window.location.origin + window.location.pathname,
		mw.config.get( 'wgTitle' ),
		mw.util.getUrl( `Special:ReadingLists/${ listId }` ),
		mw.msg( 'readinglists-default-title' )
	).parseDom();

	// Hide the page link icon in the notification
	if ( msg.length > 0 ) {
		const a = msg[ 0 ];

		if ( a.nodeType === Node.ELEMENT_NODE ) {
			a.classList.remove( 'external' );
		}
	}

	mw.notification.notify( msg, { tag: 'saved' } );
}

/**
 * Handles frontend logic for the api.createEntry() function
 *
 * @param {number} listId
 * @return {Promise<void>}
 */
async function addPageToReadingList( listId ) {
	const { createentry: { entry } } = await api.createEntry( listId, mw.config.get( 'wgPageName' ) );
	bookmark.dataset.mwEntryId = entry.id;

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
		const { setup: { list: { id } } } = await api.setup();
		bookmark.dataset.mwListId = id;
		listId = id;
	}

	const entryId = bookmark.dataset.mwEntryId;

	if ( !entryId ) {
		await addPageToReadingList( listId );
	} else {
		await removePageFromReadingList( entryId, listId );
	}
} );
