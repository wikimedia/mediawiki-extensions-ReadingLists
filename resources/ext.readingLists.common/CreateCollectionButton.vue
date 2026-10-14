<template>
	<button
		class="readinglists-create-collection-button"
		@click="onCreateButtonClick"
	>
		{{ createButtonLabel }}
	</button>
</template>

<script>
const { computed } = require( 'vue' );

/**
 * Link-style button for creating a collection.
 */
// @vue/component
module.exports = exports = {
	name: 'CreateCollectionButton',
	emits: [
		/**
		 * When the user clicks the button.
		 */
		'create-collection'
	],
	setup( props, { emit } ) {
		const createButtonLabel = computed( () => mw.msg( 'readinglists-customlists-create-collection-short' ) );

		function onCreateButtonClick() {
			emit( 'create-collection' );
		}

		return {
			createButtonLabel,
			onCreateButtonClick
		};
	}
};
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

.readinglists-create-collection-button {
	.cdx-mixin-link();
	width: @size-full;

	// Normalize button styles.
	background: 0;
	margin: 0;
	padding: 0;
	border: 0;
	font-family: inherit;
	font-size: @font-size-medium;
	font-weight: @font-weight-normal;
	text-align: left;
	text-transform: none;

	// The outline from the link mixin looks awful, use text decoration instead.
	&:focus-visible {
		outline: @outline-base--focus;
		text-decoration: @text-decoration-underline;
	}
}
</style>
