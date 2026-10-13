<template>
	<cdx-message v-if="error" type="error">
		{{ error }}
	</cdx-message>

	<template v-else>
		<slot v-if="$slots[ 'import-dialog' ]" name="import-dialog"></slot>

		<create-collection-dialog
			v-if="showCreateCollection"
			@update:open="( newVal ) => showCreateCollection = newVal"
			@success="routeToNewCollection"
		>
		</create-collection-dialog>

		<navigation-bar
			v-if="showNavBar"
			:is-custom-list="isCustomList"
			@create-collection="showCreateCollection = true"
		>
		</navigation-bar>

		<template v-if="showInPageTitle">
			<h2 v-if="title" class="reading-lists-title">
				{{ title }}
			</h2>

			<p v-if="description" class="reading-lists-description">
				{{ description }}
			</p>
		</template>

		<survey v-if="showSurvey" @survey-completed="onSurveyCompleted"></survey>

		<p v-if="entries.length" class="reading-lists-sorting">
			{{ sortingText }}
		</p>

		<all-collections
			v-if="isCollections"
			:collections="entries"
			:loading-info="loadingInfo"
			@create-collection="showCreateCollection = true"
		></all-collections>

		<collection
			v-else
			ref="collectionRef"
			:collection-items="entries"
			:loading-info="loadingInfo"
			:collection-title="title"
			:is-list-view="isListView">
		</collection>

		<template v-if="!loadingInfo && !loadingEntries">
			<empty-list
				v-if="entries.length === 0"
				:is-custom-list="isCustomList">
			</empty-list>

			<cdx-button v-else-if="next !== null" @click="onShowMore">
				{{ msgShowMore }}
			</cdx-button>
		</template>

		<cdx-progress-bar
			v-if="loadingInfo || loadingEntries"
			:aria-label="msgLoading">
		</cdx-progress-bar>
	</template>
</template>

<script>
const { ref, computed, nextTick, onMounted } = require( 'vue' );
const { useRouter } = require( 'vue-router' );
const { useIsMobileResolution, getCollectionUrl, CreateCollectionDialog } = require( 'ext.readingLists.common' );
const api = require( 'ext.readingLists.api' );
const { CdxButton, CdxMessage, CdxProgressBar } = require( '../../../codex.js' );
const EmptyList = require( '../components/EmptyList.vue' );
const AllCollections = require( '../components/AllCollections.vue' );
const Collection = require( '../components/Collection.vue' );
const NavigationBar = require( '../components/NavigationBar.vue' );
const Survey = require( '../components/Survey.vue' );
const surveyStorageKey = 'readinglists-beta-survey';
const MIN_PAGE_SIZE = 12;

// @vue/component
module.exports = exports = {
	components: {
		CdxButton,
		CdxMessage,
		CdxProgressBar,
		CreateCollectionDialog,
		EmptyList,
		AllCollections,
		Collection,
		NavigationBar,
		Survey
	},
	props: {
		isCollections: {
			type: Boolean,
			default: false
		},
		listId: {
			type: Number,
			default: null
		},
		imported: {
			type: Object,
			default: null
		},
		isCustomListsEnabled: {
			type: Boolean,
			default: false
		}
	},
	setup( props ) {
		const router = useRouter();

		const loadingInfo = ref( true );
		const loadingEntries = ref( true );
		const title = ref( '' );
		const isDefaultList = ref( true );
		const isAllListItems = ref( false );
		const description = ref( '' );
		const error = ref( '' );
		const ready = ref( false );
		const sort = ref( 'updated' );
		const direction = ref( 'descending' );
		const entries = ref( [] );
		const next = ref( null );
		const msgShowMore = mw.msg( 'readinglists-show-more' );
		const msgLoading = mw.msg( 'readinglists-loading' );
		const showSurvey = ref( false );
		const isListView = useIsMobileResolution();
		const collectionRef = ref();
		const showCreateCollection = ref( false );

		const sortingText = computed( () => mw.msg( 'readinglists-sorted-by-recent' ) );

		const showInPageTitle = computed( () => {
			if ( props.imported ) {
				return true;
			}
			return !props.isCustomListsEnabled &&
				!isDefaultList.value &&
				!isAllListItems.value;
		} );

		// A custom list (collection): the custom lists experience is enabled and
		// we are viewing a specific, non-default list rather than the aggregate
		// all-items view or an imported list.
		const isCustomList = computed( () => props.isCustomListsEnabled &&
				!props.imported &&
				!isDefaultList.value &&
				!isAllListItems.value &&
				!props.isCollections
		);

		const showNavBar = computed( () => {
			// if custom lists are enabled, display the nav bar for traversing them
			if ( !props.isCustomListsEnabled ) {
				return false;
			}

			// we only want to show the nav bar once the interface (incl. entries) have loaded, but
			// don't want it to disappear if we load more entries
			if ( loadingEntries.value && entries.value.length === 0 ) {
				return false;
			}

			return true;
		} );

		function handleError( err ) {
			if ( typeof err === 'string' ) {
				const listNotFoundErrors = [
					'badinteger',
					'readinglists-db-error-no-such-list',
					'readinglists-db-error-not-own-list',
					'readinglists-db-error-list-deleted'
				];
				const key = listNotFoundErrors.includes( err ) ?
					'readinglists-special-error-list-not-found' : err;

				error.value = mw.msg( key );
			} else {
				error.value = err.toString();
			}
		}

		async function getList() {
			loadingInfo.value = true;

			try {
				if ( props.imported || props.listId ) {
					const list = props.imported || await api.getList( props.listId );

					if ( list.error !== undefined ) {
						throw list.error;
					}

					title.value = list.name;
					description.value = list.description;
					isDefaultList.value = !!list.default;
				} else if ( props.isCollections ) {
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

		function getColumnCount() {
			// eslint-disable-next-line es-x/no-optional-chaining
			if ( isListView.value || !collectionRef.value?.$el ) {
				return 1;
			}

			// Measure the wrapper before the list is rendered; the list inherits its grid.
			const tracks = getComputedStyle( collectionRef.value.$el ).gridTemplateColumns;

			return tracks ? tracks.split( ' ' ).length : 1;
		}

		function getPageSize() {
			// Load at least MIN_PAGE_SIZE entries, plus enough to fill the last row.
			const columns = getColumnCount();
			const total = entries.value.length + MIN_PAGE_SIZE;

			return Math.ceil( total / columns ) * columns - entries.value.length;
		}

		async function getEntries() {
			loadingEntries.value = true;

			try {
				let newEntries;
				let newNext = null;

				if ( props.isCollections ) {
					const lists = await api.getListsAll();
					newEntries = lists.map( ( { name, id, size } ) => (
						{
							id,
							title: name,
							description: mw.msg( 'readinglists-total-articles', size ),
							url: mw.util.getUrl( getCollectionUrl( id, name ) )
						}
					) );
				} else if ( props.imported === null ) {
					const query = await api.getEntries(
						props.listId,
						sort.value,
						direction.value,
						getPageSize(),
						next.value,
						[ '@local' ]
					);
					newEntries = query.entries;
					newNext = query.next;
				} else if ( props.imported.error !== undefined ) {
					return;
				} else {
					newEntries = props.imported.list.slice();

					if ( sort.value === 'name' ) {
						newEntries.sort( ( a, b ) => a.title.localeCompare( b.title ) );
					}

					if ( direction.value === 'descending' ) {
						newEntries.reverse();
					}
				}

				entries.value.push( ...newEntries );
				next.value = newNext;
			} catch ( err ) {
				handleError( err );
			} finally {
				loadingEntries.value = false;
			}
		}

		async function initializePage() {
			await getEntries();
			ready.value = true;
			maybeShowSurvey();
		}

		async function onShowMore() {
			// eslint-disable-next-line es-x/no-optional-chaining
			const list = collectionRef.value?.listRef;
			// eslint-disable-next-line es-x/no-optional-chaining
			const lastEntry = list?.lastElementChild;
			await getEntries();
			await nextTick();
			// eslint-disable-next-line es-x/no-optional-chaining
			const nextEntry = lastEntry?.nextElementSibling?.firstElementChild;
			if ( nextEntry ) {
				nextEntry.focus();
			}
		}

		function maybeShowSurvey() {
			// Don't show if the survey is not enabled or there are no saved pages.
			const enabled = mw.config.get( 'wgReadingListsEnableBetaQuickSurvey' );
			if ( !enabled || entries.value.length < 1 ) {
				return;
			}

			// This token is either a count of the number of times the user has seen the survey, or
			// a ~ if they have completed the survey.
			const betaSurveyToken = mw.storage.get( surveyStorageKey ) || 0;
			if ( betaSurveyToken === '~' ) {
				return;
			}

			// Show survey a max of 10 times.
			const betaSurveyTokenAsNumber = Number( betaSurveyToken );
			if ( betaSurveyTokenAsNumber > 10 ) {
				return;
			}

			// Store key for ~4 months, slightly longer than the beta feature period.
			mw.storage.set( surveyStorageKey, betaSurveyTokenAsNumber + 1, 60 * 60 * 24 * 120 );
			showSurvey.value = true;
		}

		function onSurveyCompleted() {
			mw.storage.set( surveyStorageKey, '~', 60 * 60 * 24 * 120 );
		}

		function routeToNewCollection( id, name ) {
			const collectionUrl = getCollectionUrl( id, name, true );
			router.push( collectionUrl );
			showCreateCollection.value = false;
		}

		onMounted( async () => {
			// The list metadata (getList) and the entries request (initializePage → getEntries)
			// do not depend on each other, so fetch them in parallel instead of waiting for the
			// metadata round-trip before the entries request starts. Both methods handle their
			// own errors, so Promise.all does not reject.
			await Promise.all( [ getList(), initializePage() ] );
		} );

		return {
			loadingInfo,
			loadingEntries,
			title,
			description,
			error,
			entries,
			next,
			msgShowMore,
			msgLoading,
			showSurvey,
			isListView,
			collectionRef,
			sortingText,
			showInPageTitle,
			isCustomList,
			showNavBar,
			onShowMore,
			onSurveyCompleted,
			showCreateCollection,
			routeToNewCollection
		};
	}
};
</script>
