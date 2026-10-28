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
			:disabled="isSaving"
			:status="isError ? 'error' : 'default'"
			:aria-invalid="isError || undefined"
			:aria-errormessage="isError ? errorId : undefined"
			:placeholder="placeholder"></cdx-text-input>
		<cdx-message
			v-if="error"
			:id="errorId"
			class="readinglists-collection-name-dialog__error"
			type="error"
			:inline="true"
		>
			{{ error }}
		</cdx-message>
	</dialog-popover>
</template>

<script>
const { ref, computed, watch } = require( 'vue' );
const { createList, updateList } = require( 'ext.readingLists.api' );
const { CdxTextInput, CdxMessage } = require( '../../codex.js' );
const DialogPopover = require( './DialogPopover.vue' );

/**
 * Dialog shown to anonymous users when they click the bookmark button, prompting sign-in.
 */
// @vue/component
module.exports = exports = {
	name: 'CollectionNameDialog',
	components: {
		DialogPopover,
		CdxTextInput,
		CdxMessage
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
		const isSaving = ref( false );
		const error = ref( '' );
		const isError = ref( false );
		const errorId = 'readinglists-collection-name-error';

		const clearError = () => {
			error.value = '';
			isError.value = false;
		};
		const handleError = ( code ) => {
			const nameErrors = [
				'readinglists-db-error-empty-name'
			];
			isError.value = nameErrors.includes( code );
			error.value = mw.msg( isError.value ? code : 'readinglists-browser-error' );
		};

		watch( collectionName, clearError );

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
			if ( isSaving.value ) {
				return;
			}

			const name = collectionName.value;
			clearError();
			isSaving.value = true;
			try {
				const result = props.isRename ?
					await updateList( props.listId, name ) :
					await createList( name );
				const id = props.isRename ? props.listId : result.create.id;
				emit( 'success', id, name );
				onUpdateOpen( false );
			} catch ( code ) {
				handleError( code );
			} finally {
				isSaving.value = false;
			}
		};

		const primaryAction = computed( () => ( {
			label: mw.msg( 'readinglists-customlists-collection-name-dialog-save' ),
			actionType: 'progressive',
			disabled: isSaving.value || !collectionName.value
		} ) );

		const placeholder = mw.msg( 'readinglists-customlists-collection-name-dialog-placeholder' );
		const handleClose = () => {
			onUpdateOpen( false );
		};

		return {
			primaryAction,
			placeholder,
			isOpen,
			isSaving,
			error,
			isError,
			errorId,
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

.readinglists-collection-name-dialog__error {
	margin-top: @spacing-100;
}
</style>
