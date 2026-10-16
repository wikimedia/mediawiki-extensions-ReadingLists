<template>
	<nav class="reading-lists-nav">
		<ul class="reading-lists-nav-links">
			<li>
				<router-link
					class="readinglists-special-nav-link"
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
					@click="maybeGetCollections">
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
				<router-link
					v-else
					class="readinglists-special-nav-link"
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
				aria-controls="readinglists-collection-settings-actions"
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
	</nav>
</template>

<script>
const { ref, watch, onUpdated, onMounted } = require( 'vue' );
const { RouterLink } = require( 'vue-router' );
const { getCollectionUrl } = require( 'ext.readingLists.common' );
const { CdxButton, CdxIcon, CdxMenuButton, CdxPopover } = require( '../../../codex.js' );
const { cdxIconAdd, cdxIconExpand, cdxIconSettings } = require( '../../../icons.json' );

const api = require( 'ext.readingLists.api' );

// how many collections to initially load, as well as how many to show at once
const collectionsPageSize = 8;

// string to be appended to URL on the mobile collections view - same for all languages
const collectionsPath = '/collections';

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
		RouterLink
	},
	props: {
		isCustomList: {
			type: Boolean,
			required: true
		}
	},
	emits: [
		'create-collection'
	],
	setup: ( props, { emit } ) => {
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

		const createCollection = () => {
			emit( 'create-collection' );
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
			createCollection,
			cdxIconExpand,
			allItemsTo,
			allItemsText,
			collectionsUrl,
			collectionsText,
			menuConfig,
			collections,
			selectedCollection,
			maybeGetCollections,
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
		// needed to explicitly specify no visited styles - I welcome a better way to do this
		&:visited,
		&:visited:hover {
			color: @color-progressive;
		}

		&.router-link-active {
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
