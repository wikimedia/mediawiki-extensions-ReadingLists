<template>
	<cdx-popover
		v-if="usePopover"
		:open="open"
		use-bottom-sheet="always"
		:title="title"
		:primary-action="primaryAction"
		:default-action="defaultAction"
		@primary="$emit( 'primary', $event )"
		@default="$emit( 'default', $event )"
		@update:open="$emit( 'update:open', $event )"
	>
		<slot></slot>
	</cdx-popover>
	<cdx-dialog
		v-else
		:open="open"
		:title="title"
		:use-close-button="false"
		:render-in-place="true"
		:primary-action="primaryAction"
		:default-action="defaultAction"
		@primary="$emit( 'primary', $event )"
		@default="$emit( 'default', $event )"
		@update:open="$emit( 'update:open', $event )"
	>
		<slot></slot>
	</cdx-dialog>
</template>

<script>
const { CdxDialog, CdxPopover } = require( '../../codex.js' );
const { useIsMobileResolution } = require( './composables.js' );

/**
 * Renders a CdxPopover on mobile resolutions and a CdxDialog otherwise, sharing
 * the same title, open state, and default/footer slots between the two.
 */
// @vue/component
module.exports = exports = {
	name: 'DialogPopover',
	components: {
		CdxPopover,
		CdxDialog
	},
	props: {
		defaultAction: {
			type: Object,
			required: true
		},
		primaryAction: {
			type: Object,
			required: true
		},
		open: {
			type: Boolean,
			required: true
		},
		title: {
			type: String,
			required: true
		}
	},
	emits: [ 'primary', 'default', 'update:open' ],
	setup() {
		const usePopover = useIsMobileResolution();

		return {
			usePopover
		};
	}
};
</script>
