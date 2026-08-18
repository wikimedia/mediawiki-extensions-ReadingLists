<template>
	<div class="reading-lists-empty">
		<h2 class="reading-lists-empty__title">
			{{ msgEmptyTitle }}
		</h2>

		<p class="reading-lists-empty__description">
			{{ msgEmptyDescription }}
		</p>
	</div>
</template>

<script>
// @vue/component
module.exports = exports = {
	props: {
		// Whether the empty list is a custom list (collection) rather than the
		// default "saved articles" list or the aggregate all-items view.
		isCustomList: {
			type: Boolean,
			default: false
		}
	},
	computed: {
		msgEmptyTitle() {
			return this.isCustomList ?
				mw.msg( 'readinglists-empty-collection-title' ) :
				mw.msg( 'readinglists-empty-title' );
		},
		msgEmptyDescription() {
			return this.isCustomList ?
				mw.msg( 'readinglists-empty-collection-cta' ) :
				mw.msg( 'readinglists-empty-cta' );
		}
	}
};
</script>

<style lang="less">
	@import 'mediawiki.skin.variables.less';

	.reading-lists-empty {
		max-width: @size-2400;
		margin-left: auto;
		margin-right: auto;
		text-align: center;

		// `<h2>` element, for semantic structure, but styled differently from Vector.
		// Overspecified to override Vector's `.mw-body-content h2` styles for setting
		// sans-serif font.
		& &__title {
			margin-top: @spacing-200;
			margin-bottom: 0;
			padding: 0;
			border: 0;
			font-family: @font-family-sans;
			font-size: @font-size-medium;
			font-weight: @font-weight-bold;

			&::before {
				display: block;
				content: url( ../../assets/collection-empty-state.svg );
				margin: 0 auto;
			}
		}

		&__description {
			margin-top: @spacing-100;
		}
	}
</style>
