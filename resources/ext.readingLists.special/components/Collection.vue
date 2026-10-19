<template>
	<div class="reading-lists-collection">
		<p v-if="collectionItems.length" class="reading-lists-sorting">
			{{ $i18n( 'readinglists-sorted-by-recent' ) }}
		</p>

		<div
			ref="listWrapperRef"
			class="reading-lists-items-wrapper"
			:class="listWrapperClasses"
		>
			<!-- role="list" needed for Safari support, refer to T435864. -->
			<ul
				v-if="!loadingInfo && collectionItems.length !== 0"
				ref="listRef"
				role="list"
				class="reading-lists-items"
				:class="listClasses"
				:aria-label="title || allItemsAriaLabel">
				<collection-item
					v-for="item in collectionItems"
					:key="item.id"
					:collection-data="item"
					:is-list-view="isListView"
				></collection-item>
			</ul>
		</div>

		<template v-if="!loadingInfo && !loadingEntries">
			<empty-list
				v-if="collectionItems.length === 0"
				:heading="msgEmptyListHeading"
				:call-to-action="msgEmptyListCTA"
				:image-url="emptyStateImageUrl"
			>
			</empty-list>

			<cdx-button v-else-if="hasMoreEntries" @click="$emit( 'show-more' )">
				{{ msgShowMore }}
			</cdx-button>
		</template>
	</div>
</template>

<script>
const { ref, computed } = require( 'vue' );
const { storeToRefs } = require( 'pinia' );
const { useReadingListsStore } = require( '../store.js' );
const { CdxButton } = require( '../../../codex.js' );
const CollectionItem = require( './CollectionItem.vue' );
const EmptyList = require( './EmptyList.vue' );

/**
 * A single collection (reading list).
 */
// @vue/component
module.exports = exports = {
	name: 'Collection',
	components: {
		CdxButton,
		CollectionItem,
		EmptyList
	},
	props: {
		collectionItems: {
			type: Array,
			required: true
		},
		loadingInfo: {
			type: Boolean,
			required: true
		},
		loadingEntries: {
			type: Boolean,
			required: true
		},
		isListView: {
			type: Boolean,
			default: false
		},
		isCustomList: {
			type: Boolean,
			default: false
		},
		hasMoreEntries: {
			type: Boolean,
			default: false
		}
	},
	emits: [ 'show-more' ],
	setup( props ) {
		const store = useReadingListsStore();
		const { title } = storeToRefs( store );

		const listWrapperRef = ref();
		const listWrapperClasses = computed( () => (
			{ 'reading-lists-items-wrapper--grid-view': !props.isListView }
		) );

		const listRef = ref();
		const listClasses = computed( () => (
			{ 'reading-lists-items--grid-view': !props.isListView }
		) );

		const msgShowMore = computed( () => mw.msg( 'readinglists-show-more' ) );
		const allItemsAriaLabel = computed( () => mw.msg( 'readinglists-customlists-allitems' ) );

		const msgEmptyListHeading = computed( () => mw.msg(
			props.isCustomList ?
				'readinglists-empty-collection-title' :
				'readinglists-empty-title'
		) );

		const msgEmptyListCTA = computed( () => mw.msg(
			props.isCustomList ?
				'readinglists-empty-collection-cta' :
				'readinglists-empty-cta'
		) );

		const emptyStateImageUrl = computed( () => mw.config.get( 'wgExtensionAssetsPath' ) +
			'/ReadingLists/resources/assets/collection-empty-state.svg' );

		return {
			title,
			listWrapperRef,
			listWrapperClasses,
			listRef,
			listClasses,
			msgShowMore,
			allItemsAriaLabel,
			msgEmptyListHeading,
			msgEmptyListCTA,
			emptyStateImageUrl
		};
	}
};
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

@min-width-card: @size-1200;

.reading-lists-items-wrapper {
	&--grid-view {
		display: grid;
		// Add as many cards to a row as possible & ensure cards don't shrink below their min-width.
		grid-template-columns: repeat( auto-fill, minmax( @min-width-card, 1fr ) );
		gap: @spacing-100;
	}

	// "Show more" button.
	+ .cdx-button {
		display: block;
		margin-top: @spacing-200;
		margin-left: auto;
		margin-right: auto;
	}

	p.reading-lists-sorting {
		margin: 0;
	}
}

.reading-lists-items {
	grid-column: 1 / -1;

	.content ul&,
	& {
		list-style: none;
		margin: 0;
		padding: 0;
	}

	&--grid-view {
		display: grid;
		grid-template-columns: inherit;
		gap: inherit;
	}
}
</style>
