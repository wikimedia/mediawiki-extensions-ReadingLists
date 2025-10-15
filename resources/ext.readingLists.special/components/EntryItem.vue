<template>
	<cdx-card
		:url="entry.url"
		:class="{ 'cdx-card--is-link': entry.url || editing }"

		:thumbnail="entry.thumbnail ? { url: entry.thumbnail } : undefined"
		:force-thumbnail="false"
		@click="onClick">
		<template #title>
			<span v-if="editing" class="reading-lists-selectable">
				<cdx-checkbox
					v-if="editing"
					:model-value="selected"
					inline
					:aria-label="msgSelectArticle"
					@update:model-value="onToggle">
				</cdx-checkbox>
				{{ entry.title }}
			</span>
			<template v-else>{{ entry.title }}</template>
		</template>

		<template v-if="entry.description" #description>
			{{ entry.description }}
		</template>
	</cdx-card>
</template>

<script>
const { CdxCard, CdxCheckbox } = require( '../../../codex.js' );

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
			msgSelectArticle: mw.msg( 'readinglists-select-article' )
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
