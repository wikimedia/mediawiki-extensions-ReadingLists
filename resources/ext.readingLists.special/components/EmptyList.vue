<template>
	<div class="reading-lists-empty">
		<h2 class="reading-lists-empty-title">
			{{ msgEmptyTitle }}
		</h2>

		<p class="reading-lists-empty-cta">
			{{ msgEmptyCta }}
		</p>

		<a :href="randomUrl">
			<cdx-button action="progressive" weight="primary">
				<cdx-icon :icon="cdxIconDie"></cdx-icon>
				{{ msgEmptyRandom }}
			</cdx-button>
		</a>
	</div>
</template>

<script>
const { CdxButton, CdxIcon } = require( '../../../codex.js' );
const { cdxIconDie } = require( '../../../icons.json' );

// @vue/component
module.exports = exports = {
	components: { CdxButton, CdxIcon },
	setup() {
		return {
			randomUrl: mw.util.getUrl( 'Special:Random' ),
			msgEmptyTitle: mw.msg( 'readinglists-empty-title' ),
			msgEmptyCta: mw.msg( 'readinglists-empty-cta' ),
			msgEmptyRandom: mw.msg( 'readinglists-empty-random' ),
			cdxIconDie
		};
	}
};
</script>
