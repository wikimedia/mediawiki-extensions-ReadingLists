const { initBookmark, initOnboardingPopover } = require( './bookmark.js' );
const skinName = mw.config.get( 'skin' );
const isMinerva = skinName === 'minerva';
const bookmarks = document.querySelectorAll( isMinerva ? '#ca-bookmark' : '.reading-lists-bookmark' );

if ( bookmarks.length === 0 ) {
	throw new Error( 'Bookmark not found' );
}

bookmarks.forEach( ( bookmarkElement ) => {
	// ReadingsLists instrument: T414368
	// This is for the ReadingList long-term instrument to know which bookmark the user clicked
	let eventSource = 'toolbar';

	if ( bookmarkElement.id === 'ca-bookmark-sticky-header' ) {
		eventSource = 'sticky_header';
	} else if ( bookmarkElement.closest( '#ca-more-bookmark' ) ) {
		eventSource = 'tool_menu';
	}

	initBookmark( bookmarkElement, isMinerva, eventSource );
} );

if ( !( skinName === 'vector-2022' || skinName === 'minerva' ) ) {
	return;
}

const moduleName = isMinerva ?
	'ext.readingLists.onboarding.mobile' :
	'ext.readingLists.onboarding.desktop';

const bookmarkForOnboarding = document.querySelector( '#ca-bookmark' );
const isMainPage = mw.config.get( 'wgIsMainPage' );

if ( bookmarkForOnboarding && !bookmarkForOnboarding.dataset.mwEntryId && !isMainPage ) {
	initOnboardingPopover(
		'#ca-bookmark',
		'readinglists-bookmark-dialog-seen',
		'readinglists-onboarding-title',
		'readinglists-onboarding-text',
		mw.config.get( 'wgExtensionAssetsPath' ) + '/ReadingLists/resources/assets/onboarding-save.svg',
		moduleName
	);
}
