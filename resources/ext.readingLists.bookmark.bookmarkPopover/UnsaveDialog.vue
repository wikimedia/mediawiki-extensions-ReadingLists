<template>
	<div>
		<cdx-dialog
			v-model:open="isOpen"
			class="readinglists-unsave-dialog"
			:title="dialogTitle"
			:use-close-button="true"
			:render-in-place="true"
			:primary-action="primaryAction"
			:default-action="defaultAction"
			@primary="unsavePage"
			@default="$emit( 'close' )"
			@update:open="$emit( 'close' )"
		>
			<p>{{ bodyText }}</p>

			<ul>
				<li
					v-for="collection in collectionNames"
					:key="collection.id"
				>
					{{ collection.name }}
				</li>
			</ul>
			<div v-if="error" :inline="true">
				<cdx-message type="error">
					{{ error }}
				</cdx-message>
			</div>
		</cdx-dialog>
	</div>
</template>

<script>
const { computed, ref } = require( 'vue' );
const { CdxDialog, CdxMessage } = require( '../../codex.js' );
const { deleteEntryByPageTitle } = require( 'ext.readingLists.api' );

/**
 * Dialog for confirming whether to unsave a page from all collections.
 */
// @vue/component
module.exports = exports = {
	name: 'UnsaveDialog',
	components: {
		CdxDialog,
		CdxMessage
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
		 * List of collections that currently contain this page.
		 */
		collectionsWithPage: {
			type: Array,
			required: true
		}
	},
	emits: [
		/**
		 * When a user clicks the primary "Remove" action.
		 */
		'page-unsaved',
		/**
		 * When a user closes the dialog.
		 */
		'close'
	],
	setup( props, { emit } ) {
		const isOpen = ref( true );
		const unsaveIsPending = ref( false );
		const error = ref( '' );

		const dialogTitle = computed( () => mw.msg( 'readinglists-unsave-from-custom-list-title-collections' ) );
		const bodyText = computed( () => mw.msg( 'readinglists-unsave-from-custom-list-body-collections' ) );

		const primaryAction = computed( () => ( {
			label: mw.msg( 'readinglists-unsave-from-custom-list-confirm' ),
			actionType: 'destructive',
			disabled: unsaveIsPending.value
		} ) );

		const defaultAction = {
			label: mw.msg( 'readinglists-unsave-from-custom-list-cancel' ),
			disabled: unsaveIsPending.value
		};

		const collectionNames = computed( () => props.collectionsWithPage );

		async function unsavePage() {
			unsaveIsPending.value = true;

			// Unsave from all lists.
			try {
				await deleteEntryByPageTitle( props.title );
				emit( 'page-unsaved' );
			} catch ( e ) {
				error.value = 'An unexpected error occurred. Please check your internet connection and try again.';
				mw.errorLogger.logError(
					new Error( `Error ${ e } in unsavePage` ),
					'error.readinglists'
				);
			}
			unsaveIsPending.value = false;
		}

		return {
			error,
			isOpen,
			dialogTitle,
			bodyText,
			collectionNames,
			primaryAction,
			defaultAction,
			unsavePage
		};
	}
};
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

.readinglists-cta-dialog {
	&__illustration {
		background-repeat: no-repeat;
		background-position: center;
		height: 10em;
		margin-top: @spacing-150;
	}

	&__actions {
		display: flex;
		flex-direction: column;
		gap: @spacing-75;
		width: @size-full;

		.cdx-button {
			max-width: none;
		}
	}
}
</style>
