<template>
	<div class="readinglist-page">
		<div class="readinglist-collection">
			<div class="readinglist-collection-summary">
				<div v-if="showMeta">
					<h1 v-if="viewTitle">
						{{ viewTitle }}
					</h1>
					<p class="readinglist-collection-description">
						&nbsp;{{ viewDescription }}
					</p>
					<cdx-button
						v-if="!showDisclaimer && collection &&
							isShareEnabled"
						@click="clickShareButton">
						{{ shareLabel }}
					</cdx-button>
				</div>
				<div v-if="showDisclaimer">
					{{ disclaimer }}
					<div v-if="hasApp">
						<ol>
							<li>
								<span v-html="importMessage"></span>
								<div v-if="isAndroid && androidDownloadLink">
									<a
										target="_blank"
										rel="noreferrer"
										:href="androidDownloadLink">
										<span class="app_store_images_sprite svg-badge_google_play_store"></span>
									</a>
								</div>
								<div v-else-if="isIOS && iosDownloadLink">
									<a
										target="_blank"
										rel="noreferrer"
										:href="iosDownloadLink">
										<span class="app_store_images_sprite svg-badge_ios_app_store"></span>
									</a>
								</div>
								<div v-else>
									<a
										v-if="androidDownloadLink"
										target="_blank"
										rel="noreferrer"
										:href="androidDownloadLink">
										<span class="app_store_images_sprite svg-badge_google_play_store"></span>
									</a>
									<a
										v-if="iosDownloadLink"
										target="_blank"
										rel="noreferrer"
										:href="iosDownloadLink">
										<span class="app_store_images_sprite svg-badge_ios_app_store"></span>
									</a>
								</div>
							</li>
							<li>
								{{ importButtonHint }}
								<div>
									<cdx-button
										action="progressive"
										weight="primary"
										@click="clickDeepLink">
										{{ importButtonLabel }}
									</cdx-button>
								</div>
							</li>
						</ol>
					</div>
					<div v-else>
						{{ noAppMessage }}
					</div>
				</div>
			</div>
			<div v-if="errorCode">
				<cdx-message type="error">
					{{ errorMessage }}
				</cdx-message>
			</div>
			<div v-if="loaded && !errorCode">
				<div :class="readingListClass">
					<div v-if="cards.length" class="readinglist-list__container">
						<cdx-card
							v-for="( card ) in cards"
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

const getEnabledMessage = ( key, params ) => {
	// eslint-disable-next-line mediawiki/msg-doc
	const text = mw.msg( key, params );
	return text === '-' ? '' : text;
};

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
		showMeta() {
			return this.showDisclaimer ? !this.anonymizedPreviews : true;
		},
		readingListClass() {
			return {
				'readinglist-list--hidden': this.anonymizedPreviews && this.showDisclaimer,
				'readinglist-list': true
			};
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
			if ( this.isIOS && this.iosDownloadLink ) {
				return getEnabledMessage( 'readinglists-import-app-with-link', this.iosDownloadLink );
			} else if ( this.isAndroid && this.androidDownloadLink ) {
				return getEnabledMessage( 'readinglists-import-app-with-link', this.androidDownloadLink );
			} else {
				return getEnabledMessage( 'readinglists-import-app' );
			}
		},
		shareLabel() {
			return mw.msg( 'readinglists-export' );
		},
		importButtonLabel() {
			return mw.msg( 'readinglists-import-button-label' );
		},
		importButtonHint() {
			return mw.msg( 'readinglists-import-button-hint' );
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
		/**
		 * @param {string} title
		 * @param {string} text
		 * @param {string} url
		 * @return {Promise}
		 */
		shareList: ( title, text, url ) => {
			const msgArgs = text ?
				[ 'readinglists-share-url-text', title, text ] :
				[ 'readinglists-share-url-text-incomplete', title ];
			const shareData = { title, url,
				text: mw.msg.apply( null, msgArgs.concat( '' ) ).trim() };

			if ( navigator.share && navigator.canShare( shareData ) ) {
				return navigator.share( shareData );
			} else {
				return navigator.clipboard.writeText(
					mw.msg.apply( null, msgArgs.concat( url ) )
				).then( () => {
					mw.notify( mw.msg( 'readinglists-share-url-notify' ) );
				} );
			}
		},
		clickShareButton: function () {
			const list = {};
			// ID is preferred if available, as it results in a shorter URL
			const shareField = this.cards.filter( ( card ) => !!card.pageid ).length === this.cards.length ?
				'pageid' : 'title';
			this.cards.forEach( ( card ) => {
				if ( !list[ card.project ] ) {
					list[ card.project ] = [];
				}
				list[ card.project ].push( card[ shareField ] );
			} );
			// https://wikitech.wikimedia.org/wiki/Provenance
			// product reading list web 1
			const wprov = 'prlw1';
			const url = new URL(
				`${location.pathname}?limport=${this.api.toBase64( this.name, this.description, list )}&wprov=${wprov}`,
				`${location.protocol}//${location.host}`
			);
			this.shareList( this.name, this.description, url.toString() );
		},
		clickDeepLink: function () {
			try {
				window.location.protocol = 'wikipedia';
				setTimeout( () => mw.notify( mw.msg( 'readinglists-import-app-launch-hint' ) ), 1000 );
			} catch ( e ) {
				// User does not have the app installed.
			}
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
	// stylelint-disable-next-line declaration-property-unit-disallowed-list
	font-size: 16px;

	&-summary {
		margin: 7px 0 10px 0;

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

	ol {
		margin-top: 16px;
		padding-left: 0;
		list-style: inside decimal;
	}
}

.readinglist-list--hidden {
	display: none;
}

.app_store_images_sprite {
	background-image: url( images/sprite.svg );
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
