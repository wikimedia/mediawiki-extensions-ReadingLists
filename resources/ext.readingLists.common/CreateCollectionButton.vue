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
const { actionCreateCollectionButtonClick } = require( './actions.js' );

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
			actionCreateCollectionButtonClick();
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
@import './button-link.less';

.readinglists-create-collection-button {
	.readinglists-button-link();
}
</style>
