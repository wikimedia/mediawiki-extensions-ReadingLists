const api = require( 'ext.readingLists.api' );

const listId = mw.config.get( 'rlListId' );
let entryId = mw.config.get( 'rlEntryId' );

const pageName = mw.config.get( 'wgPageName' );
const pageTitle = mw.config.get( 'wgTitle' );

const isMinerva = mw.config.get( 'skin' ) === 'minerva';
const iconPrefix = isMinerva ? 'minerva-icon--' : 'mw-ui-icon-';
const iconSolid = iconPrefix + 'bookmark';
const iconOutline = iconPrefix + 'bookmarkOutline';

const labels = [];
const icons = [];

/**
 * Display a successful notification and update buttons content.
 */
function updateState() {
	mw.config.set( 'rlEntryId', entryId );
	const isSaved = entryId !== null;

	// The following messages are used here:
	// * readinglists-browser-add-entry-success
	// * readinglists-browser-remove-entry-success
	const msg = mw.message(
		'readinglists-browser-' + ( isSaved ? 'add' : 'remove' ) + '-entry-success',
		window.location.origin + window.location.pathname,
		pageTitle,
		mw.util.getUrl( 'Special:ReadingLists/' + listId ),
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

	for ( const label of labels ) {
		// The following messages are used here:
		// * readinglists-add-bookmark
		// * readinglists-remove-bookmark
		label.textContent = mw.msg( 'readinglists-' + ( isSaved ? 'remove' : 'add' ) + '-bookmark' );
	}

	for ( const icon of icons ) {
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
}

/**
 * Create or delete list entry based on the existing state.
 */
async function toggleBookmark() {
	try {
		entryId = entryId === null ?
			await api.createEntry( listId, pageName ) :
			await api.deleteEntry( entryId );
	} catch ( err ) {
		mw.notify(
			mw.msg( 'readinglists-browser-error-intro', err ),
			{ tag: 'saved', type: 'error' }
		);

		throw err;
	}

	updateState();
}

const buttons = document.querySelectorAll(
	'#ca-bookmark > a, #ca-more-bookmark > a, a#ca-bookmark'
);

for ( /** @type {Element} */ const button of buttons ) {
	const count = button.children.length;

	if ( count === 0 ) {
		labels.push( button );
	} else if ( count === 1 ) {
		labels.push( button.children[ 0 ] );
	} else {
		labels.push( button.children[ 1 ] );
		icons.push( button.children[ 0 ] );
	}

	button.addEventListener( 'click', toggleBookmark );
}
