<template>
	<div v-if="!loadingInfo && !loadingEntries" class="reading-lists-all-collections">
		<template v-if="collections.length !== 0">
			<create-collection-button
				v-if="isListView"
				class="reading-lists-all-collections__create-button"
				@create-collection="onCreateButtonClick"
			></create-collection-button>
			<!-- role="list" needed for Safari support, refer to T435864. -->
			<ul
				role="list"
				class="reading-lists-all-collections__list"
				:class="listClasses"
				:aria-label="ariaLabel"
			>
				<li
					v-for="collection in collections"
					:key="collection.id"
					class="reading-lists-all-collections__list-item"
				>
					<cdx-card
						class="reading-lists-all-collections__list-item__card"
						:url="collection.url"
						:separation="isListView ? 'divider' : 'outline'"
						@click="( e ) => onNavigate( e, collection )"
					>
						<template #title>
							{{ collection.title }}
						</template>
						<template v-if="collection.description" #description>
							{{ collection.description }}
						</template>
					</cdx-card>
				</li>
			</ul>
		</template>
		<template v-else>
			<empty-list
				class="reading-lists-all-collections__empty-state"
				:heading="msgEmptyListHeading"
				:image-url="emptyStateImageUrl"
			>
				<cdx-button
					class="reading-lists-all-collections__empty-state__create-button"
					weight="quiet"
					action="progressive"
					@click="onCreateButtonClick"
				>
					{{ createButtonLabel }}
				</cdx-button>
			</empty-list>
		</template>
	</div>
</template>

<script>
const { computed } = require( 'vue' );
const { useRouter } = require( 'vue-router' );
const { storeToRefs } = require( 'pinia' );
const { useReadingListsStore } = require( '../store.js' );
const { CdxButton, CdxCard } = require( '../../../codex.js' );
const { CreateCollectionButton } = require( 'ext.readingLists.common' );
const { collectionsBaseUrl } = require( 'ext.readingLists.api' );
const EmptyList = require( './EmptyList.vue' );

/**
 * @typedef {import('../../types.js').CollectionWithTitle} CollectionWithTitle
 */

/**
 * List of all collections on Special:Saved/collections.
 */
// @vue/component
module.exports = exports = {
	name: 'AllCollections',
	components: {
		CdxButton,
		CdxCard,
		CreateCollectionButton,
		EmptyList
	},
	props: {
		collections: {
			type: Array,
			required: true
		},
		loadingEntries: {
			type: Boolean,
			required: true
		},
		/**
		 * Whether to display the cards as a list with simple dividers (as opposed to a grid).
		 */
		isListView: {
			type: Boolean,
			default: false
		}
	},
	emits: [
		'create-collection'
	],
	setup( props, { emit } ) {
		const router = useRouter();
		const store = useReadingListsStore();
		const { title, loadingInfo } = storeToRefs( store );

		const listClasses = computed( () => ( {
			[ `reading-lists-all-collections__list--${ props.isListView ? 'list' : 'grid' }-view` ]: true
		} ) );

		const ariaLabel = computed( () => mw.msg( 'readinglists-customlists-collections' ) );

		const msgEmptyListHeading = computed( () => mw.msg( 'readinglists-customlists-no-collections' ) );
		const emptyStateImageUrl = computed( () => mw.config.get( 'wgExtensionAssetsPath' ) +
			'/ReadingLists/resources/assets/all-collections-empty-state.svg' );
		const createButtonLabel = computed( () => mw.msg( 'readinglists-customlists-create-collection-short' ) );

		function onCreateButtonClick() {
			emit( 'create-collection' );
		}

		function getUrlSection( url ) {
			return url.slice(
				mw.util.getUrl( collectionsBaseUrl ).length
			);
		}

		/**
		 * Handle card click.
		 *
		 * @param {Event} e The click event
		 * @param {CollectionWithTitle} collection
		 */
		function onNavigate( e, collection ) {
			// Allow the link click to work normally if the user modified the click, e.g. to open
			// in a new tab.
			if ( e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0 ) {
				return;
			}

			e.preventDefault();
			title.value = collection.title;
			router.push( getUrlSection( collection.url ) );
		}

		return {
			loadingInfo,
			listClasses,
			ariaLabel,
			msgEmptyListHeading,
			emptyStateImageUrl,
			createButtonLabel,
			onCreateButtonClick,
			onNavigate
		};
	}
};
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

@min-width-card: @size-1600;
@min-height-card: @size-600;

.reading-lists-all-collections {
	&__list,
	&__list-item {
		// Reset list styles from Minerva.
		&,
		.content & {
			list-style: none;
			padding: 0;
			margin: 0;
		}
	}

	&__list {
		&--list-view {
			.cdx-card__text__title {
				font-size: @font-size-large;
				font-weight: @font-weight-normal;
			}
		}

		&--grid-view {
			display: grid;
			// Add as many cards to a row as possible & ensure cards don't shrink below their
			// min-width.
			grid-template-columns: repeat( auto-fill, minmax( @min-width-card, 1fr ) );
			gap: @spacing-100;

			// These styles make the cards full width and height within their li element.
			.reading-lists-all-collections__list-item {
				display: flex;
				min-height: @min-height-card;

				&__card {
					width: @size-full;
				}
			}
		}
	}

	&__list-item {
		// Simulate divider separation styles from Codex. The style in Codex expects `.cdx-card`
		// elements to be direct siblings, while we wrap ours in `<li>`s.
		& + & > .cdx-card--separation-divider {
			border-top: @border-base;
		}

		.cdx-card__text__description {
			font-size: @font-size-small;
			margin-top: @spacing-50;
		}
	}

	&__create-button {
		min-height: @size-400;
		border-top: @border-base;
		border-bottom: @border-base;
	}

	&__empty-state__create-button.cdx-button {
		margin-top: @spacing-200;
	}
}

</style>
