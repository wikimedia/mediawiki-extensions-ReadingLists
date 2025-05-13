<template>
	<div class="readinglist-list">
		<div v-if="cardsLocal.length" class="readinglist-list__container">
			<cdx-card
				v-for="( card ) in cardsLocal"
				:key="card.id"
				:url="card.url"
				:force-thumbnail="true"
				:thumbnail="card.thumbnail"
				@click="( event ) => clickCard( event, card )"
			>
				<template #title>
					{{ card.title }}

					<cdx-button
						v-if="listId > 0 && card.project"
						action="destructive"
						@click="( event ) => deleteEntry( event, card )">
						Delete
					</cdx-button>
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
const { defineComponent } = require( 'vue' );
const { CdxCard, CdxButton } = require( '@wikimedia/codex' );
let api;

// @vue/component
module.exports = exports = defineComponent( {
	name: 'ReadingList',
	components: {
		CdxCard,
		CdxButton
	},
	props: {
		emptyMessage: {
			type: String,
			required: false,
			default: undefined
		},
		listId: {
			type: Number,
			required: false,
			default: undefined
		},
		listName: {
			type: String,
			required: false,
			default: undefined
		},
		cards: {
			type: Array,
			required: true
		}
	},
	emits: [ 'click-card' ],
	data() {
		return {
			cardsLocal: this.cards
		};
	},
	methods: {
		clickCard( event, card ) {
			this.$emit( 'click-card', event, card );
		},
		deleteEntry( event, card ) {
			event.preventDefault();

			mw.loader.using( [ 'mediawiki.api', 'mediawiki.user', 'mediawiki.notification' ], async () => {
				// eslint-disable-next-line security/detect-possible-timing-attacks
				if ( api === undefined ) {
					api = new mw.Api();
				}

				await api.postWithToken( 'csrf', {
					action: 'readinglists',
					command: 'deleteentry',
					entry: card.id
				} );

				this.cardsLocal.splice( this.cardsLocal.indexOf( card ), 1 );

				const msg = mw.message(
					'readinglists-browser-remove-entry-success',
					card.url,
					card.title,
					window.location.origin + window.location.pathname,
					this.listName
				).parseDom();

				// Hide the page link icon in the notification
				const link = msg[ 0 ];

				if ( link && link.classList ) {
					link.classList.remove( 'external' );
				}

				mw.notification.notify( msg, { tag: 'removed' } );
			} );
		}
	}
} );
</script>

<style lang="less">
.cdx-card__text {
	width: 100%;

	.cdx-button {
		position: absolute;
		top: 0;
		right: 0;
		margin: 12px;
	}
}

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
