<template>
	<cdx-card
		:url="entry.url"
		:thumbnail="entry.thumbnail ? { url: entry.thumbnail } : undefined"
		:force-thumbnail="false">
		<template #title>
			{{ entry.title }}
		</template>

		<template v-if="entry.description" #description>
			{{ entry.description }}
		</template>
	</cdx-card>
</template>

<script>
const { CdxCard } = require( '../../../codex.js' );

// @vue/component
module.exports = exports = {
	components: { CdxCard },
	props: {
		entry: {
			type: Object,
			default: () => ( {
				id: 1,
				title: 'Example',
				description: 'Lorem ipsum dolor sit amet'
			} )
		}
	}
};
</script>
