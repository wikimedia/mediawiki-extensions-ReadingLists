<template>
	<!--
		Keyed on the route path so navigating between the all-items view and a
		specific list forces a remount, which re-runs the fetch in Entries'
		mounted() hook.
	-->
	<router-view :key="$route.path"></router-view>
</template>

<script>
const { RouterView } = require( 'vue-router' );

// @vue/component
module.exports = exports = {
	name: 'App',
	components: {
		RouterView
	}
};
</script>
