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
				:aria-label="collectionTitle || allItemsAriaLabel">
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
				:is-custom-list="isCustomList">
			</empty-list>

			<cdx-button v-else-if="hasMoreEntries" @click="$emit( 'show-more' )">
				{{ msgShowMore }}
			</cdx-button>
		</template>
	</div>
</template>

<script>
const { ref, computed } = require( 'vue' );
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
		collectionTitle: {
			type: String,
			default: ''
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

		return {
			listWrapperRef,
			listWrapperClasses,
			listRef,
			listClasses,
			msgShowMore,
			allItemsAriaLabel
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
