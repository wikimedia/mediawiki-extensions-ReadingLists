<template>
	<cdx-popover
		ref="popover"
		v-model:open="wrappedOpen"
		class="readinglists-config-popover"
		:use-bottom-sheet="useBottomSheet"
		:hide-arrow="true"
		:do-not-position="true"
		v-bind="attrs"
		@update:open="onUpdateOpen"
	>
		<template v-if="$slots.header" #header>
			<slot name="header"></slot>
		</template>
		<slot></slot>
		<template v-if="$slots.footer" #footer>
			<slot name="footer"></slot>
		</template>
	</cdx-popover>
</template>

<script>
const { toRef, ref, computed, watch, onMounted, onUnmounted } = require( 'vue' );
const { CdxPopover, useModelWrapper, useResizeObserver } = require( '../../codex.js' );
const MAX_WIDTH_MOBILE = 639;

/**
 * Opinionated implementation of CdxPopover for a (potentially multi-step) configuration form.
 *
 * By default, displays as a popover on larger viewports (above `max-width-breakpoint-mobile`) and
 * a bottom sheet below that breakpoint. In MinervaNeue, always displays as a bottom sheet.
 *
 * The popover version displays in the top right corner of the page, ideal on article pages to
 * avoid covering up content. mw.notifications are pushed down below the popover.
 *
 * You can bind CdxPopover props to the component or use CdxPopover slots and they'll get passed to
 * the CdxPopover.
 *
 * Too many instances of this component could cause collisions, so carefully consider whether this
 * is the right component for your use case.
 *
 * DO USE when ALL of the following are true:
 * - For configuration forms that are too large to display attached to their triggering element
 * - On article pages
 * - When the workflow isn't relatively important enough to disrupt user flow with a Dialog
 *
 * DO NOT USE:
 * - For notifications (use mw.notify())
 * - For small, informative popovers (use a CdxPopover anchored to the triggering element)
 * - On non-article pages (use a CdxPopover anchored to the triggering element or a CdxDialog)
 * - When the workflow is important enough to require the user's full attention (use a CdxDialog)
 */
// @vue/component
module.exports = exports = {
	components: {
		CdxPopover
	},
	props: {
		/**
		 * Whether the popover is visible.
		 * Should be provided via a v-model:open binding in the parent scope.
		 */
		// eslint-disable-next-line vue/no-unused-properties
		open: {
			type: Boolean,
			default: false
		}
	},
	emits: [ 'update:open' ],
	setup( props, { emit, attrs } ) {
		const wrappedOpen = useModelWrapper(
			toRef( props, 'open' ),
			emit,
			'update:open'
		);

		const skinName = computed( () => mw.config.get( 'skin' ) );
		const isMinerva = computed( () => skinName.value === 'minerva' );
		const useBottomSheet = computed( () => isMinerva.value ? 'always' : 'responsive' );

		const popover = ref(); // Ref for the CdxPopover component.
		const popoverEl = ref(); // Ref for the internal `.cdx-popover` element; gets set on mount.
		const notificationsElement = ref();
		const popoverDimensions = useResizeObserver( popoverEl );
		const currentPopoverHeight = computed( () => popoverDimensions.value.height || 0 );

		/**
		 * Set styles on the notifications area to account for this popover.
		 */
		function setNotificationsStyles() {
			// First look for and set up the notifications area element.
			if ( !notificationsElement.value ) {
				const el = document.querySelector( '#mw-notification-area' );
				if ( el ) {
					notificationsElement.value = el;
				}
			}
			// Then attempt to set the `top` value.
			if (
				notificationsElement.value &&
				currentPopoverHeight.value > 0 &&
				popoverEl.value &&
				popoverEl.value.classList.contains( 'cdx-popover--bottom-sheet' ) === false
			) {
				// Account for the `spacing-400` top value of this popover.
				notificationsElement.value.style.top = ( currentPopoverHeight.value + 64 ) + 'px';
				// Don't allow notifications area to move when scrolling.
				notificationsElement.value.style.position = 'fixed';
				// Override the transform added when the Vector 2022 sticky header is open.
				// FIXME: This is specific to Vector 2022. Before upstreaming to core, this should
				// be removed and this should be handled in Vector.
				notificationsElement.value.style.transform = 'translateX(-50%)';
			}
		}

		/**
		 * Restore notifications area styles to their defaults.
		 */
		function restoreNotificationsStyles() {
			if ( notificationsElement.value ) {
				notificationsElement.value.style.removeProperty( 'top' );
				notificationsElement.value.style.removeProperty( 'position' );
				notificationsElement.value.style.removeProperty( 'transform' );
			}
		}

		// Update the `top` value when the height of the popover changes.
		watch( currentPopoverHeight, () => {
			setNotificationsStyles();
		} );

		// Set up a MutationObserver to watch for the notifications area. This element gets added to
		// the page when the first notification is launched, so we need to apply the `top` value in
		// case the popover is open when that happens.
		const mutationObserver = new MutationObserver( () => {
			setNotificationsStyles();

			// The element will remain in the DOM so we can disconnect the observer.
			if ( notificationsElement.value ) {
				mutationObserver.disconnect();
			}
		} );
		// The parent element of the notifications area is a direct child of the body, so we don't
		// need to observe the whole subtree.
		mutationObserver.observe( document.body, { childList: true } );

		/**
		 * Handle window resize.
		 */
		function onResize() {
			if ( window.innerWidth > MAX_WIDTH_MOBILE ) {
				// Above the mobile breakpoint, maybe set a `top` style on the notifications area.
				setNotificationsStyles();
			} else {
				// Below it, remove that `top` style.
				restoreNotificationsStyles();
			}
		}

		/**
		 * Handle open change based on user interaction.
		 *
		 * @param {boolean} newValue Whether the popover is open.
		 */
		function onUpdateOpen( newValue ) {
			if ( !newValue && notificationsElement.value ) {
				restoreNotificationsStyles();
			}

			emit( 'update:open', newValue );
		}

		/**
		 * Handle open change coming from parent component.
		 */
		watch( wrappedOpen, ( newValue ) => {
			if ( !notificationsElement.value ) {
				return;
			}
			if ( newValue ) {
				// If open, set new top value on notifications area.
				setNotificationsStyles();
			} else {
				// If closed, remove top value on notifications area.
				restoreNotificationsStyles();
			}
		} );

		const debouncedOnResize = !isMinerva.value ? mw.util.debounce( onResize, 100 ) : null;

		onMounted( () => {
			popoverEl.value = popover.value.panel;
			// Handle case of the popover mounting when notifications are visible.
			setNotificationsStyles();

			if ( debouncedOnResize ) {
				window.addEventListener( 'resize', debouncedOnResize );
			}
		} );

		onUnmounted( () => {
			mutationObserver.disconnect();
			restoreNotificationsStyles();

			if ( debouncedOnResize ) {
				window.removeEventListener( 'resize', debouncedOnResize );
			}
		} );

		return {
			attrs,
			wrappedOpen,
			useBottomSheet,
			popover,
			onUpdateOpen
		};
	}
};
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

.readinglists-config-popover.cdx-popover:not( .cdx-popover--bottom-sheet ) {
	// Override styles applied by FloatingUI to position the popover.
	// FIXME: Once T438901 is done we can remove the `!important` keywords.
	/* stylelint-disable declaration-no-important */
	position: fixed !important;
	// Note that these positioning styles work well in Vector 2022; other skins will need skin-
	// specific styles in their respective repos.
	left: unset !important;
	// Match the spacing of notifications so the popover lines up with them nicely.
	right: 1em !important;
	top: @spacing-400 !important;
	transform: none !important;
	will-change: auto !important;
	/* stylelint-enable declaration-no-important */
}
</style>
