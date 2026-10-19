const { defineStore } = require( 'pinia' );
const { ref } = require( 'vue' );

/**
 * Central store for the Special:ReadingLists page.
 */
const useReadingListsStore = defineStore( 'readingLists', () => {

	// State. ------------------------------------------------------------------

	const title = ref( '' );

	return {
		title
	};
} );

module.exports = exports = { useReadingListsStore };
