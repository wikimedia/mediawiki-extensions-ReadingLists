<template>
	<cdx-popover
		v-model:open="isOpen"
		:anchor="bookmarkElement"
		placement="bottom-start"
		class="readinglists-onboarding-popover"
		role="dialog"
		aria-labelledby="readinglists-onboarding-title"
		aria-describedby="readinglists-onboarding-text"
		@update:open="handleOpenChange"
	>
		<template #header>
			<div class="readinglists-onboarding-banner">
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
				{{ $i18n( 'readinglists-onboarding-title' ).text() }}
			</h4>
			<p id="readinglists-onboarding-text" class="readinglists-onboarding-text">
				{{ $i18n( 'readinglists-onboarding-text' ).text() }}
			</p>
		</div>
	</cdx-popover>
</template>

<script>
const { ref, defineComponent } = require( 'vue' );
const { CdxPopover, CdxButton, CdxIcon } = require( '../../../codex.js' );
const { cdxIconClose } = require( '../../../icons.json' );

module.exports = defineComponent( {
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
		onDismiss: {
			type: Function,
			required: true
		}
	},
	setup( props ) {
		const isOpen = ref( true );
		const closeButtonLabel = mw.msg( 'readinglists-onboarding-close-button' );

		const handleOpenChange = ( newValue ) => {
			if ( !newValue ) {
				props.onDismiss();
			}
		};

		const handleClose = () => {
			isOpen.value = false;
		};

		return {
			isOpen,
			closeButtonLabel,
			cdxIconClose,
			handleOpenChange,
			handleClose
		};
	}
} );
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

.readinglists-onboarding-popover {
	padding: 0;
	min-width: @size-2400;
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
	background-color: @background-color-progressive-subtle--hover;
	padding: @spacing-50;
	text-align: center;
	width: @size-full;

	&::before {
		display: block;
		content: url( ../../assets/onboarding-save.svg );
		margin: 0 auto;
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
