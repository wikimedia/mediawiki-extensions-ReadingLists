<template>
	<nav class="reading-lists-nav">
		<ul class="reading-lists-nav-links">
			<li>
				<router-link
					class="reading-lists-special-nav-link"
					:to="allItemsTo">
					{{ allItemsText }}
				</router-link>
			</li>
			<li>
				<cdx-menu-button
					v-if="showDropdown"
					v-model:selected="selectedCollection"
					action="progressive"
					weight="quiet"
					:menu-items="collections"
					:menu-config="menuConfig"
					@click="maybeGetCollections"
					@update:selected="onSelectCollection">
					{{ collectionsText }}
					<cdx-icon size="medium" :icon="cdxIconExpand"></cdx-icon>
					<template #menu-item="{ menuItem }">
						<span
							v-if="menuItem.url"
							class="cdx-menu-item__content">
							<span class="cdx-menu-item__text"><bdi>{{ menuItem.label }}</bdi></span>
						</span>
						<span
							v-else
							class="cdx-menu-item__content">
							<span class="cdx-menu-item__text"><bdi>{{ menuItem.label }}</bdi></span>
						</span>
					</template>
				</cdx-menu-button>
				<router-link
					v-else
					class="reading-lists-special-nav-link reading-lists-collections-link"
					:to="collectionsUrl">
					{{ collectionsText }}
				</router-link>
			</li>
		</ul>

		<div
			v-if="isCustomList"
			class="reading-lists-nav-settings">
			<cdx-button
				ref="settingsButton"
				class="reading-lists-nav-settings-button"
				weight="quiet"
				:aria-label="collectionSettingsLabel"
				aria-haspopup="true"
				aria-controls="reading-lists-collection-settings-actions"
				:aria-expanded="showCollectionSettings"
				@click="toggleCollectionSettings">
				<cdx-icon :icon="cdxIconSettings" size="medium"></cdx-icon>
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
					id="reading-lists-collection-settings-actions"
					class="reading-lists-collection-settings-actions">
					<cdx-button weight="quiet" @click="renameCollection">
						{{ renameCollectionLabel }}
					</cdx-button>
					<cdx-button
						weight="quiet"
						action="destructive"
						@click="deleteCollection">
						{{ deleteCollectionLabel }}
					</cdx-button>
				</div>
			</cdx-popover>
		</div>
	</nav>
</template>

<script>
const { ref, watch, onUpdated, onMounted } = require( 'vue' );
const { RouterLink, useRouter } = require( 'vue-router' );
const { actions } = require( 'ext.readingLists.common' );
const { CdxButton, CdxIcon, CdxMenuButton, CdxPopover } = require( '../../../codex.js' );
const { cdxIconExpand, cdxIconSettings } = require( '../../../icons.json' );
const { actionSettingsDeleteClick, actionSettingsRenameClick,
	actionCreateCollectionButtonClick
} = actions;

const api = require( 'ext.readingLists.api' );
const { getCollectionUrl } = api;

// how many collections to initially load, as well as how many to show at once
const collectionsPageSize = 8;

// string to be appended to URL on the mobile collections view - same for all languages
const collectionsPath = '/collections';

// codex menu item to be inserted in both default state and when list is populated
const createCollectionEntry = {
	label: mw.msg( 'readinglists-customlists-create-collection' ),
	value: 0,
	class: 'reading-list-create-collection-menu-item',
	action: 'progressive'
};

// default entry, to also be used when the user has no collections
const noCollectionsEntry = {
	label: mw.msg( 'readinglists-customlists-no-collections' ),
	class: 'reading-list-no-collection-menu-item',
	disabled: true,
	value: -1
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
		RouterLink
	},
	props: {
		isCustomList: {
			type: Boolean,
			required: true
		}
	},
	emits: [
		'create-collection',
		'rename-collection',
		'delete-collection'
	],
	setup: ( props, { emit } ) => {
		const router = useRouter();
		const showDropdown = ref( mw.config.get( 'skin' ) === 'vector-2022' );

		// Router target (relative to the special page base) for the all-items
		// view, derived from the canonical URL so it matches the router's routes.
		const allItemsTo = getCollectionUrl( undefined, undefined, true );
		const allItemsText = mw.msg( 'readinglists-customlists-allitems' );
		const collectionsUrl = allItemsTo.concat( collectionsPath );
		const collectionsText = mw.msg( 'readinglists-customlists-collections' );
		const menuConfig = { visibleItemLimit: collectionsPageSize };

		const collections = ref( [] );
		const selectedCollection = ref( null );

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

		const renameCollection = () => {
			emit( 'rename-collection' );
			actionSettingsRenameClick();
		};

		const onSelectCollection = ( selected ) => {
			const selectedItem = collections.value.find( ( item ) => item.value === selected );
			if ( selectedItem ) {
				if ( selectedItem.url ) {
					router.push( selectedItem.url );
				} else {
					emit( 'create-collection' );
					actionCreateCollectionButtonClick();
				}
				selectedCollection.value = null;
			}
		};

		const deleteCollection = () => {
			showCollectionSettings.value = false;
			emit( 'delete-collection' );
			actionSettingsDeleteClick();
		};

		const maybeGetCollections = async () => {
			// if the list of collections has already been updated, no need to make another api call
			if ( collections.value.length > 0 ) {
				return;
			}

			try {
				const lists = await api.getListsAll();
				collections.value = [
					// put create collections CTA in first position
					createCollectionEntry
				].concat(
					lists.length ? makeListEntries( lists ) :
						[ noCollectionsEntry ]
				);
			} catch ( err ) {
				// T434119 - unclear what we should do in this case besides fail silently
			}
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
			cdxIconExpand,
			allItemsTo,
			allItemsText,
			collectionsUrl,
			collectionsText,
			menuConfig,
			collections,
			selectedCollection,
			maybeGetCollections,
			renameCollection,
			deleteCollection,
			cdxIconSettings,
			collectionSettingsLabel,
			renameCollectionLabel,
			deleteCollectionLabel,
			showCollectionSettings,
			settingsButton,
			toggleCollectionSettings,
			onCollectionSettingsOpen,
			onSelectCollection
		};
	}
};
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

.reading-lists-nav {
	margin-bottom: @spacing-100;
	display: flex;
	gap: @spacing-50;
	align-items: center;
	justify-content: space-between;

	&-links {
		margin: 0;
		display: flex;
		gap: @spacing-50;
		align-items: center;
		// Needed to override content styles in Minerva
		/* stylelint-disable-next-line declaration-no-important */
		list-style: none !important;

		li {
			margin: 0;
		}
	}

	a.reading-lists-special-nav-link {
		font-weight: bold;

		// needed to explicitly specify no visited styles - I welcome a better way to do this
		&:visited,
		&:visited:hover {
			color: @color-progressive;
		}

		&.router-link-active {
			color: @color-base;

			&:visited,
			&:visited:hover {
				color: @color-base;
			}
		}

		&.reading-lists-collections-link {
			// Add spacing between the All Items and Collections link
			// Because the collections link on Minerva isnt a menu button and doesnt have padding
			margin-left: @spacing-125;
		}
	}

	ul {
		padding-left: 0;
		padding-inline: 0;
	}

	.reading-list-create-collection-menu-item {
		border-bottom: @border-base;
	}

	.reading-list-no-collection-menu-item {
		color: @color-subtle;
	}
}

.reading-lists-collection-settings-popover.cdx-popover {
	padding: 0 0 @spacing-75 0;
	width: @size-1200;
}

.reading-lists-nav-settings-button.cdx-button {
	@media ( any-pointer: coarse ) {
		min-width: @min-size-interactive-touch;
		min-height: @min-size-interactive-touch;
	}
}

.reading-lists-collection-settings-actions .cdx-button {
	width: 100%;
	justify-content: flex-start;
	padding: @spacing-50 @spacing-75;
	font-weight: @font-weight-normal;
}

</style>
