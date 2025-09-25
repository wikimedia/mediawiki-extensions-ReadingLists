<template>
	<cdx-menu-button
		v-model:selected="selected"
		:disabled="disabled"
		:menu-items="menuItems"
		:aria-label="msgDisplayMenu"
		@update:selected="onSelect">
		<cdx-icon :icon="cdxIconSortVertical"></cdx-icon>
	</cdx-menu-button>
</template>

<script>
const { ref } = require( 'vue' );
const { CdxIcon, CdxMenuButton } = require( '../../../codex.js' );
const {
	cdxIconArrowDown,
	cdxIconArrowUp,
	cdxIconHistory,
	cdxIconLargerText,
	cdxIconSortVertical
} = require( '../../../icons.json' );

// @vue/component
module.exports = exports = {
	components: { CdxIcon, CdxMenuButton },
	props: {
		default: {
			type: Array,
			default: () => [ 's:name', 'd:ascending' ]
		},
		disabled: {
			type: Boolean,
			default: false
		},
		imported: {
			type: Boolean,
			default: false
		}
	},
	emits: [ 'ready', 'changed' ],
	setup( props ) {
		/** @type {string[]} */
		const selected = props.default;

		return {
			selected: ref( selected ),
			prev: props.default,
			menuItems: [
				{
					label: mw.msg( 'readinglists-display-sort' ),
					items: [
						{
							value: 's:name',
							label: mw.msg( 'readinglists-display-sort-name' ),
							icon: cdxIconLargerText
						},
						{
							value: 's:updated',
							label: mw.msg( 'readinglists-display-sort-updated' ),
							icon: cdxIconHistory,
							disabled: props.imported
						}
					]
				},
				{
					label: mw.msg( 'readinglists-display-direction' ),
					items: [
						{
							value: 'd:ascending',
							label: mw.msg( 'readinglists-display-direction-ascending' ),
							icon: cdxIconArrowUp
						},
						{
							value: 'd:descending',
							label: mw.msg( 'readinglists-display-direction-descending' ),
							icon: cdxIconArrowDown
						}
					]
				}
			],
			msgDisplayMenu: mw.msg( 'readinglists-display-menu' ),
			cdxIconSortVertical
		};
	},
	methods: {
		onSelect( values ) {
			const prev = this.prev;
			// Derive prefix placeholders from `prev` so length isn't hardcoded.
			const next = prev.map( ( v ) => v.slice( 0, 2 ) );

			for ( let i = 0; i < 2; i++ ) {
				const prefix = next[ i ];
				let value = null;

				for ( const value2 of values ) {
					if ( value2.startsWith( prefix ) ) {
						value = value2;
					}
				}

				next[ i ] = value === null ? prev[ i ] : value;
			}

			this.selected = next;

			if ( prev.toString() !== next.toString() ) {
				this.prev = next;
				this.$emit( 'changed', next );
			}
		}
	},
	mounted() {
		this.$emit( 'ready', this.default );
	}
};
</script>
