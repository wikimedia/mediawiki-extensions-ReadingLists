<template>
	<cdx-dialog
		v-model:open="showImport"
		:title="msgImportTitle"
		:primary-action="{ actionType: 'progressive', label: msgImportButton }"
		:default-action="anonymizedPreviews ? null : { label: msgCancel }"
		:use-close-button="!anonymizedPreviews"
		@primary="onAppImport"
		@default="showImport = false"
		@update:open="onDialogClose">
		<p>{{ msgImportDisclaimer }}</p>

		<p>{{ msgImportApp }}</p>

		<a
			v-if="androidDownloadLink"
			class="reading-lists-play-store"
			:href="androidDownloadLink"
			target="_blank"></a>

		<a
			v-if="iosDownloadLink"
			class="reading-lists-app-store"
			:href="iosDownloadLink"
			target="_blank"></a>
	</cdx-dialog>
</template>

<script>
const { ref } = require( 'vue' );
const { CdxDialog } = require( '../../../codex.js' );
const { ReadingListAndroidAppDownloadLink, ReadingListiOSAppDownloadLink, ReadingListsAnonymizedPreviews } = require( '../../../config.json' );

// @vue/component
module.exports = exports = {
	components: { CdxDialog },
	setup() {
		return {
			showImport: ref( true ),
			anonymizedPreviews: ReadingListsAnonymizedPreviews,
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
		},
		onDialogClose( isOpen ) {
			if ( !isOpen && this.anonymizedPreviews ) {
				this.showImport = true;
			}
		}
	}
};
</script>
