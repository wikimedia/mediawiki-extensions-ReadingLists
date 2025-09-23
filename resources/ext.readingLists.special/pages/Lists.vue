<template>
	<cdx-message v-if="error" type="error">
		{{ error }}
	</cdx-message>

	<template v-else>
		<h1>{{ msgTitle }}</h1>

		<div v-if="enableToolbar" class="reading-lists-toolbar">
			<display-button
				v-show="ready"
				:disabled="loadingLists"
				@ready="onReady"
				@changed="getLists">
			</display-button>
		</div>

		<div
			v-show="lists.length !== 0"
			ref="container"
			:class="'reading-lists-' + options[ 2 ]">
			<list-item
				v-for="list in lists"
				:key="list.id"
				:title="list.name"
				:description="list.description"
				:thumbnail="list.thumbnail"
				:size="list.size">
			</list-item>
		</div>

		<cdx-button v-if="!infinite && next !== null" @click="onShowMore">
			{{ msgShowMore }}
		</cdx-button>

		<cdx-progress-bar
			v-if="loadingLists"
			:aria-label="msgLoading">
		</cdx-progress-bar>
	</template>
</template>

<script>
const { ref } = require( 'vue' );
const api = require( 'ext.readingLists.api' );
const { CdxButton, CdxMessage, CdxProgressBar } = require( '../../../codex.js' );
const DisplayButton = require( '../components/DisplayButton.vue' );
const ListItem = require( '../components/ListItem.vue' );
const { ReadingListsEnableSpecialPageToolbar } = require( '../../../config.json' );

// @vue/component
module.exports = exports = {
	components: {
		CdxButton,
		CdxProgressBar,
		CdxMessage,
		ListItem,
		DisplayButton
	},

	setup() {
		return {
			loadingLists: ref( true ),
			error: ref( '' ),
			ready: ref( false ),
			options: ref( [] ),
			lists: ref( [] ),
			next: ref( null ),
			infinite: ref( false ),
			msgTitle: mw.msg( 'readinglists-title' ),
			msgShowMore: mw.msg( 'readinglists-show-more' ),
			msgLoading: mw.msg( 'readinglists-loading' ),
			enableToolbar: ReadingListsEnableSpecialPageToolbar
		};
	},
	methods: {
		handleError( err ) {
			// eslint-disable-next-line no-console
			console.error( err );

			if ( typeof err === 'string' ) {
				// eslint-disable-next-line mediawiki/msg-doc
				this.error = mw.msg( err );
			} else {
				this.error = err.toString();
			}
		},
		async getLists( options = null, init = false ) {
			if ( options === null ) {
				this.loadingLists = true;
			} else {
				const sort = options[ 0 ].replace( 's:', '' );
				const direction = options[ 1 ].replace( 'd:', '' );
				const view = options[ 2 ].replace( 'v:', '' );

				if ( sort !== this.options[ 0 ] || direction !== this.options[ 1 ] ) {
					this.loadingLists = true;
					this.lists = [];
					this.next = null;
					this.infinite = false;
				}

				this.options = [ sort, direction, view ];
			}

			if ( this.loadingLists ) {
				try {
					const { lists, next } = await api.getLists(
						this.options[ 0 ],
						this.options[ 1 ],
						12,
						this.next
					);

					this.lists.push( ...lists );
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
					this.loadingLists = false;
				}
			}
		},
		async onReady( options ) {
			await this.getLists( options, true );
		},
		async onShowMore() {
			await this.getLists();
			this.infinite = true;
		}
	},
	async mounted() {
		if ( !this.enableToolbar ) {
			this.options = [ 'updated', 'descending', 'grid' ];
			await this.getLists( null, true );
		}

		document.addEventListener( 'scroll', () => {
			if (
				!this.loadingLists &&
				this.infinite &&
				this.next !== null &&
				this.$refs.container.getBoundingClientRect().bottom < window.innerHeight
			) {
				this.getLists();
			}
		} );
	}
};
</script>
