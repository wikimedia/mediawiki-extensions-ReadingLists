<template>
	<div class="readinglist-collection-summary">
		<div v-if="showMeta">
			<h3 v-if="name">
				{{ name }}
			</h3>
			<p class="readinglist-collection-description">
				{{ description }}
			</p>
			<p v-if="isWatchlist" v-html="watchlistMsg"></p>
			<cdx-button
				v-if="showShareButton && shareUrl"
				@click="clickShareButton">
				{{ shareLabel }}
			</cdx-button>
		</div>
		<reading-list-download
			v-if="showDisclaimer"
			:disclaimer="disclaimer"></reading-list-download>
	</div>
</template>

<script>
const { CdxButton } = require( '@wikimedia/codex' );
const ReadingListDownload = require( './ReadingListDownload.vue' );

// @vue/component
module.exports = {
	name: 'ReadingListSummary',
	components: {
		CdxButton,
		ReadingListDownload
	},
	props: {
		isWatchlist: {
			type: Boolean,
			default: false
		},
		showMeta: {
			type: Boolean,
			default: false
		},
		showShareButton: {
			type: Boolean,
			default: false
		},
		showDisclaimer: {
			type: Boolean,
			default: false
		},
		description: {
			type: String,
			default: ''
		},
		name: {
			type: String,
			default: ''
		},
		disclaimer: {
			type: String,
			default: ''
		},
		shareUrl: {
			type: String,
			default: ''
		}
	},
	computed: {
		watchlistMsg() {
			return mw.message( 'readinglists-watchlist-monitor', mw.util.getUrl( 'Special:Watchlist' ) ).parse();
		},
		shareLabel() {
			return mw.msg( 'readinglists-export' );
		}
	},
	methods: {
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
			this.shareList( this.name, this.description, this.shareUrl );
		}
	}
};
</script>

<style lang="less">
.readinglist-collection-summary {
	margin: 7px 0 10px 0;

	h3 {
		border-bottom: 0;
		font-weight: bold;
		margin: 0;
		padding: 0;
	}

	p {
		padding: 0;
	}
}

.readinglist-collection--description {
	margin-bottom: 24px;
}

</style>
