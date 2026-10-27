<template>
	<cdx-popover
		ref="popover"
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
const { computed, nextTick, onMounted, ref } = require( 'vue' );
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
		const popover = ref();
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

		onMounted( async () => {
			// The popover is not reachable by keyboard so move focus to it.
			// We must move focus to the body and not a button bc codex buttons activate on keyup.
			// So keydown opens the popover and keyup triggers the button it lands on.
			await nextTick();
			if ( !popover.value ) {
				return;
			}
			const popoverFocusHolder = popover.value.focusHolder;
			// Focus on the CdxPopover's focus holder. The next tab keypress
			// will go to the first focusable element.
			if ( popoverFocusHolder ) {
				popoverFocusHolder.focus( { preventScroll: true } );
			}

			const popoverPanel = popover.value.panel;
			if ( !popoverPanel ) {
				return;
			}

			const closeButton = popoverPanel.querySelector( '.cdx-popover__header__close-button' );
			if ( !closeButton ) {
				return;
			}
			// useFocusTrap in Codex calls scrollIntoView on the popover body when the focus wraps
			// btw the last button and the close button.
			// This is causing the screen to scroll in an unexpected way.
			closeButton.scrollIntoView = () => {};
			// Move focus ourselves to prevent undesired scrolling behavior.
			const lastLink = popoverPanel.querySelector(
				'.readinglists-bookmark-cta__actions a:last-child'
			);
			popoverPanel.addEventListener( 'keydown', ( event ) => {
				if ( event.key !== 'Tab' ) {
					return;
				}
				const active = document.activeElement;
				if ( !event.shiftKey && active === lastLink ) {
					event.preventDefault();
					closeButton.focus( { preventScroll: true } );
					return;
				}
				if ( event.shiftKey && active === closeButton && lastLink ) {
					event.preventDefault();
					lastLink.focus( { preventScroll: true } );
				}
			} );
		} );

		function onUpdateOpen( newOpenState ) {
			if ( !newOpenState ) {
				props.onClose();
				// Focus is lost after the popover is closed. Move focus back to the anchor.
				// #ca-bookmark is the list item. The link inside it is what can take focus.
				const anchor = props.anchor.querySelector( 'a' ) || props.anchor;
				anchor.focus( { preventScroll: true } );
			}
		}

		return {
			popover,
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
