const initBookmark = require( './bookmark.js' );
const skinName = mw.config.get( 'skin' );
const isMinerva = skinName === 'minerva';
const bookmarks = document.querySelectorAll( isMinerva ? '#ca-bookmark' : '.reading-lists-bookmark' );

if ( bookmarks.length === 0 ) {
	throw new Error( 'Bookmark not found' );
}

bookmarks.forEach( ( bookmarkElement ) => {
	// ReadingsLists experiments T397532
	// This is for the ReadingList experiment to know which bookmark the user clicked
	// ToDo: Remove after experiment ends
	let eventSource = 'toolbar';

	if ( bookmarkElement.id === 'ca-bookmark-sticky-header' ) {
		eventSource = 'sticky_header';
	} else if ( bookmarkElement.closest( '#ca-more-bookmark' ) ) {
		eventSource = 'page_tools';
	}

	initBookmark( bookmarkElement, isMinerva, eventSource );
} );

/**
 * Initializes the onboarding popover.
 *
 * @param {string} anchorSelector CSS selector for the element to anchor popover to.
 */
function initOnboardingPopover( anchorSelector ) {
	mw.loader.using( 'ext.readingLists.onboarding' ).then( ( require ) => {
		const { initOnboardingPopover: initPopover } = require( 'ext.readingLists.onboarding' );
		initPopover(
			anchorSelector,
			'readinglists-bookmark-dialog-seen',
			'readinglists-onboarding-title',
			'readinglists-onboarding-text',
			mw.config.get( 'wgExtensionAssetsPath' ) + '/ReadingLists/resources/assets/onboarding-save.svg',
			mw.config.get( 'skin' )
		);
	} );
}

if ( skinName === 'vector-2022' || skinName === 'minerva' ) {
	initOnboardingPopover( '#ca-bookmark' );
}
