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
		<cdx-field
			:status="status"
			:messages="messages"
			:disabled="isSaving"
			:hide-label="true"
		>
			<cdx-text-input
				v-model="collectionName"
				:aria-invalid="status === 'error' || undefined"
				:aria-errormessage="status === 'error' ? errorId : undefined"
				:placeholder="placeholder"
			></cdx-text-input>
			<template #label>
				{{ $i18n( 'readinglists-customlists-collection-name-dialog-title' ).text() }}
			</template>
			<!-- Give the error text an ID so the input's aria-errormessage can point to it. -->
			<template #error>
				<span :id="errorId">{{ messages.error }}</span>
			</template>
		</cdx-field>
		<cdx-message
			v-if="saveError"
			class="readinglists-collection-name-dialog__error"
			type="error"
			:inline="true"
		>
			{{ saveError }}
		</cdx-message>
	</dialog-popover>
</template>

<script>
const { ref, computed } = require( 'vue' );
const { createList, updateList } = require( 'ext.readingLists.api' );
const { CdxField, CdxTextInput, CdxMessage } = require( '../../codex.js' );
const DialogPopover = require( './DialogPopover.vue' );

const nameErrors = {
	'readinglists-db-error-empty-name': 'readinglists-customlists-collection-name-dialog-required',
	'readinglists-db-error-duplicate-list': 'readinglists-customlists-collection-name-dialog-duplicate'
};

/**
 * Dialog shown to anonymous users when they click the bookmark button, prompting sign-in.
 */
// @vue/component
module.exports = exports = {
	name: 'CollectionNameDialog',
	components: {
		DialogPopover,
		CdxField,
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
		const errorId = 'readinglists-collection-name-error';
		const messages = ref( {} );
		const saveError = ref( '' );
		const status = computed( () => messages.value.error ? 'error' : 'default' );

		const showError = ( code ) => {
			const messageKey = nameErrors[ code ];
			if ( messageKey ) {
				messages.value = { error: mw.msg( messageKey ) };
			} else {
				saveError.value = mw.msg( 'readinglists-browser-error' );
			}
		};

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

			messages.value = {};
			saveError.value = '';
			collectionName.value = collectionName.value.trim();
			const name = collectionName.value;
			if ( name === '' ) {
				showError( 'readinglists-db-error-empty-name' );
				return;
			}

			isSaving.value = true;
			try {
				const response = props.isRename ?
					await updateList( props.listId, name ) :
					await createList( name );
				const result = props.isRename ? response.update : response.create;
				emit( 'success', result.id, name );
				onUpdateOpen( false );
			} catch ( code ) {
				showError( code );
			} finally {
				isSaving.value = false;
			}
		};

		const primaryAction = computed( () => ( {
			label: mw.msg( 'readinglists-customlists-collection-name-dialog-save' ),
			actionType: 'progressive',
			disabled: isSaving.value
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
			status,
			messages,
			saveError,
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
