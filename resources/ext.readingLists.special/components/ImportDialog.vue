<template>
	<cdx-dialog
		v-model:open="showImport"
		:title="msgImportTitle"
		:primary-action="{ actionType: 'progressive', label: msgImportButton }"
		:default-action="{ label: msgCancel }"
		:use-close-button="true"
		@primary="onAppImport"
		@default="showImport = false">
		<p>{{ msgImportDisclaimer }}</p>

		<p>{{ msgImportApp }}</p>

		<a
			v-if="androidDownloadLink"
			class="readinglists-play-store"
			:href="androidDownloadLink"
			target="_blank"></a>

		<a
			v-if="iosDownloadLink"
			class="readinglists-app-store"
			:href="iosDownloadLink"
			target="_blank"></a>
	</cdx-dialog>
</template>

<script>
const { ref } = require( 'vue' );
const { CdxDialog } = require( '../../../codex.js' );
const { ReadingListAndroidAppDownloadLink, ReadingListiOSAppDownloadLink } = require( '../../../config.json' );

// @vue/component
module.exports = exports = {
	components: { CdxDialog },
	setup() {
		return {
			showImport: ref( true ),
			androidDownloadLink: ReadingListAndroidAppDownloadLink,
			iosDownloadLink: ReadingListiOSAppDownloadLink,
			msgImportTitle: mw.msg( 'readinglists-special-title-imported' ),
			msgImportDisclaimer: mw.msg( 'readinglists-import-disclaimer' ),
			msgImportApp: mw.msg( 'readinglists-import-app' ),
			msgImportButton: mw.msg( 'readinglists-import-button-label' ),
			msgCancel: mw.msg( 'cancel' )
		};
	},
	methods: {
		onAppImport() {
			const deepLink = new URL( 'wikipedia://' );
			deepLink.hostname = window.location.hostname;
			deepLink.pathname = window.location.pathname;
			deepLink.search = window.location.search;

			window.open( deepLink.href, '_self' );
		}
	}
};
</script>
