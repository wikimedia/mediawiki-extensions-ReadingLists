<template>
	<cdx-tabs
		v-model:active="currentTab"
		:framed="framed"
		class="readinglist-page">
		<cdx-tab
			key="1"
			name="tabHome"
			:label="listsTitle"
			class="readinglist-collection">
			<reading-list-summary
				:show-disclaimer="showDisclaimer"
				:show-meta="showMeta"
				:show-share-button="false"
				:disclaimer="disclaimer"
				:description="listsDescription"
				:share-url="shareUrl"
			></reading-list-summary>
			<div v-if="errorCodeListsOfList">
				<cdx-message type="error">
					{{ errorMessageListsOfList }}
				</cdx-message>
			</div>
			<div v-if="loadedListsOfLists && !errorCodeListsOfList">
				<reading-list
					v-if="!( anonymizedPreviews && showDisclaimer )"
					:cards="listsOfLists"
					:empty-message="isImport ? emptyMessageImportListsOfLists : emptyMessageListsOfLists"
					@click-card.prevent="clickCard"></reading-list>
			</div>
			<div v-if="!loadedListsOfLists">
				<intermediate-state></intermediate-state>
			</div>
		</cdx-tab>
		<cdx-tab
			v-if="name"
			key="2"
			name="tabList"
			:label="name"
			class="readinglist-collection"
			@click="setTabUrl">
			<reading-list-summary
				:is-watchlist="api.WATCHLIST_ID === collection"
				:show-disclaimer="showDisclaimer"
				:show-meta="showMeta"
				:show-share-button="!showDisclaimer && isShareEnabled && cardsList.length"
				:disclaimer="disclaimer"
				:name="name"
				:description="description"
			></reading-list-summary>
			<div v-if="errorCode">
				<cdx-message type="error">
					{{ errorMessage }}
				</cdx-message>
			</div>
			<div v-if="loaded && !errorCode">
				<reading-list :cards="cardsList" :empty-message="emptyMessage"></reading-list>
			</div>
			<div v-if="!loaded">
				<intermediate-state></intermediate-state>
			</div>
		</cdx-tab>
	</cdx-tabs>
</template>

<script>
/* global Card */
const { CdxTab, CdxTabs, CdxMessage } = require( '@wikimedia/codex' );
const READING_LIST_SPECIAL_PAGE_NAME = 'Special:ReadingLists';

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

function getCollectionUrl( username, id, name ) {
	return id ? mw.util.getUrl( `${ READING_LIST_SPECIAL_PAGE_NAME }/${ username }/${ id }/${ name }` ) :
		mw.util.getUrl( `${ READING_LIST_SPECIAL_PAGE_NAME }/${ username }` );
}

// @vue/component
module.exports = {
	name: 'ReadingListPage',
	compilerOptions: {
		whitespace: 'condense'
	},
	components: {
		CdxTab,
		CdxTabs,
		ReadingList: require( './ReadingList.vue' ),
		ReadingListSummary: require( './ReadingListSummary.vue' ),
		CdxMessage,
		IntermediateState: require( './IntermediateState.vue' )
	},
	props: {
		isImport: {
			type: Boolean,
			default: false
		},
		anonymizedPreviews: {
			type: Boolean,
			default: false
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
		},
		listsTitle: {
			type: String,
			default: mw.msg( 'special-tab-readinglists-short' )
		},
		listsDescription: {
			type: String,
			default: mw.msg( 'readinglists-description' )
		},
		emptyMessage: {
			type: String,
			default: mw.msg( 'readinglists-list-empty-message' )
		},
		emptyMessageImportListsOfLists: {
			type: String,
			default: mw.msg( 'readinglists-import-empty-message' )
		},
		emptyMessageListsOfLists: {
			type: String,
			default: mw.msg( 'readinglists-empty-message' )

		}
	},
	data: function () {
		return {
			loadedListsOfLists: false,
			currentTab: this.initialCollection ? 'tabList' : 'tabHome',
			showDisclaimer: this.disclaimer !== '',
			titlesToLoad: this.initialTitles,
			loaded: false,
			listsOfLists: [],
			cardsList: [],
			name: this.initialName,
			description: this.initialDescription,
			collection: this.initialCollection,
			errorCodeListsOfList: 0,
			errorCode: 0
		};
	},
	computed: {
		shareUrl() {
			const list = {};
			// ID is preferred if available, as it results in a shorter URL
			const shareField = this.cardsList.filter(
				( card ) => !!card.pageid
			).length === this.cardsList.length ? 'pageid' : 'title';
			this.cardsList.forEach( ( card ) => {
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
				`${ location.pathname }?limport=${ base64 }&wprov=${ wprov }`,
				`${ location.protocol }//${ location.host }`
			);
			return url.toString();
		},
		showMeta() {
			return this.showDisclaimer ? !this.anonymizedPreviews : true;
		},
		errorMessageListsOfList: function () {
			return this.errorString( this.errorCodeListsOfList );
		},
		errorMessage: function () {
			return this.errorString( this.errorCode );
		}
	},
	methods: {
		errorString: function ( code ) {
			switch ( code ) {
				case 'readinglists-import-error':
				case 'readinglists-db-error-no-such-list':
				case 'readinglists-db-error-list-deleted':
				case 'readinglists-import-size-error':
					// eslint-disable-next-line mediawiki/msg-doc
					return mw.msg( code, this.collection );
				default:
					return 'An unknown error occurred (' + code + ')';
			}
		},
		isShareEnabled: () => navigator.share || navigator.clipboard,
		clickCard: function ( _ev, card ) {
			this.currentTab = 'tabList';
			this.collection = card.id;
			this.name = card.title;
			this.description = card.description;
			this.loadCollectionPages();
		},
		getState: function () {
			return this.currentTab === 'tabHome' ? {} : {
				name: this.name,
				description: this.description,
				collection: this.collection
			};
		},
		loadCollectionPages() {
			this.loaded = false;
			return this.api.getPages( this.collection ).then( ( pages ) => {
				this.cardsList = pages.map( ( collection ) => getCard( collection ) );
				this.loaded = true;
			} );
		},
		load: function () {
			if ( this.loaded ) {
				if ( this.errorCode || !this.username ) {
					return;
				}
				return;
			}

			// Load titles.
			if ( this.titlesToLoad ) {
				if ( this.anonymizedPreviews ) {
					this.cardsList = [];
					this.loaded = true;
					this.titlesToLoad = undefined;
				} else {
					this.api.getPagesFromProjectMap( this.titlesToLoad ).then( ( pages ) => {
						this.collection = -1;
						this.cardsList = pages.map( ( page ) => getCard( page ) );
						this.loaded = true;
						this.titlesToLoad = undefined;
					}, ( err ) => {
						this.errorCode = err;
						this.loaded = true;
						this.titlesToLoad = undefined;
					} );
				}
			} else if ( this.collection ) {
				this.api.getCollectionMeta(
					this.username, parseInt( this.collection, 10 )
				).then( ( meta ) => this.loadCollectionPages().then( () => {
					this.name = meta.name;
					this.description = meta.description;
				} ), ( code ) => {
					this.collection = undefined;
					this.errorCode = code;
					this.loaded = true;
				} );
			}

			// Load lists of list for this user.
			if ( this.username ) {
				this.api.getCollections( this.username, [] ).then( ( collections ) => {
					this.listsOfLists = collections.map( ( collection ) => getCard( collection ) );
					this.loadedListsOfLists = true;
				} );
			} else if ( this.isImport ) {
				// Special handling for imports - there are no lists of list so disable loading.
				this.loadedListsOfLists = true;
			} else {
				this.errorCodeListsOfList = 'readinglists-import-error';
				this.loaded = true;
			}
		}
	},
	updated: function () {
		this.load();
		const url = this.currentTab === 'tabList' ?
			getCollectionUrl( this.username, this.collection, this.name ) :
			getCollectionUrl( this.username );
		if ( url !== window.location.pathname ) {
			history.pushState( this.getState(), this.name, url );
		}
	},
	mounted: function () {
		this.load();
		if ( !this.isImport ) {
			history.replaceState( {
				collection: this.initialCollection
			}, this.name, window.location.pathname );
		}
		window.addEventListener( 'popstate', ( ev ) => {
			if ( ev.state ) {
				const collection = ev.state.collection;
				this.currentTab = collection ? 'tabList' : 'tabHome';
				if ( collection ) {
					this.collection = collection;
				}
				this.loadCollectionPages();
			}
		} );
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

/* If viewing of private reading lists is disabled, hide the tab. */
.mw-special-readinglist-export-only,
.mw-special-readinglist-watchlist-only {
	.cdx-tabs__header {
		display: none;
	}
}
</style>
