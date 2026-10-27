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
				v-if="!isNewSave"
				class="cdx-popover__header__title"
				v-html="saveTitle"
			></div>
			<!-- eslint-enable vue/no-v-html -->
			<div v-else class="cdx-popover__header__title">
				{{ formattedTitle }}
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
			:collections-with-page="collectionsWithPage"
			@create-collection="launchCreateCollection"
			@add-to-collection="addToCollection"
			@remove-from-collection="removeFromCollection"
			@unsave-click="onUnsaveClick"
		></collection-picker>

		<collection-name-dialog
			v-if="isCollectionNameDialogOpen"
			@success="createAndAddToCollection"
			@update:open="isCollectionNameDialogOpen = $event"
		></collection-name-dialog>

		<unsave-dialog
			v-if="isUnsaveDialogOpen"
			:title="title"
			:collections-with-page="collectionsWithPage"
			@page-unsaved="onPageUnsaved"
			@close="isUnsaveDialogOpen = false"
		></unsave-dialog>
	</config-popover>
</template>

<script>
const { ref, computed, toRef } = require( 'vue' );
const { CdxButton, CdxIcon } = require( '../../codex.js' );
const { cdxIconSuccess, cdxIconClose } = require( '../../icons.json' );
const { ConfigPopover, CollectionNameDialog, getCollectionUrl, actions } = require( 'ext.readingLists.common' );
const { createEntry, deleteEntryByPageTitle } = require( 'ext.readingLists.api' );
const CollectionPicker = require( './CollectionPicker.vue' );
const UnsaveDialog = require( './UnsaveDialog.vue' );
const { actionAddToCustomList, actionRemoveFromCustomList, actionSaveCustomList } = actions;

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
		CollectionPicker,
		UnsaveDialog
	},
	props: {
		/**
		 * Title of the page.
		 */
		title: {
			type: String,
			required: true
		},
		/**
		 * Whether this page was just saved for the first time.
		 */
		isNewSave: {
			type: Boolean,
			default: false
		},
		/**
		 * A list of collections that contain this page.
		 */
		collectionsWithPage: {
			type: Array,
			default: () => []
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
		const isUnsaveDialogOpen = ref( false );
		const title = toRef( props, 'title' );
		const formattedTitle = computed( () => title.value.replace( /_/g, ' ' ) );

		// Different popover title depending on whether the user is saving or un-saving the article.
		const saveTitle = computed( () => mw.message(
			'readinglists-customlists-add-entry-success',
			formattedTitle.value,
			getCollectionUrl(),
			mw.msg( 'readinglists-default-title' )
		).parse() );

		const popoverIcon = computed( () => props.isNewSave ? null : cdxIconSuccess );
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
				props.onDismiss( { isSaved: true, showNotification: false } );
			}
		}

		function launchCreateCollection() {
			isCollectionNameDialogOpen.value = true;
		}

		async function addToCollection( listId, listName ) {
			// Add to custom list.
			try {
				await createEntry( listId, title.value );
				actionAddToCustomList();
			} catch ( e ) {
				mw.errorLogger.logError(
					new Error( `Error ${ e } in addToCollection` ),
					'error.readinglists'
				);
				props.onDismiss( { isSaved: false, isError: true, showNotification: true } );
				return;
			}
			// Close the popover and show a notification that the page was added to a custom list.
			isConfigPopoverOpen.value = false;
			props.onDismiss( { isSaved: true, showNotification: true, listId, listName } );
		}

		async function createAndAddToCollection( listId, listName ) {
			actionSaveCustomList();
			await addToCollection( listId, listName );
		}

		async function removeFromCollection( listId ) {
			try {
				await deleteEntryByPageTitle( title.value, listId );
				actionRemoveFromCustomList();
			} catch ( e ) {
				mw.errorLogger.logError(
					new Error( `Error ${ e } in removeFromCollection` ),
					'error.readinglists'
				);
				props.onDismiss( { isSaved: false, isError: true, showNotification: true } );
				return;
			}
		}

		async function onUnsaveClick() {
			// If the page is in a custom list, show the unsave dialog to confirm removal.
			if ( props.collectionsWithPage.length > 0 ) {
				isUnsaveDialogOpen.value = true;
				return;
			}

			// Otherwise, just remove from the default list and close the popover.
			try {
				await deleteEntryByPageTitle( props.title );
			} catch ( e ) {
				mw.errorLogger.logError(
					new Error( `Error ${ e } in onUnsaveClick` ),
					'error.readinglists'
				);
				props.onDismiss( { isSaved: false, isError: true, showNotification: true } );
			}
			isConfigPopoverOpen.value = false;
			props.onDismiss( { isSaved: false, showNotification: true } );
		}

		function onPageUnsaved() {
			isUnsaveDialogOpen.value = false;
			isConfigPopoverOpen.value = false;
			props.onDismiss( { isSaved: false, showNotification: true } );
		}

		return {
			isConfigPopoverOpen,
			isCollectionNameDialogOpen,
			isUnsaveDialogOpen,
			formattedTitle,
			saveTitle,
			popoverIcon,
			closeButtonLabel,
			handleOpenChange,
			launchCreateCollection,
			createAndAddToCollection,
			addToCollection,
			removeFromCollection,
			onUnsaveClick,
			onPageUnsaved,
			cdxIconClose
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
