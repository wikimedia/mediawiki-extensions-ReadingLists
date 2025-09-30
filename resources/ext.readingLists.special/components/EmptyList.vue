<template>
	<div class="reading-lists-empty">
		<h2 class="reading-lists-empty__title">
			{{ msgEmptyTitle }}
		</h2>

		<p class="reading-lists-empty__description">
			{{ msgEmptyDescription }}
		</p>
	</div>
</template>

<script>
// @vue/component
module.exports = exports = {
	setup() {
		return {
			msgEmptyTitle: mw.msg( 'readinglists-empty-title' ),
			msgEmptyDescription: mw.msg( 'readinglists-empty-cta' )
		};
	}
};
</script>
