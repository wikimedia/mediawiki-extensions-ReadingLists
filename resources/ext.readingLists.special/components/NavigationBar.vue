<template>
	<div class="readinglists-nav-bar">
		<create-collection-dialog
			v-if="showCreateCollection"
			@update:open="onUpdateOpen"
			@success="routeToNewCollection"
		>
		</create-collection-dialog>
		<router-link
			class="readinglists-special-nav-link"
			:to="allItemsTo"
			:class="{ 'readinglists-special-nav-link--active': isAllItems }">
			{{ allItemsText }}
		</router-link>

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
			<template #menu-item="{ menuItem }">
				<router-link
					v-if="menuItem.url"
					class="cdx-menu-item__content"
					:to="menuItem.url">
					<span class="cdx-menu-item__text"><bdi>{{ menuItem.label }}</bdi></span>
				</router-link>
				<span
					v-else
					class="cdx-menu-item__content"
					@click="createCollection">
					<cdx-icon
						v-if="menuItem.icon"
						:icon="menuItem.icon"
						class="cdx-menu-item__icon"></cdx-icon>
					<span class="cdx-menu-item__text"><bdi>{{ menuItem.label }}</bdi></span>
				</span>
			</template>
		</cdx-menu-button>

		<a
			v-else
			class="readinglists-special-nav-link"
			:class="{ 'readinglists-special-nav-link--active': isCollections }">
			{{ collectionsText }}
		</a>

		<div
			v-if="isCustomList"
			class="readinglists-nav-bar-settings">
			<cdx-button
				ref="settingsButton"
				class="readinglists-nav-bar-settings-button"
				weight="quiet"
				:aria-label="collectionSettingsLabel"
				aria-haspopup="true"
				aria-controls="readinglists-collection-settings-actions"
				:aria-expanded="showCollectionSettings"
				@click="toggleCollectionSettings">
				<cdx-icon :icon="cdxIconSettings" size="large"></cdx-icon>
			</cdx-button>
			<cdx-popover
				v-if="showCollectionSettings"
				class="readinglists-collection-settings-popover"
				open
				:anchor="settingsButton"
				placement="bottom-end"
				:hide-arrow="true"
				:render-in-place="true"
				@update:open="onCollectionSettingsOpen">
				<div
					id="readinglists-collection-settings-actions"
					class="readinglists-collection-settings-actions">
					<cdx-button weight="quiet">
						{{ renameCollectionLabel }}
					</cdx-button>
					<cdx-button
						weight="quiet"
						action="destructive">
						{{ deleteCollectionLabel }}
					</cdx-button>
				</div>
			</cdx-popover>
		</div>
	</div>
</template>

<script>
const { ref, watch, onUpdated, onMounted } = require( 'vue' );
const { RouterLink, useRouter } = require( 'vue-router' );
const { CreateCollectionDialog, getCollectionUrl } = require( 'ext.readingLists.common' );
const { CdxButton, CdxIcon, CdxMenuButton, CdxPopover } = require( '../../../codex.js' );
const { cdxIconAdd, cdxIconExpand, cdxIconSettings } = require( '../../../icons.json' );

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
		// T432633 - route via vue-router (see #menu-item slot in the template) rather than
		// an @update:selected handler, so the whole row stays a full-size click target.
		// Relative to the special page base, matching the router's routes (see allItemsTo below).
		url: getCollectionUrl( list.id, list.name, true )
	} ) )
);

const collectionSettingsLabel = mw.msg( 'readinglists-customlists-collection-settings' );
const renameCollectionLabel = mw.msg( 'readinglists-customlists-rename-collection' );
const deleteCollectionLabel = mw.msg( 'readinglists-customlists-delete-collection' );

// @vue/component
module.exports = exports = {
	components: {
		CdxButton,
		CdxIcon,
		CdxMenuButton,
		CdxPopover,
		CreateCollectionDialog,
		RouterLink
	},
	props: {
		isAllItems: {
			type: Boolean,
			required: true
		},
		isCollections: {
			type: Boolean
			// not required for now as this will be added in a follow up
			// required: true
		},
		isCustomList: {
			type: Boolean,
			default: false
		}
	},
	setup: ( props ) => {
		const router = useRouter();
		// @todo: Temporarily enable to true to support testing in mobile.
		// Revisit as part of https://phabricator.wikimedia.org/T438404
		const showDropdown = ref( true );

		// Router target (relative to the special page base) for the all-items
		// view, derived from the canonical URL so it matches the router's routes.
		const allItemsTo = getCollectionUrl( undefined, undefined, true );
		const allItemsText = mw.msg( 'readinglists-customlists-allitems' );
		const collectionsText = mw.msg( 'readinglists-customlists-collections' );
		const menuConfig = { visibleItemLimit: collectionsPageSize };

		const collections = ref( [] );
		const selectedCollection = ref( null );
		const collectionsNext = ref( null );

		const showCreateCollection = ref( false );
		const showCollectionSettings = ref( false );
		const settingsButton = ref( null );

		const toggleCollectionSettings = () => {
			showCollectionSettings.value = !showCollectionSettings.value;
		};

		const onCollectionSettingsOpen = ( value ) => {
			showCollectionSettings.value = value;
		};

		watch( () => props.isCustomList, ( customList ) => {
			if ( !customList ) {
				showCollectionSettings.value = false;
			}
		} );

		const createCollection = () => {
			showCreateCollection.value = true;
		};

		const onUpdateOpen = ( value ) => {
			showCreateCollection.value = value;
		};

		const maybeGetCollections = async () => {
			// if the list of collections has already been updated, no need to make another api call
			if ( collections.value.length > 0 ) {
				return;
			}

			try {
				const result = await api.getLists(
					'name', 'ascending', collectionsPageSize - 1
				);
				const listsMinusDefault = result.lists ?
					result.lists.filter( ( list ) => !list.default ) : [];

				collections.value = [
					// put create collections CTA in first position
					createCollectionEntry
				].concat(
					listsMinusDefault.length ? makeListEntries( listsMinusDefault ) :
						[ noCollectionsEntry ]
				);

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

		const routeToNewCollection = ( id, name ) => {
			const collectionUrl = getCollectionUrl( id, name, true );
			router.push( collectionUrl );
			showCreateCollection.value = false;
			collections.value = [];
			collectionsNext.value = null;
		};

		// Populate on startup if desktop detected
		onUpdated( () => {
			if ( showDropdown.value ) {
				maybeGetCollections();
			}
		} );

		onMounted( () => {
			if ( showDropdown.value ) {
				maybeGetCollections();
			}
		} );
		return {
			showDropdown,
			routeToNewCollection,
			onUpdateOpen,
			showCreateCollection,
			createCollection,
			cdxIconExpand,
			allItemsTo,
			allItemsText,
			collectionsText,
			menuConfig,
			collections,
			selectedCollection,
			maybeGetCollections,
			maybeGetNextCollections,
			cdxIconSettings,
			collectionSettingsLabel,
			renameCollectionLabel,
			deleteCollectionLabel,
			showCollectionSettings,
			settingsButton,
			toggleCollectionSettings,
			onCollectionSettingsOpen
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

	ul {
		padding-left: 0;
		padding-inline: 0;
	}
}

.readinglists-collection-settings-popover.cdx-popover {
	padding: 0 0 @spacing-75 0;
	width: @size-1200;
}

.readinglists-nav-bar-settings {
	position: relative;
	margin-inline-start: auto;
}

.readinglists-nav-bar-settings-button.cdx-button {
	@media ( any-pointer: coarse ) {
		min-width: @min-size-interactive-touch;
		min-height: @min-size-interactive-touch;
	}
}

.readinglists-collection-settings-actions .cdx-button {
	width: 100%;
	justify-content: flex-start;
	padding: @spacing-50 @spacing-75;
	font-weight: @font-weight-normal;
}

</style>
