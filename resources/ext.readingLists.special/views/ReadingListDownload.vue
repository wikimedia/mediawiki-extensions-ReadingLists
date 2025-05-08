<template>
	<div class="reading-list-download">
		{{ disclaimer }}
		<div v-if="hasApp">
			<ol>
				<li>
					<!-- eslint-disable vue/no-v-html -->
					<span v-html="importMessage"></span>
					<div v-if="isAndroid && androidDownloadLink">
						<a
							target="_blank"
							rel="noreferrer"
							:href="androidDownloadLink">
							<span
								class="app_store_images_sprite svg-badge_google_play_store"></span>
						</a>
					</div>
					<div v-else-if="isIOS && iosDownloadLink">
						<a
							target="_blank"
							rel="noreferrer"
							:href="iosDownloadLink">
							<span
								class="app_store_images_sprite svg-badge_ios_app_store"></span>
						</a>
					</div>
					<div v-else>
						<a
							v-if="androidDownloadLink"
							target="_blank"
							rel="noreferrer"
							:href="androidDownloadLink">
							<span
								class="app_store_images_sprite svg-badge_google_play_store"></span>
						</a>
						<a
							v-if="iosDownloadLink"
							target="_blank"
							rel="noreferrer"
							:href="iosDownloadLink">
							<span
								class="app_store_images_sprite svg-badge_ios_app_store"></span>
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
</template>

<script>
const { CdxButton } = require( '@wikimedia/codex' );
const { ReadingListiOSAppDownloadLink,
	ReadingListAndroidAppDownloadLink } = require( 'ext.readingLists.api' ).legacy.config;
const { getEnabledMessage } = require( './helpers.js' );

// @vue/component
module.exports = {
	name: 'ReadingListDownload',
	components: {
		CdxButton
	},
	props: {
		androidDownloadLink: {
			type: String,
			default: ReadingListAndroidAppDownloadLink
		},
		disclaimer: {
			type: String,
			default: ''
		},
		iosDownloadLink: {
			type: String,
			default: ReadingListiOSAppDownloadLink
		},
		isAndroid: {
			type: Boolean,
			// eslint-disable-next-line vue/no-boolean-default
			default: window.navigator.userAgent.includes( 'Android' )
		},
		isIOS: {
			type: Boolean,
			// eslint-disable-next-line vue/no-boolean-default
			default: window.navigator.userAgent.includes( 'iPhone' ) || window.navigator.userAgent.includes( 'iPad' )
		}
	},
	computed: {
		importButtonLabel() {
			return mw.msg( 'readinglists-import-button-label' );
		},
		importButtonHint() {
			return mw.msg( 'readinglists-import-button-hint' );
		},
		hasApp: function () {
			return (
				this.iosDownloadLink || this.androidDownloadLink
			);
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
		noAppMessage() {
			return getEnabledMessage( 'readinglists-import-app-misconfigured' );
		}
	},
	methods: {
		clickDeepLink: function () {
			try {
				window.location.protocol = 'wikipedia';
				setTimeout( () => mw.notify( mw.msg( 'readinglists-import-app-launch-hint' ) ), 1000 );
			} catch ( e ) {
				// User does not have the app installed.
			}
		}
	}
};
</script>

<style lang="less">
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
