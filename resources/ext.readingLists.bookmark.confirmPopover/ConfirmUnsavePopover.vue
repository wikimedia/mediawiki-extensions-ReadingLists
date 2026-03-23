<template>
	<cdx-popover
		v-model:open="isOpen"
		:anchor="anchorElement"
		:title="title"
		:primary-action="primaryAction"
		:default-action="defaultAction"
		:placement="placement"
		class="readinglists-confirm-unsave-popover"
		@primary="onPrimary"
		@default="onDefault"
		@update:open="onOpenUpdate"
	>
		<p>{{ body }}</p>
	</cdx-popover>
</template>

<script>
const { computed, ref } = require( 'vue' );
const { CdxPopover } = require( '../../codex.js' );

// @vue/component
module.exports = exports = {
	name: 'ConfirmUnsavePopover',
	components: {
		CdxPopover
	},
	props: {
		anchorElement: {
			type: [ HTMLElement, Object ],
			required: true
		},
		isMinerva: {
			type: Boolean,
			required: true
		},
		onConfirm: {
			type: Function,
			required: true
		},
		onCancel: {
			type: Function,
			required: true
		}
	},
	setup( props ) {
		const isOpen = ref( true );
		const title = computed( () => mw.msg( 'readinglists-unsave-from-custom-list-title' ) );
		const body = computed( () => mw.msg( 'readinglists-unsave-from-custom-list-body' ) );
		const placement = computed( () => props.isMinerva ? 'bottom-start' : 'bottom-end' );
		const primaryAction = computed( () => ( {
			label: mw.msg( 'readinglists-unsave-from-custom-list-confirm' ),
			actionType: 'destructive'
		} ) );
		const defaultAction = computed( () => ( {
			label: mw.msg( 'readinglists-unsave-from-custom-list-cancel' )
		} ) );

		function onPrimary() {
			props.onConfirm();
		}

		function onDefault() {
			props.onCancel();
		}

		function onOpenUpdate( value ) {
			isOpen.value = value;

			if ( value ) {
				return;
			}

			props.onCancel();
		}

		return {
			body,
			defaultAction,
			isOpen,
			onDefault,
			onOpenUpdate,
			onPrimary,
			placement,
			primaryAction,
			title
		};
	}
};
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

.cdx-popover.readinglists-confirm-unsave-popover {
	width: @size-2400;
	max-width: 100vw;
}
</style>
