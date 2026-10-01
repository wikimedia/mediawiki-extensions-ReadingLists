<template>
	<li class="reading-lists-item" :class="rootClasses">
		<cdx-card
			class="reading-lists-item__card"
			:url="entry.url"
			:thumbnail="entry.thumbnail ? { url: entry.thumbnail } : undefined"
			thumbnail-size="large"
			:thumbnail-position="isListView ? 'inline-start' : 'block-start'"
			:force-thumbnail="isListView"
			:separation="isListView ? 'divider' : 'outline'"
		>
			<template #title>
				{{ entry.title }}
			</template>
			<template v-if="entry.redirectTitle || entry.description" #description>
				<template v-if="entry.redirectTitle">
					<cdx-icon :icon="cdxIconInfo"></cdx-icon>
					<span v-i18n-html:readinglists-redirect-to="[ entry.redirectTitle ]"></span>
				</template>
				<template v-else>
					{{ entry.description }}
				</template>
			</template>
		</cdx-card>
	</li>
</template>

<script>
const { computed } = require( 'vue' );
const { cdxIconInfo } = require( '../../../icons.json' );
const { CdxCard, CdxIcon } = require( '../../../codex.js' );

// @vue/component
module.exports = exports = {
	components: { CdxCard, CdxIcon },
	props: {
		entry: {
			type: Object,
			// FIXME: This prop should be required instead of having a default.
			default: () => ( {
				id: 1,
				title: 'Example',
				description: 'Lorem ipsum dolor sit amet'
			} )
		},
		/**
		 * Whether to display the cards as a list with simple dividers (as opposed to a grid).
		 */
		isListView: {
			type: Boolean,
			default: false
		}
	},
	setup( props ) {
		const rootClasses = computed( () => ( {
			'reading-lists-item--has-thumbnail': !!props.entry.thumbnail
		} ) );

		return {
			rootClasses,
			cdxIconInfo
		};
	}
};
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

.reading-lists-item {
	// This element is a grid item. We need to make the internal card full-height.
	display: flex;

	// Minerva sets a bottom margin on every content li element except the last.
	.content li&,
	& {
		margin: 0;
	}

	.reading-lists-item__card {
		// Make the card full-width too.
		width: 100%;
	}

	.cdx-card__thumbnail.cdx-thumbnail {
		.cdx-thumbnail__image {
			aspect-ratio: 1;
			// Center vertically and top align thumbnail image.
			background-position: center 0;
		}
	}

	// When there's a thumbnail, clamp title and description to 2 lines.
	&--has-thumbnail {
		.cdx-card__text__title,
		.cdx-card__text__description {
			// Contradictory to its name, non standards conforming `-webkit-box` value is supported
			// across all major browsers.
			// Must be used in combination with `-webkit-box-orient` to make `-webkit-line-clamp`
			// below work.
			display: -webkit-box;
			-webkit-box-orient: vertical;
			// Contain text to a given amount of lines when used in combination with
			// `display: -webkit-box` and ` `-webkit-box-orient`. It will end with ellipsis when
			// `text-overflow: ellipsis` is included.
			-webkit-line-clamp: 2;
			text-overflow: ellipsis;
			overflow: hidden;
		}
	}

	.cdx-card__text__supporting-text {
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	// Simulate divider separation styles from Codex. We have to copy this style because the one in
	// Codex expects `.cdx-card` elements to be direct siblings, while we wrap ours in `<li>`s.
	& + & > .cdx-card--separation-divider {
		border-top: @border-base;
	}
}
</style>
