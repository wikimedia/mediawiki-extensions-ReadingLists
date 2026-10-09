<template>
	<div class="readinglists-collection-picker">
		<div
			v-if="collections.length === 0 && collectionsFetched"
			class="readinglists-collection-picker__prompt">
			<p>{{ noCollectionsMessage }}</p>
		</div>
		<button
			class="readinglists-collection-picker__create-button"
			@click="onCreateButtonClick"
		>
			{{ createButtonLabel }}
		</button>
		<ul class="readinglists-collection-picker__collections">
			<li
				v-for="collection in collections"
				:key="collection.id"
				class="readinglists-collection-picker__collection"
				@click="onAddToCollection( collection )"
			>
				{{ collection.name }}
				<cdx-button
					weight="quiet"
					:aria-label="getAddButtonLabel( collection.name )"
					@click="onAddToCollection( collection )"
				>
					<cdx-icon :icon="cdxIconAdd"></cdx-icon>
				</cdx-button>
			</li>
		</ul>
	</div>
</template>

<script>
const { ref, computed, onMounted } = require( 'vue' );
const { CdxButton, CdxIcon } = require( '../../codex.js' );
const { cdxIconAdd } = require( '../../icons.json' );
const api = require( 'ext.readingLists.api' );

/**
 * UI for creating or choosing a collection to add an article to.
 */
// @vue/component
module.exports = exports = {
	name: 'CollectionPicker',
	components: { CdxButton, CdxIcon },
	props: {
		/**
		 * Title of the article being saved.
		 */
		title: {
			type: String,
			required: true
		}
	},
	emits: [
		/**
		 * When the user clicks "Create collection".
		 */
		'create-collection',
		/**
		 * When the user clicks "add" for a collection.
		 *
		 * @param {number} listId - The ID of the collection.
		 * @param {string} listName - The name of the collection.
		 */
		'add-to-collection'
	],
	setup( props, { emit } ) {
		const noCollectionsMessage = computed( () => mw.msg( 'readinglists-customlists-collection-picker-create-prompt' ) );
		const createButtonLabel = computed( () => mw.msg( 'readinglists-customlists-create-collection-short' ) );
		function getAddButtonLabel( collectionTitle ) {
			return mw.msg( 'readinglists-customlists-collection-picker-add-button-label', props.title, collectionTitle );
		}

		const collections = ref( [] );
		const collectionsFetched = ref( false );

		async function getCollections() {
			try {
				const allLists = await api.getListsAll();
				collections.value = collections.value.concat( allLists );
				collectionsFetched.value = true;
			} catch ( err ) {
				// Do nothing for now.
			}
		}

		function onCreateButtonClick() {
			emit( 'create-collection' );
		}

		function onAddToCollection( collection ) {
			emit( 'add-to-collection', collection.id, collection.name );
		}

		onMounted( () => {
			getCollections();
		} );

		return {
			noCollectionsMessage,
			createButtonLabel,
			getAddButtonLabel,
			collections,
			collectionsFetched,
			onCreateButtonClick,
			onAddToCollection,
			cdxIconAdd
		};
	}
};
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

@collection-height: 42px;
@max-collections: 6;

.readinglists-collection-picker {
	// Override skin list styles. `.content` class needed for Minerva.
	&__collections {
		&,
		.content & {
			list-style: none;
			margin: 0;
			padding: 0;
			max-height: @collection-height * @max-collections;
		}
	}

	&__create-button,
	&__collection {
		border-top: @border-width-base @border-style-base @border-color-subtle;

		&:hover {
			cursor: @cursor-base--hover;
		}
	}

	&__create-button {
		.cdx-mixin-link();
		width: @size-full;
		// To match the list items, make height min button height + list item padding + top border.
		min-height: @min-size-interactive-pointer + ( @spacing-25 * 2 ) + @border-width-base;

		// Normalize button styles.
		background: 0;
		margin: 0;
		padding: 0;
		border: 0;
		font-family: inherit;
		font-size: @font-size-medium;
		font-weight: @font-weight-normal;
		text-align: left;
		text-transform: none;

		// The outline from the link mixin looks awful, use text decoration instead.
		&:focus-visible {
			outline: @outline-base--focus;
			text-decoration: @text-decoration-underline;
		}
	}

	&__collection {
		display: flex;
		justify-content: space-between;
		align-items: center;

		// Override skin list item styles. `.content` class needed for Minerva.
		&,
		.content & {
			margin: 0;
			padding: @spacing-25 0;
		}
	}
}
</style>
