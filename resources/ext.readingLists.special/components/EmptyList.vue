<template>
	<div class="reading-lists-empty">
		<span
			ref="imageRef"
			class="reading-lists-empty__image"
		></span>

		<h2 class="reading-lists-empty__title">
			{{ heading }}
		</h2>

		<p v-if="callToAction" class="reading-lists-empty__description">
			{{ callToAction }}
		</p>

		<slot></slot>
	</div>
</template>

<script>
const { ref, onMounted } = require( 'vue' );

// @vue/component
module.exports = exports = {
	props: {
		imageUrl: {
			type: String,
			required: true
		},
		heading: {
			type: String,
			required: true
		},
		callToAction: {
			type: String,
			default: ''
		}
	},
	setup( props ) {
		const imageRef = ref();

		onMounted( () => {
			if ( imageRef.value ) {
				imageRef.value.style.content = `url( ${ props.imageUrl } )`;
			}
		} );

		return {
			imageRef
		};
	}
};
</script>

<style lang="less">
	@import 'mediawiki.skin.variables.less';

	.reading-lists-empty {
		max-width: @size-2400;
		margin: @spacing-200 auto 0;
		text-align: center;

		&__image {
			display: block;
			margin: 0 auto;
		}

		// `<h2>` element, for semantic structure, but styled differently from Vector.
		// Overspecified to override Vector's `.mw-body-content h2` styles for setting
		// sans-serif font.
		& &__title {
			margin: 0;
			padding: 0;
			border: 0;
			font-family: @font-family-sans;
			font-size: @font-size-medium;
			font-weight: @font-weight-bold;
		}

		&__description {
			margin-top: @spacing-100;
		}
	}
</style>
