<template>
	<div class="readinglist-page">
		<div class="readinglist-collection">
			<div class="readinglist-collection-summary">
				<h1 v-if="viewTitle">{{ viewTitle }}</h1>
				<p class="readinglist-collection-description">&nbsp;{{ viewDescription }} </p>
				<cdx-message v-if="showDisclaimer" type="warning">
					{{ disclaimer }}
					<div v-if="initialTitles">
						<div v-if="hasApp">
							<p>{{ $i18n( 'readinglists-import-app' ) }}</p>
						</div>
						<div v-if="isAndroid && hasApp">
							<a target="_blank" rel="noreferrer" :href="androidDownloadLink">
								<span class="app_store_images_sprite svg-badge_google_play_store"></span>
							</a>
						</div>
						<div v-if="isIOS && hasApp">
							<a target="_blank" rel="noreferrer" :href="iosDownloadLink">
								<span class="app_store_images_sprite svg-badge_ios_app_store"></span>
							</a>
						</div>
					</div>
				</cdx-message>
			</div>
			<div v-if="errorCode">
				<cdx-message type="error">{{ errorMessage }}</cdx-message>
			</div>
			<div v-if="loaded && !errorCode">
				<div class="readinglist-list">
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
					<p v-else>
						{{ emptyMessage }}
					</p>
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
			type: Array,
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
		hasApp: function () {
			return (
				this.iosDownloadLink && this.isIOS
			) || (
				this.isAndroid && this.androidDownloadLink
			);
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
		importList: function () {
			this.loaded = true;
			const titles = this.cards.map( ( card ) => card.title );
			window.location.search = `?limport=${this.api.toBase64( this.name, this.description, titles )}`;
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
				return;
			}
			if ( this.initialTitles ) {
				this.api.getPagesFromProjectMap( this.initialTitles ).then( ( pages ) => {
					this.cards = pages.map( ( page ) => getCard( page ) );
					this.loaded = true;
				}, ( err ) => {
					this.errorCode = err;
					this.loaded = true;
				} );
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