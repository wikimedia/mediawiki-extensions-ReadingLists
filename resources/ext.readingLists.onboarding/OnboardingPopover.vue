<template>
	<cdx-popover
		v-model:open="isOpen"
		:anchor="bookmarkElement"
		placement="bottom-end"
		class="readinglists-onboarding-popover"
		role="dialog"
		aria-labelledby="readinglists-onboarding-title"
		aria-describedby="readinglists-onboarding-text"
		@update:open="handleOpenChange"
	>
		<template #header>
			<div
				class="readinglists-onboarding-banner"
				:style="{ '--banner-image': `url(${bannerImagePath})` }"
			>
				<cdx-button
					class="readinglists-onboarding-close-button"
					weight="quiet"
					type="button"
					:aria-label="closeButtonLabel"
					@click="handleClose"
				>
					<cdx-icon :icon="cdxIconClose"></cdx-icon>
				</cdx-button>
			</div>
		</template>
		<div class="readinglists-onboarding-content">
			<h4 id="readinglists-onboarding-title" class="readinglists-onboarding-title">
				{{ titleText }}
			</h4>
			<p id="readinglists-onboarding-text" class="readinglists-onboarding-text">
				{{ bodyText }}
			</p>
		</div>
	</cdx-popover>
</template>

<script>
const { ref, computed } = require( 'vue' );
const { CdxPopover, CdxButton, CdxIcon } = require( '../../codex.js' );
const { cdxIconClose } = require( '../../icons.json' );

// @vue/component
module.exports = exports = {
	components: {
		CdxPopover,
		CdxButton,
		CdxIcon
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
		bannerImagePath: {
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

		const titleText = computed( () => mw.msg( props.titleMsgKey ) );
		const bodyText = computed( () => mw.msg( props.bodyMsgKey ) );

		const closeButtonLabel = mw.msg( 'readinglists-onboarding-close-button' );

		const handleOpenChange = ( newValue ) => {
			if ( !newValue ) {
				props.onDismiss();
			}
		};

		const handleClose = () => {
			isOpen.value = false;
			props.onDismiss();
		};

		return {
			isOpen,
			titleText,
			bodyText,
			closeButtonLabel,
			cdxIconClose,
			handleOpenChange,
			handleClose
		};
	}
};
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

.cdx-popover.readinglists-onboarding-popover {
	padding: 0;
	min-width: @size-1600;
	max-width: @size-2400;

	.cdx-popover__header {
		margin-bottom: 0;
	}

	.cdx-popover__arrow {
		background-color: @background-color-progressive-subtle--hover;
	}

	.cdx-popover__body {
		padding: 0;
	}
}

// Use color.blue100 for the background
.readinglists-onboarding-banner {
	position: relative;
	display: flex;
	justify-content: center;
	background-color: @background-color-progressive-subtle--hover;
	padding: @spacing-125 0;
	width: @size-full;

	&::before {
		content: var( --banner-image );
		display: block;
	}
}

.readinglists-onboarding-close-button {
	position: absolute;
	top: @spacing-75;
	right: @spacing-75;
}

.readinglists-onboarding-content {
	padding: @spacing-100;
}

.readinglists-onboarding-title {
	margin: 0 0 @spacing-50 0;
	font-size: @font-size-medium;
	font-weight: @font-weight-bold;
	line-height: @line-height-small;
}

.readinglists-onboarding-text {
	margin: 0;
	font-size: @font-size-medium;
	line-height: @line-height-medium;
	color: @color-subtle;
}
</style>
