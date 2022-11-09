<template>
	<div class="readinglist-page">
		<div class="readinglist-collection">
			<div class="readinglist-collection-summary">
				<div v-if="showMeta">
					<h1 v-if="viewTitle">{{ viewTitle }}</h1>
					<p class="readinglist-collection-description">&nbsp;{{ viewDescription }} </p>
					<cdx-button v-if="!showDisclaimer" @click="clickImportList">{{ shareLabel }}</cdx-button>
				</div>
				<cdx-message v-if="showDisclaimer" type="warning">
					{{ disclaimer }}
					<div v-if="hasApp">
						<p>{{ importMessage }}</p>
						<div v-if="isAndroid">
							<a target="_blank" rel="noreferrer" :href="androidDownloadLink">
								<span class="app_store_images_sprite svg-badge_google_play_store"></span>
							</a>
						</div>
						<div v-else-if="isIOS">
							<a target="_blank" rel="noreferrer" :href="iosDownloadLink">
								<span class="app_store_images_sprite svg-badge_ios_app_store"></span>
							</a>
						</div>
						<div v-else>
							<a target="_blank" rel="noreferrer" :href="androidDownloadLink">
								<span class="app_store_images_sprite svg-badge_google_play_store"></span>
							</a>
							<a target="_blank" rel="noreferrer" :href="iosDownloadLink">
								<span class="app_store_images_sprite svg-badge_ios_app_store"></span>
							</a>
						</div>
					</div>
					<div v-else>
						{{ noAppMessage }}
					</div>
				</cdx-message>
			</div>
			<div v-if="errorCode">
				<cdx-message type="error">{{ errorMessage }}</cdx-message>
			</div>
			<div v-if="loaded && !errorCode">
				<div :class="readingListClass">
					<div class="readinglist-list__container" v-if="cards.length">
						<cdx-card
							v-for="(card) in cards"
							:key="card.id"
							:url="card.url"
							:force-thumbnail="true"
							:thumbnail="card.thumbnail"
							@click="clickCard"
						>
							<template #title>
								{{ card.title }}
							</template>
							<template #description>
								{{ card.description }}
							</template>
						</cdx-card>
					</div>
					<div v-else>
						{{ emptyMessage }}
					</div>
				</div>
			</div>
			<div v-if="!loaded">
				<intermediate-state></intermediate-state>
			</div>
		</div>
	</div>
</template>

<script>
const { CdxCard, CdxMessage, CdxButton } = require( '@wikimedia/codex' );
const { ReadingListiOSAppDownloadLink,
	ReadingListAndroidAppDownloadLink } = require( '../config.json' );
const READING_LIST_SPECIAL_PAGE_NAME = 'Special:ReadingLists';
const READING_LISTS_NAME_PLURAL = mw.msg( 'special-tab-readinglists-short' );
const READING_LIST_TITLE = mw.msg( 'readinglists-special-title' );
const HOME_URL = ( new mw.Title( 'ReadingLists', -1 ) ).getUrl();

const getEnabledMessage = ( key ) => {
	const text = mw.msg( key );
	return text === '-' ? '' : text;
};

/**
 * @param {number} id
 * @return {Card}
 */
function getCard( { id, url, name, description, title, thumbnail, project } ) {
	return {
		loaded: false,
		id,
		url,
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
	components: {
		CdxButton,
		CdxCard,
		CdxMessage,
		IntermediateState: require( './IntermediateState.vue' )
	},
	props: {
		iosDownloadLink: {
			type: String,
			default: ReadingListiOSAppDownloadLink
		},
		anonymizedPreviews: {
			type: Boolean,
			default: false
		},
		androidDownloadLink: {
			type: String,
			default: ReadingListAndroidAppDownloadLink
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
			required: false
		},
		initialName: {
			type: String,
			default: ''
		},
		initialDescription: {
			type: String,
			default: ''
		},
		initialCollection: {
			type: Number,
			required: false
		},
		isAndroid: {
			type: Boolean,
			default: window.navigator.userAgent.includes( 'Android' )
		},
		isIOS: {
			type: Boolean,
			default: window.navigator.userAgent.includes( 'iPhone' ) || window.navigator.userAgent.includes( 'iPad' )
		}
	},
	computed: {
		showMeta() {
			return this.showDisclaimer ? !this.anonymizedPreviews : true;
		},
		readingListClass() {
			return {
				'readinglist-list--hidden': this.anonymizedPreviews && this.showDisclaimer,
				'readinglist-list': true
			}
		},
		hasApp: function () {
			return (
				this.iosDownloadLink || this.androidDownloadLink
			);
		},
		shareUrl: function () {
			return HOME_URL;
		},
		getHomeUrl: function () {
			return HOME_URL;
		},
		viewDescription: function () {
			return this.description ||
				( this.collection ? '' : mw.msg( 'readinglists-description' ) );
		},
		readingListUrl: function () {
			return getReadingListUrl( mw.user.getName() );
		},
		viewTitle: function () {
			return this.name || mw.msg( 'special-tab-readinglists-short' );
		},
		noAppMessage() {
			return getEnabledMessage( 'readinglists-import-app-misconfigured' );
		},
		importMessage() {
			return getEnabledMessage( 'readinglists-import-app' );
		},
		shareLabel() {
			return mw.msg( 'readinglists-export' );
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
					return mw.msg( this.errorCode, this.collection );
				default:
					return 'An unknown error occurred (' + this.errorCode + ')';
			}
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
	methods: {
		clickImportList: function () {
			const list = {};
			this.cards.forEach( ( card ) => {
				if ( !list[ card.project ] ) {
					list[ card.project ] = [];
				}
				list[ card.project ].push( card.title );
		 	} );
			window.location.search = `?limport=${this.api.toBase64( this.name, this.description, list )}`;
		},
		clickCard: function ( ev ) {
			// If we are navigating to a list, navigate internally
			if ( !this.collection ) {
				this.navigate( ev.currentTarget.getAttribute( 'href' ), null );
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
				if (this.anonymizedPreviews) {
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
				this.api.getCollectionMeta( this.username, this.collection ).then( ( meta ) => {
					this.api.getPages( this.collection ).then( ( pages ) => {
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
	font-size: 16px;

	&-summary {
		color: #555555;
		font-size: 0.85em;
		margin: 7px 0 10px 0;
		text-align: center;

		h1 {
			border-bottom: 0;
			margin-top: 0;
			font-weight: bold;
		}
	}

	&-description {
		margin-bottom: 24px;
	}

	p {
		margin-top: 16px;
	}
}

.readinglist-list--hidden {
	display: none;
}

.app_store_images_sprite {
 	background-image: linear-gradient(transparent,transparent),url(images/sprite.svg);
 	background-repeat: no-repeat;
	display: inline-block;
	vertical-align: middle;
}

.svg-badge_google_play_store {
	background-position: 0 -541px;
	width: 124px;
	height: 38px;
}

.svg-badge_ios_app_store {
	background-position: 0 -579px;
	width: 110px;
	height: 38px;
}
</style>
