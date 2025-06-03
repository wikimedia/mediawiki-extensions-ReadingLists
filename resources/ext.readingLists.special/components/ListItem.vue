<template>
	<cdx-card
		:url="urlValue"
		:thumbnail="thumbnailValue"
		force-thumbnail>
		<template v-if="titleValue" #title>
			{{ titleValue }}
		</template>

		<template v-if="descriptionValue" #description>
			{{ descriptionValue }}
		</template>

		<template v-if="totalValue" #supporting-text>
			{{ totalValue }}
		</template>
	</cdx-card>
</template>

<script>
const { CdxCard } = require( '../../../codex.js' );

// @vue/component
module.exports = exports = {
	components: { CdxCard },
	props: {
		title: {
			type: String,
			default: ''
		},
		description: {
			type: String,
			default: ''
		},
		thumbnail: {
			type: String,
			default: ''
		},
		size: {
			type: Number,
			default: 0
		}
	},
	data() {
		const title = this.title.trim();
		const description = this.description.trim();
		const thumbnail = this.thumbnail.trim();

		return {
			urlValue: mw.util.getUrl( `Special:ReadingLists/${ mw.user.getName() }/${ this.$.vnode.key }` ),
			titleValue: title.length !== 0 ? title : null,
			descriptionValue: description.length !== 0 ? description : null,
			thumbnailValue: thumbnail.length !== 0 ? { url: thumbnail } : null,
			totalValue: mw.msg( 'readinglists-total-articles', this.size )
		};
	}
};
</script>
