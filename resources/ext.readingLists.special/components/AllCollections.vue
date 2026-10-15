<template>
	<div v-if="!loadingInfo && !loadingEntries" class="reading-lists-all-collections">
		<template v-if="collections.length !== 0">
			<create-collection-button
				class="reading-lists-all-collections__create-button"
				@create-collection="onCreateButtonClick"
			></create-collection-button>

			<!-- role="list" needed for Safari support, refer to T435864. -->
			<ul
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
		</template>
		<template v-else>
			<empty-list
				class="reading-lists-all-collections__empty-state"
				:heading="msgEmptyListHeading"
				:image-url="emptyStateImageUrl"
			>
				<cdx-button
					class="reading-lists-all-collections__empty-state__create-button"
					weight="quiet"
					action="progressive"
					@click="onCreateButtonClick"
				>
					{{ createButtonLabel }}
				</cdx-button>
			</empty-list>
		</template>
	</div>
</template>

<script>
const { computed } = require( 'vue' );
const { RouterLink } = require( 'vue-router' );
const { CdxButton } = require( '../../../codex.js' );
const { collectionsBaseUrl, CreateCollectionButton } = require( 'ext.readingLists.common' );
const EmptyList = require( './EmptyList.vue' );

/**
 * List of all collections on Special:ReadingLists.
 */
// @vue/component
module.exports = exports = {
	name: 'AllCollections',
	components: {
		RouterLink,
		CdxButton,
		CreateCollectionButton,
		EmptyList
	},
	props: {
		collections: {
			type: Array,
			required: true
		},
		loadingInfo: {
			type: Boolean,
			required: true
		},
		loadingEntries: {
			type: Boolean,
			required: true
		}
	},
	emits: [
		'create-collection'
	],
	setup( _props, { emit } ) {
		const ariaLabel = computed( () => mw.msg( 'readinglists-customlists-collections' ) );

		const msgEmptyListHeading = computed( () => mw.msg( 'readinglists-customlists-no-collections' ) );
		const emptyStateImageUrl = computed( () => mw.config.get( 'wgExtensionAssetsPath' ) +
			'/ReadingLists/resources/assets/all-collections-empty-state.svg' );
		const createButtonLabel = computed( () => mw.msg( 'readinglists-customlists-create-collection-short' ) );

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
			msgEmptyListHeading,
			emptyStateImageUrl,
			createButtonLabel,
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

	&__empty-state__create-button.cdx-button {
		margin-top: @spacing-200;
	}
}

</style>
