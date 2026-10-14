<template>
	<div class="reading-lists-all-collections">
		<create-collection-button
			class="reading-lists-all-collections__create-button"
			@create-collection="onCreateButtonClick"
		></create-collection-button>

		<!-- role="list" needed for Safari support, refer to T435864. -->
		<ul
			v-if="!loadingInfo && collections.length !== 0"
			role="list"
			class="reading-lists-all-collections__list"
			:aria-label="ariaLabel">
			<li
				v-for="collection in collections"
				:key="collection.id"
				class="reading-lists-all-collections__list-item"
			>
				<router-link
					class="reading-lists-all-collections__list-item__link"
					:to="getUrlSection( collection.url )"
				>
					<span class="reading-lists-all-collections__list-item__title">
						{{ collection.title }}
					</span>
					<span class="reading-lists-all-collections__list-item__description">
						{{ collection.description }}
					</span>
				</router-link>
			</li>
		</ul>
	</div>
</template>

<script>
const { computed } = require( 'vue' );
const { RouterLink } = require( 'vue-router' );
const { collectionsBaseUrl, CreateCollectionButton } = require( 'ext.readingLists.common' );

/**
 * List of all collections on Special:ReadingLists.
 */
// @vue/component
module.exports = exports = {
	name: 'AllCollections',
	components: { RouterLink, CreateCollectionButton },
	props: {
		collections: {
			type: Array,
			required: true
		},
		loadingInfo: {
			type: Boolean,
			required: true
		}
	},
	emits: [
		'create-collection'
	],
	setup( _props, { emit } ) {
		const ariaLabel = computed( () => mw.msg( 'readinglists-customlists-collections' ) );

		function getUrlSection( url ) {
			return url.slice(
				mw.util.getUrl( collectionsBaseUrl ).length
			);
		}

		function onCreateButtonClick() {
			emit( 'create-collection' );
		}

		return {
			ariaLabel,
			getUrlSection,
			onCreateButtonClick
		};
	}
};
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

@min-width-card: @size-1200;

.reading-lists-all-collections {
	&__list,
	&__list-item {
		// Reset list styles from Minerva.
		&,
		.content & {
			list-style: none;
			padding: 0;
			margin: 0;
		}
	}

	&__create-button {
		min-height: @size-400;
	}

	&__create-button,
	&__list-item {
		border-top: @border-width-base @border-style-base @border-color-subtle;
	}

	&__list-item {
		&__link {
			display: flex;
			flex-direction: column;
			padding: @spacing-75 0;
			border-radius: @border-radius-sharp;

			&,
			&:visited {
				color: @color-base;
			}
		}

		/* stylelint-disable-next-line no-descending-specificity */
		&__title {
			font-size: @font-size-large;
		}

		/* stylelint-disable-next-line no-descending-specificity */
		&__description {
			font-size: @font-size-small;
		}
	}
}

</style>
