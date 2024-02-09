<template>
	<div class="readinglist-list">
		<div v-if="cards.length" class="readinglist-list__container">
			<cdx-card
				v-for="( card ) in cards"
				:key="card.id"
				:url="card.url"
				:force-thumbnail="true"
				:thumbnail="card.thumbnail"
				@click="( event ) => clickCard( event, card )"
			>
				<template #title>
					{{ card.title }}
				</template>
				<template #description>
					{{ card.description }}
				</template>
			</cdx-card>
		</div>
		<div v-else class="readinglist-list-empty">
			{{ emptyMessage }}
		</div>
	</div>
</template>

<script>
const { CdxCard } = require( '@wikimedia/codex' );

// @vue/component
module.exports = {
	name: 'ReadingList',
	components: {
		CdxCard
	},
	props: {
		emptyMessage: {
			type: String,
			required: true
		},
		cards: {
			type: Array,
			required: true
		}
	},
	emits: [ 'click-card' ],
	methods: {
		clickCard( ev, card ) {
			this.$emit( 'click-card', ev, card );
		}
	}
};
</script>

<style lang="less">
.readinglist-list__container {
	.cdx-card {
		margin-bottom: 1em;
	}
}

.readinglist-collection {
	// stylelint-disable-next-line declaration-property-unit-disallowed-list
	font-size: 16px;

	p {
		margin-top: 16px;
	}

	ol {
		margin-top: 16px;
		padding-left: 0;
		list-style: inside decimal;
	}
}

.readinglist-list-empty {
	font-style: italic;
}
</style>
