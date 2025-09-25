<template>
	<cdx-card
		:url="entry.url"
		:thumbnail="{ url: entry.thumbnail }"
		:custom-placeholder-icon="!entry.url || entry.missing ? cdxIconAlert : undefined"
		:class="{ 'cdx-card--is-link': entry.url || editing }"
		@click="onClick">
		<template #title>
			<div class="reading-lists-selectable">
				<cdx-checkbox
					v-if="editing"
					:model-value="selected"
					inline
					:aria-label="msgSelectArticle"
					@update:model-value="onToggle">
				</cdx-checkbox>
				{{ entry.title }}
			</div>
		</template>

		<template v-if="entry.description" #description>
			{{ entry.description }}
		</template>
	</cdx-card>
</template>

<script>
const { CdxCard, CdxCheckbox } = require( '../../../codex.js' );
const { cdxIconAlert } = require( '../../../icons.json' );

// @vue/component
module.exports = exports = {
	components: { CdxCard, CdxCheckbox },
	props: {
		entry: {
			type: Object,
			default: () => ( {
				id: 1,
				title: 'Example',
				description: 'Lorem ipsum dolor sit amet'
			} )
		},
		selected: {
			type: Boolean,
			default: false
		},
		editing: {
			type: Boolean,
			default: false
		}
	},
	emits: [ 'selected' ],
	data() {
		return {
			msgSelectArticle: mw.msg( 'readinglists-select-article' ),
			cdxIconAlert
		};
	},
	methods: {
		onClick( event ) {
			if ( event.target.type !== 'checkbox' && this.editing ) {
				event.preventDefault();
				this.onToggle();
			}
		},
		onToggle() {
			this.$emit( 'selected', this.entry.id, !this.selected );
		}
	}
};
</script>
