<template>
	<cdx-popover
		v-model:open="isOpen"
		:anchor="bookmarkElement"
		:title="titleText"
		:primary-action="{ label: okButtonText, actionType: 'progressive' }"
		placement="bottom-start"
		render-in-place
		class="readinglists-mobile-onboarding-popover"
		@primary="handleOk"
		@update:open="handleOpenChange"
	>
		<p>{{ bodyText }}</p>
	</cdx-popover>
</template>

<script>
const { ref, computed } = require( 'vue' );
const { CdxPopover } = require( '../../../codex.js' );

// @vue/component
module.exports = exports = {
	components: {
		CdxPopover
	},
	props: {
		bookmarkElement: {
			type: [ HTMLElement, Object ],
			required: true
		},
		titleMsgKey: {
			type: String,
			required: true
		},
		bodyMsgKey: {
			type: String,
			required: true
		},
		onDismiss: {
			type: Function,
			required: true
		}
	},
	setup( props ) {
		const isOpen = ref( true );

		/* eslint-disable mediawiki/msg-doc */
		const titleText = computed( () => mw.msg( props.titleMsgKey ) );
		const bodyText = computed( () => mw.msg( props.bodyMsgKey ) );
		const okButtonText = computed( () => mw.msg( 'readinglists-onboarding-ok-button' ) );
		/* eslint-enable mediawiki/msg-doc */

		const handleOpenChange = ( newValue ) => {
			if ( !newValue ) {
				props.onDismiss();
			}
		};

		const handleOk = () => {
			isOpen.value = false;
			props.onDismiss();
		};

		return {
			isOpen,
			titleText,
			bodyText,
			okButtonText,
			handleOpenChange,
			handleOk
		};
	}
};
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

.cdx-popover.readinglists-mobile-onboarding-popover {
	background-color: @background-color-progressive-subtle;

	.cdx-popover__arrow {
		background-color: @background-color-progressive-subtle;
	}
}
</style>
