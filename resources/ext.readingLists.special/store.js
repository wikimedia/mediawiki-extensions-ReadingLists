const { defineStore } = require( 'pinia' );
const { ref } = require( 'vue' );
const api = require( 'ext.readingLists.api' );

const LIST_NOT_FOUND_ERRORS = [
	'badinteger',
	'readinglists-db-error-no-such-list',
	'readinglists-db-error-not-own-list',
	'readinglists-db-error-list-deleted'
];

/**
 * Central store for the Special:ReadingLists page.
 */
const useReadingListsStore = defineStore( 'readingLists', () => {

	// State. ------------------------------------------------------------------

	// Current-list metadata.
	const title = ref( '' );
	const description = ref( '' );
	const loadingInfo = ref( true );
	const isDefaultList = ref( true );
	const isAllListItems = ref( false );

	// Page-wide error message.
	const error = ref( '' );

	// Actions. ----------------------------------------------------------------

	/**
	 * Reset all view-specific state before loading a new route.
	 */
	function resetState() {
		title.value = '';
		description.value = '';
		loadingInfo.value = true;
		isDefaultList.value = true;
		isAllListItems.value = false;
		error.value = '';
	}

	/**
	 * Set the page-wide error message.
	 *
	 * @param {string|Error} err
	 */
	function handleError( err ) {
		if ( typeof err === 'string' ) {
			const key = LIST_NOT_FOUND_ERRORS.includes( err ) ?
				'readinglists-special-error-list-not-found' : err;
			error.value = mw.msg( key );
		} else {
			error.value = err.toString();
		}
	}

	/**
	 * Fetch metadata for the currently viewed list.
	 *
	 * @param {Object|null} imported Imported list data, if any
	 * @param {number|null} listId List ID, or null if this is all items
	 * @param {boolean} isCollections Whether this is the list of all collections
	 */
	async function fetchListInfo( imported, listId, isCollections ) {
		loadingInfo.value = true;

		try {
			if ( imported || listId ) {
				const list = imported || await api.getList( listId );

				if ( list.error !== undefined ) {
					throw list.error;
				}

				title.value = list.name;
				description.value = list.description;
				isDefaultList.value = !!list.default;
			} else if ( isCollections ) {
				isDefaultList.value = false;
				isAllListItems.value = false;
			} else {
				isDefaultList.value = false;
				isAllListItems.value = true;
			}
		} catch ( err ) {
			handleError( err );
		} finally {
			loadingInfo.value = false;
		}
	}

	return {
		// State.
		title,
		description,
		loadingInfo,
		isDefaultList,
		isAllListItems,
		error,

		// Actions.
		resetState,
		handleError,
		fetchListInfo
	};
} );

module.exports = exports = { useReadingListsStore };
