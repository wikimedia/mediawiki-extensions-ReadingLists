<template>
	<div class="readinglists-collection-picker">
		<div
			v-if="collections.length === 0 && collectionsFetched"
			class="readinglists-collection-picker__prompt">
			<p>{{ noCollectionsMessage }}</p>
		</div>
		<button
			class="readinglists-collection-picker__unsave-button"
			@click="onUnsaveButtonClick"
		>
			{{ unsaveMessage }}
		</button>
		<create-collection-button
			class="readinglists-collection-picker__create-button"
			@create-collection="onCreateButtonClick"
		></create-collection-button>
		<ul class="readinglists-collection-picker__collections">
			<li
				v-for="collection in collections"
				:key="collection.id"
				class="readinglists-collection-picker__collection"
				@click="( e ) => onSelectCollection( e, collection )"
			>
				{{ collection.name }}
				<cdx-button
					weight="quiet"
					:aria-label="getAddButtonLabel( collection )"
					@click="( e ) => onSelectCollection( e, collection )"
				>
					<cdx-icon :icon="collection.withTitle ? cdxIconCheck : cdxIconAdd"></cdx-icon>
				</cdx-button>
			</li>
		</ul>
	</div>
</template>

<script>
const { ref, computed, onMounted } = require( 'vue' );
const { CdxButton, CdxIcon } = require( '../../codex.js' );
const { cdxIconAdd, cdxIconCheck } = require( '../../icons.json' );
const api = require( 'ext.readingLists.api' );
const { CreateCollectionButton } = require( 'ext.readingLists.common' );

/**
 * UI for creating or choosing a collection to add an article to.
 */
// @vue/component
module.exports = exports = {
	name: 'CollectionPicker',
	components: { CdxButton, CdxIcon, CreateCollectionButton },
	props: {
		/**
		 * Title of the page.
		 */
		title: {
			type: String,
			required: true
		},
		/**
		 * Collections containing the page.
		 */
		collectionsWithPage: {
			type: Array,
			default: () => []
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
		'add-to-collection',
		/**
		 * When the user clicks "remove" for a collection.
		 *
		 * @param {number} listId - The ID of the collection.
		 */
		'remove-from-collection',
		/**
		 * When the user clicks "Unsave".
		 */
		'unsave-click'
	],
	setup( props, { emit } ) {
		const noCollectionsMessage = computed( () => mw.msg( 'readinglists-customlists-collection-picker-create-prompt' ) );
		const unsaveMessage = computed( () => mw.msg( 'readinglists-remove-bookmark' ) );
		const getAddButtonLabel = ( collection ) => {
			if ( collection.withTitle ) {
				return mw.msg( 'readinglists-customlists-collection-picker-remove-button-label', props.title, collection.name );
			} else {
				return mw.msg( 'readinglists-customlists-collection-picker-add-button-label', props.title, collection.name );
			}
		};

		const collectionsWithPage = ref( props.collectionsWithPage );
		const allCollections = ref( [] );
		const collections = computed( () => allCollections.value.map(
			( collection ) => Object.assign( {}, collection, {
				withTitle: collectionsWithPage.value.some( ( list ) => list.id === collection.id )
			} )
		) );
		const collectionsFetched = ref( false );

		async function getCollections() {
			try {
				allCollections.value = await api.getListsAll( props.title );
				collectionsFetched.value = true;
			} catch ( err ) {
				// Do nothing for now.
			}
		}

		function onCreateButtonClick() {
			emit( 'create-collection' );
		}

		function onRemoveFromCollection( event, collection ) {
			if ( event ) {
				event.stopPropagation();
			}
			// update the collection so it is no longer with title.
			collectionsWithPage.value = collectionsWithPage.value.filter( ( c ) => c.id !== collection.id );
			// emit an event to take care of any API calls.
			emit( 'remove-from-collection', collection.id );
		}

		function onAddToCollection( event, collection ) {
			if ( event ) {
				event.stopPropagation();
			}
			collectionsWithPage.value = collectionsWithPage.value.concat( collection );
			emit( 'add-to-collection', collection.id, collection.name );
		}

		function onSelectCollection( event, collection ) {
			if ( collection.withTitle ) {
				onRemoveFromCollection( event, collection );
			} else {
				onAddToCollection( event, collection );
			}
		}

		function onUnsaveButtonClick() {
			emit( 'unsave-click' );
		}

		onMounted( () => {
			getCollections();
		} );

		return {
			noCollectionsMessage,
			unsaveMessage,
			getAddButtonLabel,
			collections,
			collectionsFetched,
			onCreateButtonClick,
			onSelectCollection,
			onUnsaveButtonClick,
			cdxIconCheck,
			cdxIconAdd
		};
	}
};
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';
@import '../ext.readingLists.common/button-link.less';

// Each collection's height is min button height + list item padding + top border width.
@collection-height: @min-size-interactive-pointer + ( @spacing-25 * 2 ) + @border-width-base;
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

	&__unsave-button,
	&__create-button,
	&__collection {
		border-top: @border-width-base @border-style-base @border-color-subtle;

		&:hover {
			cursor: @cursor-base--hover;
		}
	}

	&__unsave-button,
	&__create-button {
		min-height: @collection-height;
	}

	&__unsave-button {
		.readinglists-button-link();
		color: @color-destructive;

		&:hover {
			color: @color-destructive--hover;
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
