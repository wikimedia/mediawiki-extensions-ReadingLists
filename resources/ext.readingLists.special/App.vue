<template>
	<!--
		Keyed on the route path so navigating between the all-items view and a
		specific list forces a remount, which re-runs the fetch in Entries'
		mounted() hook.
	-->
	<router-view :key="$route.path"></router-view>
</template>

<script>
const { watch } = require( 'vue' );
const { RouterView } = require( 'vue-router' );
const { storeToRefs } = require( 'pinia' );
const { useReadingListsStore } = require( './store.js' );

// @vue/component
module.exports = exports = {
	name: 'App',
	components: {
		RouterView
	},
	setup() {
		const titleElement = document.querySelector( '.reading-lists-title-text' );
		if ( !titleElement ) {
			return;
		}

		const { title, loadingInfo } = storeToRefs( useReadingListsStore() );

		// Keep the page heading and document title in sync with the current list.
		// Watching the store covers both in-app navigation and browser back/forward,
		// where the new list's title is only known once Entries has fetched it.
		watch( [ title, loadingInfo ], ( [ newTitle, loading ] ) => {
			// Entries clears the title on mount before fetching it again; skip that
			// empty value so the heading doesn't flash back to the default.
			if ( !newTitle && loading ) {
				return;
			}
			const titleMsg = newTitle ?
				mw.msg( 'readinglists-special-custom-list-title', newTitle ) :
				mw.msg( 'readinglists-special-subpage-title' );
			titleElement.textContent = titleMsg;
			document.title = mw.msg( 'pagetitle', titleMsg );
		} );
	}
};
</script>
