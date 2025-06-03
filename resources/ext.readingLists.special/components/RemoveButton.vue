<template>
	<div class="readinglists-remove">
		<cdx-button
			:disabled="disabled"
			action="destructive"
			weight="primary"
			@click="showConfirm = true">
			{{ msgRemove }}
		</cdx-button>

		<cdx-dialog
			v-model:open="showConfirm"
			:title="msgRemoveTitle"
			:use-close-button="true"
			:primary-action="{ actionType: 'destructive', label: msgRemove }"
			:default-action="{ label: msgCancel }"
			@primary="onRemove"
			@default="showConfirm = false">
			{{ confirmationMsg }}
		</cdx-dialog>
	</div>
</template>

<script>
const { ref } = require( 'vue' );
const api = require( 'ext.readingLists.api' );
const { CdxButton, CdxDialog } = require( '../../../codex.js' );

// @vue/component
module.exports = exports = {
	components: { CdxButton, CdxDialog },
	props: {
		disabled: {
			type: Boolean,
			default: false
		},
		selected: {
			type: Array,
			default: () => []
		},
		listId: {
			type: Number,
			default: null
		},
		listTitle: {
			type: String,
			default: ''
		},
		confirmationMsg: {
			type: String,
			default: mw.msg( 'readinglists-remove-confirmation', 0, '' )
		},
		removeCallback: {
			type: Function,
			default: () => {}
		}
	},
	setup() {
		return {
			showConfirm: ref( false ),
			msgCancel: mw.msg( 'cancel' ),
			msgRemove: mw.msg( 'readinglists-remove' ),
			msgRemoveTitle: mw.msg( 'readinglists-remove-title' )
		};
	},
	methods: {
		async onRemove() {
			this.showConfirm = false;

			try {
				await api.deleteEntries( this.selected );
			} catch ( err ) {
				mw.notification.notify(
					mw.msg( 'readinglists-browser-error-intro', err ),
					{ tag: 'removed', type: 'error' }
				);

				throw err;
			}

			mw.notification.notify(
				mw.message(
					'readinglists-remove-success',
					this.selected.length,
					`Special:ReadingLists/${ mw.user.getName() }/${ this.listId }`,
					this.listTitle
				).parseDom(),
				{ tag: 'removed' }
			);

			await this.removeCallback( null, true );
		}
	}
};
</script>
