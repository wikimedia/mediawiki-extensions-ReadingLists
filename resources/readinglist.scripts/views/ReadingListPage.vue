<template>
	<div class="readinglist-page">
		<div class="readinglist-collection">
			<reading-list-summary
				:show-disclaimer="showDisclaimer"
				:show-meta="showMeta"
				:show-share-button="!showDisclaimer && collection && isShareEnabled"
				:disclaimer="disclaimer"
				:name="viewTitle"
				:description="viewDescription"
				:share-url="shareUrl"
			></reading-list-summary>
			<div v-if="errorCode">
				<cdx-message type="error">
					{{ errorMessage }}
				</cdx-message>
			</div>
			<div v-if="loaded && !errorCode">
				<reading-list
					v-if="!( anonymizedPreviews && showDisclaimer )"
					:cards="cards" :empty-message="emptyMessage" @click-card="clickCard"></reading-list>
			</div>
			<div v-if="!loaded">
				<intermediate-state></intermediate-state>
			</div>
		</div>
	</div>
</template>

<script>
/* global Card */
const { CdxMessage } = require( '@wikimedia/codex' );
const READING_LIST_SPECIAL_PAGE_NAME = 'Special:ReadingLists';
const READING_LISTS_NAME_PLURAL = mw.msg( 'special-tab-readinglists-short' );
const READING_LIST_TITLE = mw.msg( 'readinglists-special-title' );
const HOME_URL = ( new mw.Title( 'ReadingLists', -1 ) ).getUrl();

/**
 * @param {number} id
 * @return {Card}
 */
function getCard( { id, url, name, description, title, thumbnail, project, pageid } ) {
	return {
		loaded: false,
		id,
		url,
		pageid,
		project,
		// If it's a list, name
		// If it's a page on the list, title
		title: name || title,
		description,
		thumbnail
	};
}

// @vue/component
module.exports = {
	name: 'ReadingListPage',
	compatConfig: {
		MODE: 3
	},
	compilerOptions: {
		whitespace: 'condense'
	},
	components: {
		ReadingList: require( './ReadingList.vue' ),
		ReadingListSummary: require( './ReadingListSummary.vue' ),
		CdxMessage,
		IntermediateState: require( './IntermediateState.vue' )
	},
	props: {
		anonymizedPreviews: {
			type: Boolean,
			default: false
		},
		isImport: {
			type: Boolean
		},
		api: {
			type: Object,
			required: true
		},
		disclaimer: {
			type: String,
			default: ''
		},
		username: {
			type: String,
			default: ''
		},
		initialTitles: {
			type: Object,
			default: () => null
		},
		initialName: {
			type: String,
			default: ''
		},
		initialDescription: {
			type: String,
			default: ''
		},
		// eslint-disable-next-line vue/require-default-prop
		initialCollection: {
			type: Number,
			required: false
		}
	},
	data: function () {
		return {
			showDisclaimer: this.disclaimer !== '',
			ignore: [],
			titlesToLoad: this.initialTitles,
			loaded: false,
			initialized: false,
			cards: [],
			name: this.initialName,
			description: this.initialDescription,
			collection: this.initialCollection,
			errorCode: 0
		};
	},
	computed: {
		shareUrl() {
			const list = {};
			// ID is preferred if available, as it results in a shorter URL
			const shareField = this.cards.filter(
				( card ) => !!card.pageid
			).length === this.cards.length ? 'pageid' : 'title';
			this.cards.forEach( ( card ) => {
				if ( !list[ card.project ] ) {
					list[ card.project ] = [];
				}
				list[ card.project ].push( card[ shareField ] );
			} );
			// https://wikitech.wikimedia.org/wiki/Provenance
			// product reading list web 1
			const wprov = 'prlw1';
			const base64 = this.api.toBase64( this.name, this.description, list );
			if ( !base64 || !Object.keys( list ).length ) {
				return '';
			}
			const url = new URL(
				`${location.pathname}?limport=${base64}&wprov=${wprov}`,
				`${location.protocol}//${location.host}`
			);
			return url.toString();
		},
		showMeta() {
			return this.showDisclaimer ? !this.anonymizedPreviews : true;
		},
		readingListClass() {
			return {
				'readinglist-list--hidden': this.anonymizedPreviews && this.showDisclaimer,
				'readinglist-list': true
			};
		},
		getHomeUrl: function () {
			return HOME_URL;
		},
		viewDescription: function () {
			return this.description ||
				( this.collection ? '' : mw.msg( 'readinglists-description' ) );
		},
		viewTitle: function () {
			return this.name || mw.msg( 'special-tab-readinglists-short' );
		},
		emptyMessage: function () {
			return this.collection ?
				mw.msg( 'readinglists-list-empty-message' ) :
				mw.msg( 'readinglists-empty-message' );
		},
		errorMessage: function () {
			switch ( this.errorCode ) {
				case 'readinglists-import-error':
				case 'readinglists-db-error-no-such-list':
				case 'readinglists-db-error-list-deleted':
				case 'readinglists-import-size-error':
					// eslint-disable-next-line mediawiki/msg-doc
					return mw.msg( this.errorCode, this.collection );
				default:
					return 'An unknown error occurred (' + this.errorCode + ')';
			}
		}
	},
	methods: {
		isShareEnabled: () => navigator.share || navigator.clipboard,
		clickCard: function ( href, ev ) {
			// If we are navigating to a list, navigate internally
			if ( !this.collection ) {
				this.navigate( href, null );
				ev.preventDefault();
			}
		},
		/**
		 * Can be used externally to navigate to the home page.
		 */
		navigateHome: function () {
			this.navigate( HOME_URL, READING_LIST_TITLE );
		},
		getUrlFromHref: function ( href ) {
			const query = href.split( '?' )[ 1 ];
			const titleInQuery = query ? query.replace( /title=(.*)(&|$)/, '$1' ) : false;
			if ( titleInQuery ) {
				return '/wiki/' + titleInQuery;
			} else {
				return href;
			}
		},
		getState: function () {
			return {
				name: this.name,
				description: this.description,
				collection: this.collection
			};
		},
		load: function () {
			if ( this.loaded ) {
				if ( this.errorCode || !this.username ) {
					return;
				}
				const state = this.getState();
				document.title = this.name ||
					READING_LISTS_NAME_PLURAL;
				window.history.replaceState(
					state,
					null,
					this.collection ?
						mw.util.getUrl( `${READING_LIST_SPECIAL_PAGE_NAME}/${this.username}/${this.collection}/${this.name}` ) :
						mw.util.getUrl( `${READING_LIST_SPECIAL_PAGE_NAME}/${this.username}` )
				);
				return;
			}
			if ( this.titlesToLoad ) {
				if ( this.anonymizedPreviews ) {
					this.collection = -1;
					this.cards = [];
					this.loaded = true;
					this.titlesToLoad = undefined;
				} else {
					this.api.getPagesFromProjectMap( this.titlesToLoad ).then( ( pages ) => {
						this.collection = -1;
						this.cards = pages.map( ( page ) => getCard( page ) );
						this.loaded = true;
						this.titlesToLoad = undefined;
					}, ( err ) => {
						this.errorCode = err;
						this.loaded = true;
						this.titlesToLoad = undefined;
					} );
				}
			} else if ( this.collection ) {
				this.api.getCollectionMeta( this.username, parseInt( this.collection, 10 ) ).then( ( meta ) => {
					this.api.getPages( parseInt( this.collection, 10 ) ).then( ( pages ) => {
						this.cards = pages.map( ( collection ) => getCard( collection ) );
						this.loaded = true;
						this.name = meta.name;
						this.description = meta.description;
					} );
				}, function ( code ) {
					this.collection = undefined;
					this.errorCode = code;
					this.loaded = true;
				}.bind( this ) );
			} else if ( this.username ) {
				this.api.getCollections( this.username, [] ).then( function ( collections ) {
					this.cards = collections.map( ( collection ) => getCard( collection ) );
					this.loaded = true;
				}.bind( this ) );
			} else {
				this.errorCode = 'readinglists-import-error';
				this.loaded = true;
			}
		},
		reset: function () {
			this.errorCode = 0;
			this.name = '';
			this.description = '';
			this.cards = [];
			this.collection = false;
			this.loaded = false;
		},
		navigate: function ( url, title ) {
			this.showDisclaimer = false;
			const articlePathPrefix = mw.config.get( 'wgArticlePath' ).replace( '$1', '' );
			this.reset();
			const params = url.split( articlePathPrefix )[ 1 ];
			const paramArray = params.split( '/' ).slice( 1 );
			// <username>/<id>/<name>
			this.collection = paramArray[ 1 ];
			this.name = paramArray[ 2 ] ?
				decodeURIComponent( paramArray[ 2 ].replace( /_/g, ' ' ) ) : '';

			history.pushState(
				this.getState(),
				title,
				url
			);
		}
	},
	updated: function () {
		this.load();
	},
	mounted: function () {
		this.load();
		window.addEventListener( 'popstate', function ( ev ) {
			if ( ev.state ) {
				this.reset();
				Object.keys( ev.state ).forEach( function ( key ) {
					this[ key ] = ev.state[ key ];
				}.bind( this ) );
			}
		}.bind( this ) );
	}
};
</script>

<style lang="less">
.readinglist-list__container {
	.cdx-card {
		margin-bottom: 1em;
	}
}

.readinglist-collection {
	// stylelint-disable-next-line declaration-property-unit-disallowed-list
	font-size: 16px;

	p {
		margin-top: 16px;
	}

	ol {
		margin-top: 16px;
		padding-left: 0;
		list-style: inside decimal;
	}
}

.readinglist-list--hidden {
	display: none;
}
</style>
