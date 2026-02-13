<template>
	<cdx-message v-if="error" type="error">
		{{ error }}
	</cdx-message>

	<template v-else>
		<import-dialog v-if="imported !== null"></import-dialog>

		<template v-if="!isDefaultList && !isAllListItems">
			<h2 v-if="title" class="reading-lists-title">
				{{ title }}
			</h2>

			<p v-if="description" class="reading-lists-description">
				{{ description }}
			</p>
		</template>

		<p v-if="!enableToolbar" class="reading-lists-sorting">
			{{ sortingText }}
		</p>

		<div
			v-if="enableToolbar"
			v-show="ready && ( loadingEntries || entries.length !== 0 )"
			class="reading-lists-toolbar">
			<display-button
				:disabled="loadingInfo || loadingEntries"
				:imported="imported !== null"
				@ready="onReady"
				@changed="getEntries">
			</display-button>
		</div>

		<template v-if="!loadingInfo">
			<div
				v-if="entries.length !== 0"
				ref="container"
				class="reading-lists-grid">
				<entry-item
					v-for="entry in entries"
					:key="entry.id"
					:entry="entry">
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
const EmptyList = require( '../components/EmptyList.vue' );
const EntryItem = require( '../components/EntryItem.vue' );
const ImportDialog = require( '../components/ImportDialog.vue' );
const { ReadingListsEnableSpecialPageToolbar } = require( '../../../config.json' );

// @vue/component
module.exports = exports = {
	components: {
		CdxButton,
		CdxMessage,
		CdxProgressBar,
		DisplayButton,
		EmptyList,
		EntryItem,
		ImportDialog
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
			isDefaultList: ref( true ),
			isAllListItems: ref( false ),
			description: ref( '' ),
			total: ref( 0 ),
			error: ref( '' ),
			ready: ref( false ),
			options: ref( [ 'updated', 'descending', 'grid' ] ),
			entries: ref( [] ),
			next: ref( null ),
			infinite: ref( false ),
			sortingText: mw.msg( 'readinglists-sorted-by-recent' ),
			msgLoading: mw.msg( 'readinglists-loading' ),
			msgShowMore: mw.msg( 'readinglists-show-more' ),
			msgTotalArticles: ref( mw.msg( 'readinglists-total-articles', 0 ) ),
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
				if ( this.imported || this.listId ) {
					const list = this.imported || await api.getList( this.listId );

					if ( list.error !== undefined ) {
						throw list.error;
					}

					this.title = list.name;
					this.description = list.description;
					this.total = list.size;
					this.isDefaultList = !!list.default;
				} else {
					this.isDefaultList = false;
					this.isAllListItems = true;
				}
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

				if ( sort !== this.options[ 0 ] || direction !== this.options[ 1 ] ) {
					clear = true;
				}

				this.options = [ sort, direction ];
			}

			if ( clear ) {
				this.clearEntries();
				this.loadingEntries = true;

				await this.getList();

				if ( !this.isAllListItems && this.total === 0 ) {
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
		async onReady( options ) {
			await this.initializePage( options );
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
