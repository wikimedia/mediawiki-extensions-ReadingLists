<template>
	<div
		class="reading-lists-items-wrapper"
		:class="{ 'reading-lists-items-wrapper--grid-view': !isListView }">
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
</template>

<script>
const { ref, computed } = require( 'vue' );
const CollectionItem = require( './CollectionItem.vue' );

/**
 * A single collection (reading list).
 */
// @vue/component
module.exports = exports = {
	name: 'Collection',
	components: {
		CollectionItem
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
		collectionTitle: {
			type: String,
			default: ''
		},
		isListView: {
			type: Boolean,
			default: false
		}
	},
	setup( props ) {
		const listRef = ref();
		const listClasses = computed( () => (
			{ 'reading-lists-items--grid-view': !props.isListView }
		) );
		const allItemsAriaLabel = computed( () => mw.msg( 'readinglists-customlists-allitems' ) );

		return {
			listRef,
			listClasses,
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
