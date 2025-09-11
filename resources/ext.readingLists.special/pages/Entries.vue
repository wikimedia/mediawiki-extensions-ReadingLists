<template>
	<cdx-message v-if="error" type="error">
		{{ error }}
	</cdx-message>

	<template v-else>
		<import-dialog v-if="imported !== null"></import-dialog>

		<h1 class="readinglists-title">
			{{ title || defaultTitle }}
		</h1>

		<h2 v-if="description && !isDefaultList" class="readinglists-description">
			{{ description }}
		</h2>

		<div v-if="!enableToolbar">
			({{ sortingText }})
		</div>

		<div
			v-if="enableToolbar"
			v-show="ready && ( loadingEntries || entries.length !== 0 )"
			class="readinglists-toolbar">
			<display-button
				:disabled="loadingInfo || loadingEntries"
				:imported="imported !== null"
				@ready="onReady"
				@changed="getEntries">
			</display-button>

			<edit-button
				v-if="ready"
				:editing="editing"
				:disabled="loadingInfo || loadingEntries || imported !== null"
				@changed="clearSelected">
			</edit-button>

			<remove-button
				v-if="editing"
				:disabled="loadingInfo || loadingEntries || selected.length === 0"
				:selected="selected"
				:list-id="listId"
				:list-title="title"
				:confirmation-msg="msgRemoveConfirmation"
				@removing="onRemoving"
				@removed="onRemoved">
			</remove-button>
		</div>

		<template v-if="!loadingInfo">
			<div
				v-if="entries.length !== 0"
				ref="container"
				:class="'readinglists-' + options[ 2 ]">
				<entry-item
					v-for="entry in entries"
					:key="entry.id"
					:entry="entry"
					:selected="selected.includes( entry.id )"
					:editing="editing"
					@selected="onSelected">
				</entry-item>
			</div>

			<template v-if="!loadingEntries">
				<empty-list v-if="entries.length === 0"></empty-list>

				<cdx-button v-else-if="!infinite && next !== null" @click="onShowMore">
					{{ msgShowMore }}
				</cdx-button>
			</template>
		</template>

		<cdx-progress-bar
			v-if="loadingInfo || loadingEntries"
			:aria-label="msgLoading">
		</cdx-progress-bar>
	</template>
</template>

<script>
const { ref } = require( 'vue' );
const api = require( 'ext.readingLists.api' );
const { CdxButton, CdxMessage, CdxProgressBar } = require( '../../../codex.js' );
const DisplayButton = require( '../components/DisplayButton.vue' );
const EditButton = require( '../components/EditButton.vue' );
const EmptyList = require( '../components/EmptyList.vue' );
const EntryItem = require( '../components/EntryItem.vue' );
const ImportDialog = require( '../components/ImportDialog.vue' );
const RemoveButton = require( '../components/RemoveButton.vue' );
const { ReadingListsEnableSpecialPageToolbar } = require( '../../../config.json' );

// @vue/component
module.exports = exports = {
	components: {
		CdxButton,
		CdxMessage,
		CdxProgressBar,
		DisplayButton,
		EditButton,
		EmptyList,
		EntryItem,
		ImportDialog,
		RemoveButton
	},
	props: {
		listId: {
			type: Number,
			default: null
		},
		imported: {
			type: Object,
			default: null
		}
	},
	setup() {
		return {
			loadingInfo: ref( true ),
			loadingEntries: ref( true ),
			defaultTitle: mw.msg( 'readinglists-default-title' ),
			title: ref( '' ),
			isDefaultList: ref( true ),
			description: ref( '' ),
			total: ref( 0 ),
			error: ref( '' ),
			ready: ref( false ),
			options: ref( [ 'updated', 'descending', 'grid' ] ),
			entries: ref( [] ),
			next: ref( null ),
			infinite: ref( false ),
			editing: ref( false ),
			selected: ref( [] ),
			sortingText: mw.msg( 'readinglists-sorted-by-recent' ),
			msgLoading: mw.msg( 'readinglists-loading' ),
			msgShowMore: mw.msg( 'readinglists-show-more' ),
			msgRemoveConfirmation: ref( mw.msg( 'readinglists-remove-confirmation', 0, '' ) ),
			msgTotalArticles: ref( mw.msg( 'readinglists-total-articles', 0 ) ),
			msgSelectedArticles: ref( mw.msg( 'readinglists-selected-articles', 0, 0 ) ),
			enableToolbar: ReadingListsEnableSpecialPageToolbar
		};
	},
	methods: {
		handleError( err ) {
			// eslint-disable-next-line no-console
			console.error( err );

			if ( typeof err === 'string' ) {
				const key = err === 'badinteger' ? 'readinglists-db-error-no-such-list' : err;
				const args = key === 'readinglists-db-error-no-such-list' ? this.listId : undefined;

				// eslint-disable-next-line mediawiki/msg-doc
				this.error = mw.msg( key, args );
			} else {
				this.error = err.toString();
			}
		},
		async getList() {
			this.loadingInfo = true;

			try {
				const list = this.imported || await api.getList( this.listId );

				if ( list.error !== undefined ) {
					throw list.error;
				}

				this.title = list.name;
				this.description = list.description;
				this.total = list.size;
				this.isDefaultList = !!list.default;
				this.updateMessages();
			} catch ( err ) {
				this.handleError( err );
			} finally {
				this.loadingInfo = false;
			}
		},
		async getEntries( options = null, clear = false ) {
			if ( options === null ) {
				this.loadingEntries = true;
			} else {
				const sort = options[ 0 ].replace( 's:', '' );
				const direction = options[ 1 ].replace( 'd:', '' );
				const view = options[ 2 ].replace( 'v:', '' );

				if ( sort !== this.options[ 0 ] || direction !== this.options[ 1 ] ) {
					clear = true;
				}

				this.options = [ sort, direction, view ];
			}

			if ( clear ) {
				this.clearEntries();
				this.loadingEntries = true;

				await this.getList();
				this.clearSelected( this.editing && this.total !== 0 );

				if ( this.total === 0 ) {
					this.loadingEntries = false;
				}
			}

			if ( !this.loadingEntries ) {
				return;
			}

			try {
				let entries;
				let next = null;

				if ( this.imported === null ) {
					const query = await api.getEntries(
						this.listId,
						this.options[ 0 ],
						this.options[ 1 ],
						12,
						this.next
					);

					entries = query.entries;
					next = query.next;
				} else if ( this.imported.error !== undefined ) {
					return;
				} else {
					entries = this.imported.list.slice();

					if ( this.options[ 0 ] === 'name' ) {
						entries.sort( ( a, b ) => a.title.localeCompare( b.title ) );
					}

					if ( this.options[ 1 ] === 'descending' ) {
						entries.reverse();
					}
				}

				this.entries.push( ...entries );
				this.next = next;

				if ( next === null ) {
					this.infinite = false;
				}
			} catch ( err ) {
				this.handleError( err );
			} finally {
				this.loadingEntries = false;
			}
		},
		updateMessages() {
			this.msgTotalArticles = mw.msg(
				'readinglists-total-articles',
				mw.language.convertNumber( this.total )
			);

			const count = this.selected.length;
			this.msgSelectedArticles = mw.msg(
				'readinglists-selected-articles',
				mw.language.convertNumber( count ),
				mw.language.convertNumber( this.total )
			);
			this.msgRemoveConfirmation = mw.msg(
				'readinglists-remove-confirmation',
				mw.language.convertNumber( count ),
				this.title
			);
		},
		registerScrollHandler() {
			document.addEventListener( 'scroll', () => {
				if (
					!this.error &&
					!this.loadingInfo &&
					!this.loadingEntries &&
					this.infinite &&
					this.next !== null &&
					this.$refs.container.getBoundingClientRect().bottom < window.innerHeight
				) {
					this.getEntries();
				}
			} );
		},
		async initializePage( options = null ) {
			await this.getEntries( options );
			this.ready = true;
			this.registerScrollHandler();
		},
		clearEntries() {
			this.loadingEntries = true;
			this.entries = [];
			this.next = null;
			this.infinite = false;
		},
		clearSelected( editing = false ) {
			this.editing = editing;
			this.selected = [];
			this.updateMessages();
		},
		async onReady( options ) {
			await this.initializePage( options );
		},
		onRemoving() {
			this.loadingInfo = true;
		},
		async onRemoved() {
			await this.getEntries( null, true );
		},
		onSelected( id, value ) {
			const idx = this.selected.indexOf( id );

			if ( value && idx === -1 ) {
				this.selected.push( id );
			} else {
				this.selected.splice( idx, 1 );
			}

			this.updateMessages();
		},
		async onShowMore() {
			this.infinite = true;
			await this.getEntries();
		}
	},
	async mounted() {
		if ( !this.enableToolbar ) {
			await this.getList();
			this.loadingEntries = true;
			await this.initializePage();
		}
	}
};
</script>
