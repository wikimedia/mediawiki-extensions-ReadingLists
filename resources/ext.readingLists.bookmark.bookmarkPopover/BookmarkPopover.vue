<template>
	<config-popover
		v-model:open="isConfigPopoverOpen"
		class="readinglists-bookmark-popover"
		@update:open="handleOpenChange"
	>
		<!-- Custom header so we can render HTML in the title. -->
		<template #header>
			<cdx-icon
				v-if="popoverIcon"
				class="cdx-popover__header__icon"
				:icon="popoverIcon"
			></cdx-icon>
			<!-- eslint-disable vue/no-v-html -->
			<div
				v-if="!isCurrentlySaved"
				class="cdx-popover__header__title"
				v-html="saveTitle"
			></div>
			<!-- eslint-enable vue/no-v-html -->
			<div v-else class="cdx-popover__header__title">
				{{ title }}
			</div>
			<div class="cdx-popover__header__button-wrapper">
				<cdx-button
					class="cdx-popover__header__close-button"
					weight="quiet"
					type="button"
					:aria-label="closeButtonLabel"
					@click="handleOpenChange( false )"
				>
					<cdx-icon :icon="cdxIconClose"></cdx-icon>
				</cdx-button>
			</div>
		</template>

		<collection-picker
			:title="title"
			@create-collection="launchCreateCollection"
			@add-to-collection="addToCollection"
		></collection-picker>

		<collection-name-dialog
			v-if="isCollectionNameDialogOpen"
			@success="addToCollection"
			@update:open="isCollectionNameDialogOpen = $event"
		></collection-name-dialog>
	</config-popover>
</template>

<script>
const { ref, computed, toRef } = require( 'vue' );
const { CdxButton, CdxIcon } = require( '../../codex.js' );
const { cdxIconSuccess, cdxIconClose } = require( '../../icons.json' );
const { ConfigPopover, CollectionNameDialog, getCollectionUrl } = require( 'ext.readingLists.common' );
const { createEntry } = require( 'ext.readingLists.api' );
const CollectionPicker = require( './CollectionPicker.vue' );

/**
 * Popover for saving or un-saving an article to a reading list.
 */
// @vue/component
module.exports = exports = {
	name: 'BookmarkPopover',
	components: {
		CdxButton,
		CdxIcon,
		ConfigPopover,
		CollectionNameDialog,
		CollectionPicker
	},
	props: {

		title: {
			type: String,
			required: true
		},
		/**
		 * Whether this article is currently saved to a reading list. The popover content changes
		 * depending on this.
		 */
		isCurrentlySaved: {
			type: Boolean,
			default: false
		},
		/**
		 * Callback when the popover closes. The argument is whether to show an mw.notification
		 * (handled in bookmark.js).
		 */
		onDismiss: {
			type: Function,
			required: true
		}
	},
	setup( props ) {
		const isConfigPopoverOpen = ref( true );
		const isCollectionNameDialogOpen = ref( false );
		const title = toRef( props, 'title' );

		// Different popover title depending on whether the user is saving or un-saving the article.
		const saveTitle = computed( () => mw.message(
			'readinglists-customlists-add-entry-success',
			title.value.replace( /_/g, ' ' ),
			getCollectionUrl(),
			mw.msg( 'readinglists-default-title' )
		).parse() );

		const popoverIcon = computed( () => props.isCurrentlySaved ? null : cdxIconSuccess );
		const closeButtonLabel = computed( () => mw.msg( 'cdx-popover-close-button-label' ) );

		/**
		 * Handle popover dismissal.
		 *
		 * @param {boolean} newValue - Whether the popover is open.
		 */
		function handleOpenChange( newValue ) {
			if ( !newValue ) {
				// When the user closes the popover without adding to a custom list, don't show
				// a notification.
				props.onDismiss( false );
			}
		}

		function launchCreateCollection() {
			isCollectionNameDialogOpen.value = true;
		}

		async function addToCollection( listId, listName ) {
			// TODO: Handle errors.
			// Add to custom list.
			await createEntry( listId, title.value );
			// Close the popover and show a notification that the page was added to a custom list.
			isConfigPopoverOpen.value = false;
			props.onDismiss( true, listId, listName );
		}

		return {
			isConfigPopoverOpen,
			saveTitle,
			popoverIcon,
			closeButtonLabel,
			handleOpenChange,
			cdxIconClose,
			addToCollection,
			isCollectionNameDialogOpen,
			launchCreateCollection
		};
	}
};
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

.readinglists-bookmark-popover {
	&.cdx-popover--bottom-sheet {
		// Set the width to the max-width of the CdxPopover's bottom sheet version.
		width: @size-5600;
		max-width: @size-full;
	}

	&.cdx-popover:not( .cdx-popover--bottom-sheet ) {
		// Match the width of mw.notifications in Vector 2022 (20em at 0.8em font size).
		width: @size-1600;
	}

	.cdx-popover__header__icon {
		color: @color-success;
	}
}
</style>
