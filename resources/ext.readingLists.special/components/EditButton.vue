<template>
	<cdx-toggle-button
		:model-value="editing"
		:aria-label="msgEditButton"
		quiet
		@update:model-value="onToggle">
		<cdx-icon :icon="cdxIconEdit"></cdx-icon>
	</cdx-toggle-button>
</template>

<script>
const { CdxIcon, CdxToggleButton } = require( '../../../codex.js' );
const { cdxIconEdit } = require( '../../../icons.json' );

// @vue/component
module.exports = exports = {
	components: { CdxIcon, CdxToggleButton },
	props: {
		editing: {
			type: Boolean,
			default: false
		}
	},
	emits: [ 'changed' ],
	setup() {
		return {
			msgEditButton: mw.msg( 'readinglists-edit-button' ),
			cdxIconEdit
		};
	},
	methods: {
		onToggle( value ) {
			this.$emit( 'changed', value );
		}
	}
};
</script>
