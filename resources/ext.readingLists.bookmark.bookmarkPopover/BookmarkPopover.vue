<template>
	<config-popover
		v-model:open="isOpen"
		class="readinglists-bookmark-popover"
		@update:open="handleOpenChange"
	>
		<!-- Custom header so we can render HTML in the title. -->
		<template #header>
			<cdx-icon
				v-if="popoverIcon"
				class="cdx-popover__header__icon"
				:icon="popoverIcon"
			></cdx-icon>
			<!-- eslint-disable vue/no-v-html -->
			<div
				v-if="!isCurrentlySaved"
				class="cdx-popover__header__title"
				v-html="saveTitle"
			></div>
			<!-- eslint-enable vue/no-v-html -->
			<div v-else class="cdx-popover__header__title">
				{{ unsaveTitle }}
			</div>
			<div class="cdx-popover__header__button-wrapper">
				<cdx-button
					class="cdx-popover__header__close-button"
					weight="quiet"
					type="button"
					:aria-label="closeButtonLabel"
					@click="handleOpenChange( false )"
				>
					<cdx-icon :icon="cdxIconClose"></cdx-icon>
				</cdx-button>
			</div>
		</template>
	</config-popover>
</template>

<script>
const { ref, computed } = require( 'vue' );
const { CdxButton, CdxIcon } = require( '../../codex.js' );
const { cdxIconSuccess, cdxIconClose } = require( '../../icons.json' );
const { ConfigPopover } = require( 'ext.readingLists.configPopover' );

/**
 * Popover for saving or un-saving an article to a reading list.
 */
// @vue/component
module.exports = exports = {
	name: 'BookmarkPopover',
	components: { CdxButton, CdxIcon, ConfigPopover },
	props: {
		/**
		 * Whether this article is currently saved to a reading list. The popover content changes
		 * depending on this.
		 */
		isCurrentlySaved: {
			type: Boolean,
			default: false
		},
		onDismiss: {
			type: Function,
			required: true
		}
	},
	setup( props ) {
		const isOpen = ref( true );

		// Different popover title depending on whether the user is saving or un-saving the article.
		const saveTitle = computed( () => mw.message(
			'readinglists-customlists-add-entry-success',
			mw.config.get( 'wgTitle' ),
			`Special:ReadingLists/${ mw.user.getName() }`,
			mw.msg( 'readinglists-default-title' )
		).parse() );
		const unsaveTitle = computed( () => mw.config.get( 'wgTitle' ) );

		const popoverIcon = computed( () => props.isCurrentlySaved ? null : cdxIconSuccess );
		const closeButtonLabel = computed( () => mw.msg( 'cdx-popover-close-button-label' ) );

		/**
		 * Handle popover dismissal.
		 *
		 * @param {boolean} newValue - Whether the popover is open.
		 */
		function handleOpenChange( newValue ) {
			if ( !newValue ) {
				// Run onDismiss with showNotification argument set to `false` all the time for now.
				// TODO (T438393): Once we enable saving to a custom list, in that case
				// showNotification should be `true`.
				props.onDismiss( false );
			}
		}

		return {
			isOpen,
			saveTitle,
			unsaveTitle,
			popoverIcon,
			closeButtonLabel,
			handleOpenChange,
			cdxIconClose
		};
	}
};
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

.readinglists-bookmark-popover {
	&.cdx-popover--bottom-sheet {
		// Set the width to the max-width of the CdxPopover's bottom sheet version.
		width: @size-5600;
		max-width: @size-full;
	}

	&.cdx-popover:not( .cdx-popover--bottom-sheet ) {
		// Match the width of mw.notifications in Vector 2022 (20em at 0.8em font size).
		width: @size-1600;
	}

	.cdx-popover__header__icon {
		color: @color-success;
	}
}
</style>
