<template>
	<cdx-card
		:url="entry.url"
		:thumbnail="entry.thumbnail ? { url: entry.thumbnail } : undefined"
		:force-thumbnail="false">
		<template #title>
			{{ entry.title }}
		</template>

		<template v-if="entry.description" #description>
			{{ entry.description }}
		</template>
	</cdx-card>
</template>

<script>
const { CdxCard } = require( '../../../codex.js' );

// @vue/component
module.exports = exports = {
	components: { CdxCard },
	props: {
		entry: {
			type: Object,
			default: () => ( {
				id: 1,
				title: 'Example',
				description: 'Lorem ipsum dolor sit amet'
			} )
		}
	}
};
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

@min-width-card: 20rem; // equal to 320px.
@max-width-card: 28rem; // equal to 448px. Note: alternatively up to `1fr`.
@max-width-card--mobile: 34rem; // equal to 544px.

.cdx-card {
	flex-direction: row;
	// Allow thumbnail and text container to stretch to the same height.
	align-items: stretch;
	min-width: @min-width-card;
	max-width: @max-width-card--mobile;
	// Use same minimum height as thumbnail to avoid non-thumbnail lists to jump in height.
	min-height: @size-800;
	// Set 0 padding to flush thumbnails and instead use padding on text container.
	padding: 0;
	overflow: hidden;

	// Mobile only: Max it out at `34rem` (544px), specifically important on landscape mode.
	@media screen and ( max-width: @max-width-breakpoint-mobile ) {
		max-width: @max-width-card--mobile;
	}
}

// Specify higher equally to Codex component in component precedence.
.cdx-card__thumbnail.cdx-thumbnail {
	// Provide a subtle background color, in case there is any text box expanding glitch.
	background-color: @background-color-neutral-subtle;
	min-width: @size-800;
	width: @size-800;
	min-height: @size-800;
	margin-right: 0;

	// Apply necessary specificity to set thumbnail size.
	.cdx-thumbnail__image {
		// Center vertically and top align thumbnail image.
		background-position: center 0;
		width: @size-full;
		height: @size-full;
		aspect-ratio: 1;
		// Remove Codex default border on thumbnails. Rely on background color of thumbnail
		// container instead.
		border-width: 0;
	}
}

.cdx-card__text {
	// Let text container take all remaining space.
	flex: 1 1 auto;
	box-sizing: @box-sizing-base;
	height: @size-full;
	padding: @spacing-50 @spacing-75;
}

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

.cdx-card__text__title {
	font-family: @font-family-serif;
	font-size: @font-size-x-large;
	font-weight: @font-weight-normal;
	line-height: @line-height-x-large;
}

.cdx-card__text__description {
	color: @color-subtle;
	font-size: @font-size-small;
	line-height: @line-height-small;
}

.cdx-card__text__supporting-text {
	color: @color-subtle;
	font-size: @font-size-x-small;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
</style>
