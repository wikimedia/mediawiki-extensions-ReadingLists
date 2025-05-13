const api = require( 'ext.readingLists.api' );

const link = document.querySelector( '.reading-list-bookmark > a:first-child, a.reading-list-bookmark' );
const label = link !== null && link.classList.contains( 'mw-list-item' ) ? link.querySelector( '.toggle-list-item__label' ) : link;
const labelAdd = mw.msg( 'readinglists-add-bookmark' );
const labelRemove = mw.msg( 'readinglists-remove-bookmark' );

/**
 * Updates the bookmark button text and display an added/removed notification
 *
 * @param {boolean} isSaved
 * @param {number} listId
 */
function setBookmarkStatus( isSaved, listId ) {
	label.textContent = isSaved ? labelRemove : labelAdd;

	const msg = mw.message(
		isSaved ? 'readinglists-browser-add-entry-success' : 'readinglists-browser-remove-entry-success',
		window.location.origin + window.location.pathname,
		mw.config.get( 'wgTitle' ),
		mw.util.getUrl( 'Special:ReadingLists/' + mw.config.get( 'wgUserName' ) + '/' + listId ),
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
 * @param {number|null} [listId]
 * @return {Promise<void>}
 */
async function addPageToReadingList( listId = null ) {
	// If no list specified, fallback to user default
	if ( listId === null ) {
		listId = await api.getDefaultReadingList();
	}

	const { createentry: { entry } } = await api.createEntry( listId, mw.config.get( 'wgPageName' ) );

	// If there's a duplicate key it means the entry already exists, let's remove it instead
	// This is workaround until we can find an ID based on the project and page title
	if ( entry.duplicate === true ) {
		await removePageFromReadingList( entry.id, listId );
		return;
	}

	setBookmarkStatus( true, listId );
}

/**
 * Handles frontend logic for the api.deleteEntry() function
 *
 * @param {number} entryId
 * @param {number|null} [listId]
 * @return {Promise<void>}
 */
async function removePageFromReadingList( entryId, listId = null ) {
	await api.deleteEntry( entryId );

	// If no list specified, fallback to user default
	if ( listId === null ) {
		listId = await api.getDefaultReadingList();
	}

	setBookmarkStatus( false, listId );
}

async function initLabel() {
	if ( !mw.config.get( 'wgIsArticle' ) ) {
		return;
	}

	// FIXME: Workaround since we don't know whether the current page is on the default list.
	// This will trigger n + 9 / 10 requests where n is the number of reading lists a user has.
	// This is not suitable for a production environment in current form.
	// https://phabricator.wikimedia.org/T388834
	const isSaved = await api.getDefaultReadingList( mw.config.get( 'wgPageName' ) ) !== null;

	label.textContent = isSaved ? labelRemove : labelAdd;
	link.style.visibility = 'visible';

	link.addEventListener( 'click', async ( event ) => {
		event.preventDefault();
		await addPageToReadingList();
	} );
}

initLabel();
