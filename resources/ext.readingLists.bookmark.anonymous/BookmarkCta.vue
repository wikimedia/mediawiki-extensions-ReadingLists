<template>
	<cdx-popover
		v-model:open="isOpen"
		class="readinglists-bookmark-cta"
		:anchor="anchor"
		:title="title"
		:use-close-button="true"
		:close-button-label="closeButtonLabel"
		:use-bottom-sheet="useBottomSheet"
		placement="bottom"
		@update:open="onUpdateOpen"
	>
		<p class="readinglists-bookmark-cta__call-to-action">
			{{ callToAction }}
		</p>

		<div
			v-if="isMobile"
			class="readinglists-bookmark-cta__illustration"
			:style="{ backgroundImage: `url( ${illustrationPath} )` }"
		></div>

		<template #footer>
			<div class="readinglists-bookmark-cta__actions">
				<a
					:class="getFakeButtonClasses( 'primary' )"
					:href="createAccountUrl"
					@click="rememberPageToSave">
					{{ primaryActionLabel }}
				</a>
				<a
					:class="getFakeButtonClasses( 'default' )"
					:href="loginUrl"
					@click="rememberPageToSave">
					{{ defaultActionLabel }}
				</a>
			</div>
		</template>
	</cdx-popover>
</template>

<script>
const { computed, ref } = require( 'vue' );
const { CdxPopover } = require( '../../codex.js' );
const { useIsMobileResolution } = require( 'ext.readingLists.common' );

// this needs to match AUTOSAVE_STORAGE_KEY in bookmark.js
const AUTOSAVE_STORAGE_KEY = 'readinglists-cta-autosave';
const AUTOSAVE_EXPIRY_SECONDS = 3600;

/**
 * Bookmark call to action shown to anonymous users when they click the bookmark button, prompting sign-in.
 * Mobile viewports use the bottom sheet variant, which includes the illustration.
 */
// @vue/component
module.exports = exports = {
	name: 'BookmarkCta',
	components: {
		CdxPopover
	},
	props: {
		anchor: {
			type: [ HTMLElement, Object ],
			required: true
		},
		onClose: {
			type: Function,
			default: () => {}
		}
	},
	setup( props ) {
		const isOpen = ref( true );
		const isMobile = useIsMobileResolution();
		// "responsive" follows Codex's breakpoint. This feature uses useIsMobileResolution.
		const useBottomSheet = computed( () => ( isMobile.value ? 'always' : 'never' ) );

		const title = computed( () => mw.msg( 'readinglists-cta-dialog-title' ) );
		const callToAction = computed( () => mw.msg( 'readinglists-cta-dialog-call-to-action' ) );
		const closeButtonLabel = computed( () => mw.msg( 'readinglists-cta-dialog-close' ) );

		const illustrationPath = computed( () => mw.config.get( 'wgExtensionAssetsPath' ) +
			'/ReadingLists/resources/assets/cta-dialog-illustration.svg' );

		const primaryActionLabel = computed( () => mw.msg( 'readinglists-cta-dialog-create-account' ) );
		const defaultActionLabel = computed( () => mw.msg( 'readinglists-cta-dialog-log-in' ) );

		const returnTo = mw.config.get( 'wgPageName' );
		const urlParams = {
			returnto: returnTo
		};

		// Signup instrumentation relies on type=signup and a returntoquery marker
		// so the redirect flow can be attributed back to this call to action.
		const createAccountUrl = computed( () => mw.util.getUrl( 'Special:CreateAccount', {
			returnto: returnTo,
			returntoquery: 'readingListsAccountCreationCta=1',
			type: 'signup',
			// Needed to skip the newcomer welcome survey. See T422169.
			campaign: 'account-creation-reading-list-cta'
		} ) );
		const loginUrl = computed( () => mw.util.getUrl( 'Special:UserLogin', urlParams ) );

		function rememberPageToSave() {
			mw.storage.session.set( AUTOSAVE_STORAGE_KEY, returnTo, AUTOSAVE_EXPIRY_SECONDS );
		}

		function getFakeButtonClasses( action ) {
			return {
				'cdx-button cdx-button--fake-button cdx-button--fake-button--enabled': true,
				'cdx-button--weight-primary cdx-button--action-progressive': action === 'primary'
			};
		}

		function onUpdateOpen( newOpenState ) {
			if ( !newOpenState ) {
				props.onClose();
			}
		}

		return {
			isOpen,
			isMobile,
			useBottomSheet,
			title,
			callToAction,
			closeButtonLabel,
			illustrationPath,
			primaryActionLabel,
			createAccountUrl,
			defaultActionLabel,
			loginUrl,
			rememberPageToSave,
			getFakeButtonClasses,
			onUpdateOpen
		};
	}
};
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

.readinglists-bookmark-cta {
	&:not( .cdx-popover--bottom-sheet ) {
		width: @size-1600;
	}

	&__illustration {
		background-repeat: no-repeat;
		background-position: center;
		height: 10em;
		margin-top: @spacing-150;
	}

	&__actions {
		display: flex;
		flex-direction: column;
		gap: @spacing-75;
		width: @size-full;

		.cdx-button {
			max-width: none;
		}
	}
}
</style>
