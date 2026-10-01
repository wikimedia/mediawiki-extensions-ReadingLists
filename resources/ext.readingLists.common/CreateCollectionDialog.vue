<template>
	<dialog-popover
		:open="isOpen"
		:title="$i18n( 'readinglists-customlists-create-collection-dialog-name' ).text()"
		class="readinglists-create-collection-dialog"
		:primary-action="primaryAction"
		:default-action="defaultAction"
		@primary="createCollection"
		@default="handleClose"
		@update:open="onUpdateOpen"
	>
		<cdx-text-input
			:placeholder="$i18n( 'readinglists-customlists-create-collection-dialog-placeholder' ).text()"
			v-model="collectionName"></cdx-text-input>
	</dialog-popover>
</template>

<script>
const { ref, computed } = require( 'vue' );
const { createList } = require( 'ext.readingLists.api' );
const { CdxTextInput } = require( '../../codex.js' );
const DialogPopover = require( './DialogPopover.vue' );

/**
 * Dialog shown to anonymous users when they click the bookmark button, prompting sign-in.
 */
// @vue/component
module.exports = exports = {
	name: 'CreateCollectionDialog',
	components: {
		DialogPopover,
		CdxTextInput
	},
	emits: [ 'update:open', 'success' ],
	setup( _props, { emit } ) {
		const isOpen = ref( true );
		const collectionName = ref( '' );

		/**
		 * Handle open change based on user interaction.
		 *
		 * @param {boolean} newValue Whether the dialog/popover is open.
		 */
		const onUpdateOpen = ( newValue ) => {
			isOpen.value = newValue;
			emit( 'update:open', newValue );
		};

		const createCollection = async () => {
			const result = await createList( collectionName.value );
			emit( 'success', result.create.id, collectionName.value );
			onUpdateOpen( false );
		};

		const primaryAction = computed( () => ( {
			label: mw.msg( 'readinglists-add-collection' ),
			actionType: 'progressive',
			disabled: !collectionName.value
		} ) );

		const defaultAction = {
			label: mw.msg( 'readinglists-cancel-add-collection' )
		};

		const handleClose = () => {
			onUpdateOpen( false );
		};

		return {
			primaryAction,
			defaultAction,
			isOpen,
			handleClose,
			collectionName,
			createCollection,
			onUpdateOpen
		};
	}
};
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

.readinglists-create-collection-dialog .cdx-dialog__footer {
	text-align: end;
}
</style>
