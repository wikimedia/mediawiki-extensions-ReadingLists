<template>
	<cdx-message v-if="error" type="error">
		{{ error }}
	</cdx-message>

	<template v-else>
		<slot v-if="$slots[ 'import-dialog' ]" name="import-dialog"></slot>

		<navigation-bar
			v-if="showNavBar"
			:show-dropdown="showNavDropdown"
			:is-all-items="isAllListItems">
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

		<template v-if="!loadingInfo">
			<ul
				v-if="entries.length !== 0"
				ref="container"
				class="reading-lists-items reading-lists-items--view-cards"
				:aria-label="title || msgAllItems">
				<li
					v-for="entry in entries"
					:key="entry.id">
					<entry-item :entry="entry"></entry-item>
				</li>
			</ul>

			<template v-if="!loadingEntries">
				<empty-list
					v-if="entries.length === 0"
					:is-custom-list="isCustomList">
				</empty-list>

				<cdx-button v-else-if="!infinite && next !== null" @click="onShowMore">
					{{ msgShowMore }}
				</cdx-button>
			</template>
		</template>

		<cdx-progress-bar
			v-if="loadingInfo || loadingEntries"
			:aria-label="msgLoading">
		</cdx-progress-bar>
	</template>
</template>

<script>
const { ref } = require( 'vue' );
const api = require( 'ext.readingLists.api' );
const { CdxButton, CdxMessage, CdxProgressBar } = require( '../../../codex.js' );
const { ReadingListsCustomLists } = require( '../config.json' );
const EmptyList = require( '../components/EmptyList.vue' );
const EntryItem = require( '../components/EntryItem.vue' );
const NavigationBar = require( '../components/NavigationBar.vue' );
const Survey = require( '../components/Survey.vue' );

const surveyStorageKey = 'readinglists-beta-survey';

// @vue/component
module.exports = exports = {
	components: {
		CdxButton,
		CdxMessage,
		CdxProgressBar,
		EmptyList,
		EntryItem,
		NavigationBar,
		Survey
	},
	props: {
		listId: {
			type: Number,
			default: null
		},
		imported: {
			type: Object,
			default: null
		}
	},
	setup() {
		return {
			loadingInfo: ref( true ),
			loadingEntries: ref( true ),
			title: ref( '' ),
			isDefaultList: ref( true ),
			isAllListItems: ref( false ),
			description: ref( '' ),
			error: ref( '' ),
			ready: ref( false ),
			sort: ref( 'updated' ),
			direction: ref( 'descending' ),
			entries: ref( [] ),
			next: ref( null ),
			infinite: ref( false ),
			msgAllItems: mw.msg( 'readinglists-customlists-allitems' ),
			msgLoading: mw.msg( 'readinglists-loading' ),
			msgShowMore: mw.msg( 'readinglists-show-more' ),
			showSurvey: ref( false ),
			// Throttled scroll listener. Deliberately not a ref: it only ever
			// holds a handler reference for add/removeEventListener.
			throttledScroll: null
		};
	},
	computed: {
		sortingText() {
			return mw.msg( 'readinglists-sorted-by-recent' );
		},
		showInPageTitle() {
			if ( this.imported ) {
				return true;
			}
			return !ReadingListsCustomLists &&
				!this.isDefaultList &&
				!this.isAllListItems;
		},
		// A custom list (collection): the custom lists experience is enabled and
		// we are viewing a specific, non-default list rather than the aggregate
		// all-items view or an imported list.
		isCustomList() {
			return ReadingListsCustomLists &&
				!this.imported &&
				!this.isDefaultList &&
				!this.isAllListItems;
		},
		showNavBar() {
			// if custom lists are enabled, display the nav bar for traversing them
			return ReadingListsCustomLists;
		},
		showNavDropdown() {
			return mw.config.get( 'skin' ) === 'vector-2022';
		}
	},
	methods: {
		handleError( err ) {
			if ( typeof err === 'string' ) {
				const listNotFoundErrors = [
					'badinteger',
					'readinglists-db-error-no-such-list',
					'readinglists-db-error-not-own-list',
					'readinglists-db-error-list-deleted'
				];
				const key = listNotFoundErrors.includes( err ) ?
					'readinglists-special-error-list-not-found' : err;

				this.error = mw.msg( key );
			} else {
				this.error = err.toString();
			}
		},
		async getList() {
			this.loadingInfo = true;

			try {
				if ( this.imported || this.listId ) {
					const list = this.imported || await api.getList( this.listId );

					if ( list.error !== undefined ) {
						throw list.error;
					}

					this.title = list.name;
					this.description = list.description;
					this.isDefaultList = !!list.default;
				} else {
					this.isDefaultList = false;
					this.isAllListItems = true;
				}
			} catch ( err ) {
				this.handleError( err );
			} finally {
				this.loadingInfo = false;
			}
		},
		async getEntries() {
			this.loadingEntries = true;

			try {
				let entries;
				let next = null;

				if ( this.imported === null ) {
					const query = await api.getEntries(
						this.listId,
						this.sort,
						this.direction,
						12,
						this.next,
						[ '@local' ]
					);
					entries = query.entries;
					next = query.next;
				} else if ( this.imported.error !== undefined ) {
					return;
				} else {
					entries = this.imported.list.slice();

					if ( this.sort === 'name' ) {
						entries.sort( ( a, b ) => a.title.localeCompare( b.title ) );
					}

					if ( this.direction === 'descending' ) {
						entries.reverse();
					}
				}

				this.entries.push( ...entries );
				this.next = next;

				if ( next === null ) {
					this.infinite = false;
				}
			} catch ( err ) {
				this.handleError( err );
			} finally {
				this.loadingEntries = false;
			}
		},
		unregisterScrollHandler() {
			if ( this.throttledScroll ) {
				document.removeEventListener( 'scroll', this.throttledScroll );
				this.throttledScroll = null;
			}
		},
		registerScrollHandler() {
			const handleScroll = () => {
				// `mw.util.throttle` always defers via `setTimeout` and has no cancel.
				// A pending call still fires after removal, when `$refs.container`
				// is already null.
				if ( !this.throttledScroll ) {
					return;
				}

				if (
					!this.error &&
					!this.loadingInfo &&
					!this.loadingEntries &&
					this.infinite &&
					this.next !== null &&
					this.$refs.container.getBoundingClientRect().bottom < window.innerHeight
				) {
					this.getEntries();
				} else if ( this.next === null ) {
					this.unregisterScrollHandler();
				}
			};
			this.throttledScroll = mw.util.throttle( handleScroll, 250 );
			document.addEventListener( 'scroll', this.throttledScroll );
		},
		async initializePage() {
			await this.getEntries();
			this.ready = true;
			this.registerScrollHandler();
			this.maybeShowSurvey();
		},
		async onShowMore() {
			this.infinite = true;
			await this.getEntries();
		},
		maybeShowSurvey() {
			// Don't show if the survey is not enabled or there are no saved pages.
			const enabled = mw.config.get( 'wgReadingListsEnableBetaQuickSurvey' );
			if ( !enabled || this.entries.length < 1 ) {
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
			this.showSurvey = true;
		},
		onSurveyCompleted() {
			mw.storage.set( surveyStorageKey, '~', 60 * 60 * 24 * 120 );
		}
	},
	async beforeUnmount() {
		this.unregisterScrollHandler();
	},
	async mounted() {
		// The list metadata (getList) and the entries request (initializePage → getEntries)
		// do not depend on each other, so fetch them in parallel instead of waiting for the
		// metadata round-trip before the entries request starts. Both methods handle their
		// own errors, so Promise.all does not reject.
		await Promise.all( [ this.getList(), this.initializePage() ] );
	}
};
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

@min-width-card: 20rem; // equal to 320px.
@max-width-card: 28rem; // equal to 448px. Note: alternatively up to `1fr`.
@max-width-card--mobile: 34rem; // equal to 544px.

.content ul.reading-lists-items,
.reading-lists-items {
	list-style: none;
	margin: 0;
	padding: 0;
}

.reading-lists-items--view-cards {
	display: grid;
	grid-auto-rows: 1fr;
	// Auto-fit to fit as many columns as possible in the row.
	// Minimum width of a column is 22rem equal to 352px.
	// Only for browsers which do _not_ support `:has()` below.
	// Support: Chrome ≤ 105, Edge ≤ 105, Firefox ≤ 120, Safari ≤ 15.3
	grid-template-columns: repeat( auto-fit, minmax( @min-width-card, @max-width-card ) );
	// Align all items to the start of the row.
	justify-content: start;
	gap: @spacing-100;
	// Default: Limit to 4 items maximum per row.
	// Note: Desktop and Desktop wide gets 4 items per row as well.
	max-width: calc( 4 * @max-width-card + 3 * @spacing-100 );
	margin-top: @spacing-75;

	// Mobile: Limit to 1 item maximum per row, but with higher grid container max width.
	@media screen and ( max-width: @max-width-breakpoint-mobile ) {
		grid-template-columns: repeat( auto-fit, minmax( @min-width-card, @max-width-card--mobile ) );
		max-width: calc( 2 * @max-width-card--mobile + 1 * @spacing-100 );
	}

	// Tablet: Limit to 2 items maximum per row.
	// Note: As of current `@max-width-breakpoint-tablet` is 1119px.
	@media screen and ( max-width: @max-width-breakpoint-tablet ) {
		max-width: calc( 2 * @max-width-card--mobile + 1 * @spacing-100 );
	}

	// More modern browsers supporting `:has()`.
	// Default: All items, no matter which number, get equal space via `1fr`.
	// Support: Chrome ≥ 105, Edge ≥ 105, Safari ≥ 15.4 , Firefox ≥ 121
	&:has( * ) {
		grid-template-columns: repeat( auto-fit, minmax( @min-width-card, 1fr ) );
	}

	// Match container with only 1 item `:has( > :nth-child( 1 ) )` and not more.
	&:has( :nth-child( 1 ) ):not( :has( :nth-child( 2 ) ) ) {
		max-width: @max-width-card--mobile;
	}

	// Match container with only 2 items and not more.
	&:has( :nth-child( 2 ) ):not( :has( :nth-child( 3 ) ) ) {
		max-width: calc( 2 * @max-width-card--mobile + 1 * @spacing-100 );

		@media screen and ( max-width: @max-width-breakpoint-mobile ) {
			grid-template-columns: repeat( auto-fit, minmax( @min-width-card, @max-width-card--mobile ) );
		}
	}

	// Match container with 3 items and not more.
	&:has( :nth-child( 3 ) ):not( :has( :nth-child( 4 ) ) ) {
		grid-template-columns: repeat( auto-fit, minmax( @min-width-card, 1fr ) );
	}

	// Match container with 4 items and more.
	/* stylelint-disable-next-line no-descending-specificity */
	&:has( :nth-child( 4 ) ) {
		max-width: calc( 4 * @max-width-card--mobile + 3 * @spacing-100 );
	}

	// "Show more" button.
	+ .cdx-button {
		display: block;
		margin-top: @spacing-200;
		margin-left: auto;
		margin-right: auto;
	}
}

</style>
