<template>
	<dialog-popover
		:open="isOpen"
		:title="$i18n( 'readinglists-customlists-collection-name-dialog-title' ).text()"
		class="readinglists-collection-name-dialog"
		:primary-action="primaryAction"
		:use-close-button="true"
		@primary="saveCollection"
		@default="handleClose"
		@update:open="onUpdateOpen"
	>
		<cdx-text-input
			v-model="collectionName"
			:placeholder="placeholder"></cdx-text-input>
	</dialog-popover>
</template>

<script>
const { ref, computed } = require( 'vue' );
const { createList, updateList } = require( 'ext.readingLists.api' );
const { CdxTextInput } = require( '../../codex.js' );
const DialogPopover = require( './DialogPopover.vue' );

/**
 * Dialog shown to anonymous users when they click the bookmark button, prompting sign-in.
 */
// @vue/component
module.exports = exports = {
	name: 'CollectionNameDialog',
	components: {
		DialogPopover,
		CdxTextInput
	},
	props: {
		title: {
			type: String,
			default: ''
		},
		listId: {
			type: Number,
			default: null
		},
		isRename: {
			type: Boolean,
			default: false
		}
	},
	emits: [ 'update:open', 'success' ],
	setup( props, { emit } ) {
		const isOpen = ref( true );
		const collectionName = ref( props.isRename ? props.title : '' );

		/**
		 * Handle open change based on user interaction.
		 *
		 * @param {boolean} newValue Whether the dialog/popover is open.
		 */
		const onUpdateOpen = ( newValue ) => {
			isOpen.value = newValue;
			emit( 'update:open', newValue );
		};

		const saveCollection = async () => {
			const result = ( props.isRename ) ?
				await updateList( props.listId, collectionName.value ) :
				await createList( collectionName.value );
			const id = props.listId || result.create.id;
			emit( 'success', id, collectionName.value );
			onUpdateOpen( false );
		};

		const primaryAction = computed( () => ( {
			label: mw.msg( 'readinglists-customlists-collection-name-dialog-save' ),
			actionType: 'progressive',
			disabled: !collectionName.value
		} ) );

		const placeholder = mw.msg( 'readinglists-customlists-collection-name-dialog-placeholder' );
		const handleClose = () => {
			onUpdateOpen( false );
		};

		return {
			primaryAction,
			placeholder,
			isOpen,
			handleClose,
			collectionName,
			saveCollection,
			onUpdateOpen
		};
	}
};
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

.readinglists-collection-name-dialog .cdx-dialog__footer {
	text-align: end;
}
</style>
