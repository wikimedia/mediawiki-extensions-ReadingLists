<template>
	<cdx-dialog
		:open="true"
		:title="title"
		class="reading-lists-delete-collection-dialog"
		:use-close-button="isMobile"
		:render-in-place="true"
		:primary-action="primaryAction"
		:default-action="defaultAction"
		@primary="$emit( 'confirm' )"
		@default="$emit( 'close' )"
		@update:open="$emit( 'close' )"
	>
		<p>{{ dialogText }}</p>
	</cdx-dialog>
</template>

<script>
const { computed } = require( 'vue' );
const { CdxDialog } = require( '../../../codex.js' );
const { useIsMobileResolution } = require( 'ext.readingLists.common' );

/**
 * Confirmation dialog for deleting a collection.
 */
// @vue/component
module.exports = exports = {
	name: 'DeleteCollectionDialog',
	components: {
		CdxDialog
	},
	props: {
		listName: {
			type: String,
			required: true
		},
		isDeleting: {
			type: Boolean,
			default: false
		}
	},
	emits: [ 'confirm', 'close' ],
	setup( props ) {
		const isMobile = useIsMobileResolution();
		const dialogText = mw.msg( 'readinglists-customlists-delete-collection-dialog-text' );

		const title = computed( () => mw.msg(
			'readinglists-customlists-delete-collection-dialog-title',
			props.listName
		) );

		const primaryAction = computed( () => ( {
			label: mw.msg( 'readinglists-customlists-delete-collection-dialog-confirm' ),
			actionType: 'destructive',
			disabled: props.isDeleting
		} ) );

		const defaultAction = computed( () => ( {
			label: mw.msg( 'readinglists-customlists-delete-collection-dialog-cancel' ),
			disabled: props.isDeleting
		} ) );

		return {
			isMobile,
			title,
			dialogText,
			primaryAction,
			defaultAction
		};
	}
};
</script>
