<template>
	<div class="readinglists-nav-bar">
		<a
			class="readinglists-special-nav-link"
			:href="allItemsUrl"
			:class="{ 'readinglists-special-nav-link--active': isAllItems }">
			{{ allItemsText }}
		</a>

		<cdx-menu-button
			v-if="showDropdown"
			v-model:selected="selectedCollection"
			action="progressive"
			weight="quiet"
			:menu-items="collections"
			:menu-config="menuConfig"
			@click="maybeGetCollections"
			@load-more="maybeGetNextCollections">
			{{ collectionsText }}
			<cdx-icon size="medium" :icon="cdxIconExpand"></cdx-icon>
		</cdx-menu-button>

		<a
			v-else
			class="readinglists-special-nav-link"
			:class="{ 'readinglists-special-nav-link--active': isCollections }">
			{{ collectionsText }}
		</a>
	</div>
</template>

<script>
const { ref } = require( 'vue' );

const { CdxMenuButton, CdxIcon } = require( '../../../codex.js' );
const { cdxIconAdd, cdxIconExpand } = require( '../../../icons.json' );

const api = require( 'ext.readingLists.api' );

// how many collections to initially load, as well as how many to show at once
const collectionsPageSize = 8;

// codex menu item to be inserted in both default state and when list is populated
const createCollectionEntry = {
	label: mw.msg( 'readinglists-customlists-create-collection' ),
	value: 0,
	icon: cdxIconAdd
};

// default entry, to also be used when the user has no collections
const noCollectionsEntry = {
	label: mw.msg( 'readinglists-customlists-no-collections' ),
	value: -1,
	disabled: true
};

// helper function to turn api respose into select dropdown entries
const makeListEntries = ( lists ) => (
	lists.map( ( list ) => ( {
		label: list.name,
		value: list.id,
		// T432633 - with url as opposed to @update:selected handler click target is smaller
		url: mw.util.getUrl( `Special:ReadingLists/${ mw.user.getName() }/${ list.id }` )
	} ) )
);

// @vue/component
module.exports = exports = {
	components: { CdxMenuButton, CdxIcon },
	props: {
		showDropdown: {
			type: Boolean,
			required: true
		},
		isAllItems: {
			type: Boolean,
			required: true
		},
		isCollections: {
			type: Boolean
			// not required for now as this will be added in a follow up
			// required: true
		}
	},
	setup: () => {
		const allItemsUrl = mw.util.getUrl( `Special:ReadingLists/${ mw.user.getName() }` );
		const allItemsText = mw.msg( 'readinglists-customlists-allitems' );
		const collectionsText = mw.msg( 'readinglists-customlists-collections' );
		const menuConfig = { visibleItemLimit: collectionsPageSize };

		const collections = ref( [ noCollectionsEntry, createCollectionEntry ] );
		const selectedCollection = ref( null );
		const collectionsNext = ref( null );

		const maybeGetCollections = async () => {
			// if the list of collections has already been updated, no need to make another api call
			if ( collections.value[ 0 ].value !== -1 ) {
				return;
			}

			try {
				const result = await api.getLists( 'name', 'ascending', collectionsPageSize - 1 );

				collections.value = makeListEntries( result.lists );

				// put create collections CTA in first position
				collections.value.unshift( createCollectionEntry );

				// if the user has more lists, store the next value so we can load them on scroll
				if ( result.next ) {
					collectionsNext.value = result.next;
				}
			} catch ( err ) {
				// T434119 - unclear what we should do in this case besides fail silently
			}
		};

		// there's a world in which we refactor this and the above to be the same function, but for
		// now it's probably not worth being that clever
		const maybeGetNextCollections = async () => {
			if ( !collectionsNext.value ) {
				return;
			}

			try {
				const result = await api.getLists( 'name', 'ascending', collectionsPageSize, collectionsNext.value );

				collections.value = collections.value.concat( makeListEntries( result.lists ) );

				// overwrite collectionsNext regardless - either to the next value, or to null so we
				// know we've reached the end
				collectionsNext.value = result.next;
			} catch ( err ) {
				// T434119 as well
			}
		};

		return {
			cdxIconExpand,
			allItemsUrl,
			allItemsText,
			collectionsText,
			menuConfig,
			collections,
			selectedCollection,
			maybeGetCollections,
			maybeGetNextCollections
		};
	}
};
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

.readinglists-nav-bar {
	display: flex;
	gap: @spacing-50;
	align-items: center;
	margin-bottom: @spacing-100;

	a.readinglists-special-nav-link {
		// needed to explicitly specify no visited styles - I welcome a better way to do this
		&:visited,
		&:visited:hover {
			color: @color-progressive;
		}

		&--active {
			color: @color-base;
			font-weight: bold;

			&:visited,
			&:visited:hover {
				color: @color-base;
			}
		}
	}
}
</style>
