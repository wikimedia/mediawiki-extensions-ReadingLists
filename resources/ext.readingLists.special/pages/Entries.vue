<template>
	<cdx-message v-if="error" type="error">
		{{ error }}
	</cdx-message>

	<template v-else>
		<import-dialog v-if="imported !== null"></import-dialog>

		<template v-if="!loadingInfo">
			<h1 v-if="title">
				{{ title }}
			</h1>

			<h2 v-if="description">
				{{ description }}
			</h2>

			<h3>
				{{ loadingInfo ? msgLoading : editing ? msgSelectedArticles : msgTotalArticles }}
			</h3>
		</template>

		<div
			v-if="!ready || loadingEntries || entries.length !== 0"
			v-show="ready"
			class="readinglists-toolbar">
			<display-button
				:disabled="loadingEntries"
				:is-imported="imported !== null"
				@ready="onReady"
				@changed="getEntries">
			</display-button>

			<edit-button
				v-if="ready"
				:editing="editing"
				:disabled="loadingEntries || imported !== null"
				@changed="onEdit">
			</edit-button>

			<remove-button
				v-if="editing"
				:disabled="loadingEntries || selected.length === 0"
				:selected="selected"
				:list-id="listId"
				:list-title="title"
				:confirmation-msg="msgRemoveConfirmation"
				:remove-callback="getEntries">
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
			title: ref( '' ),
			description: ref( '' ),
			total: ref( 0 ),
			error: ref( '' ),
			ready: ref( false ),
			options: ref( [] ),
			entries: ref( [] ),
			next: ref( null ),
			infinite: ref( false ),
			editing: ref( false ),
			selected: ref( [] ),
			msgLoading: mw.msg( 'readinglists-loading' ),
			msgShowMore: mw.msg( 'readinglists-show-more' ),
			msgRemoveConfirmation: ref( mw.msg( 'readinglists-remove-confirmation', 0, '' ) ),
			msgTotalArticles: ref( mw.msg( 'readinglists-total-articles', 0 ) ),
			msgSelectedArticles: ref( mw.msg( 'readinglists-selected-articles', 0, 0 ) )
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
		async getEntries( options = null, init = false ) {
			if ( options === null ) {
				this.loadingEntries = true;
			} else {
				const sort = options[ 0 ].replace( 's:', '' );
				const direction = options[ 1 ].replace( 'd:', '' );
				const view = options[ 2 ].replace( 'v:', '' );

				if ( sort !== this.options[ 0 ] || direction !== this.options[ 1 ] ) {
					init = true;
				}

				this.options = [ sort, direction, view ];
			}

			if ( init ) {
				this.loadingEntries = true;
				this.selected = [];
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

				if ( init ) {
					this.entries = entries;
				} else {
					this.entries.push( ...entries );
				}

				this.next = next;

				if ( next === null ) {
					this.infinite = false;
				}

				if ( init ) {
					this.ready = true;
				}
			} catch ( err ) {
				this.handleError( err );
			} finally {
				this.loadingEntries = false;
			}
		},
		async onReady( options ) {
			await this.getEntries( options, true );
		},
		onEdit( value ) {
			this.editing = value;
			this.selected = [];
			this.msgSelectedArticles = mw.msg( 'readinglists-selected-articles', 0, this.total );
		},
		onSelected( id, value ) {
			const idx = this.selected.indexOf( id );

			if ( value && idx === -1 ) {
				this.selected.push( id );
			} else {
				this.selected.splice( idx, 1 );
			}

			this.msgSelectedArticles = mw.msg(
				'readinglists-selected-articles',
				this.selected.length,
				this.total
			);

			this.msgRemoveConfirmation = mw.msg(
				'readinglists-remove-confirmation',
				this.selected.length,
				this.title
			);
		},
		async onShowMore() {
			this.infinite = true;
			await this.getEntries();
		}
	},
	async mounted() {
		try {
			const list = this.imported || await api.getList( this.listId );

			if ( list.error !== undefined ) {
				throw list.error;
			}

			this.title = list.name;
			this.description = list.description;
			this.total = list.size;
			this.msgTotalArticles = mw.msg( 'readinglists-total-articles', list.size );
		} catch ( err ) {
			this.handleError( err );
		} finally {
			this.loadingInfo = false;
		}

		document.addEventListener( 'scroll', () => {
			if (
				!this.loadingEntries &&
				this.infinite &&
				this.next !== null &&
				this.$refs.container.getBoundingClientRect().bottom < window.innerHeight
			) {
				this.getEntries();
			}
		} );
	}
};
</script>
