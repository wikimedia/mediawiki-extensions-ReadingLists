// Minimal vue-router stub for jest. At runtime the real module is provided by
// core via require( 'vue-router' ); tests only need the surface the components
// touch (RouterLink/RouterView rendering and the factory functions).

const RouterLink = {
	name: 'RouterLink',
	props: {
		to: {
			type: [ String, Object ],
			required: true
		}
	},
	template: '<a><slot></slot></a>'
};

const RouterView = {
	name: 'RouterView',
	template: '<div></div>'
};

module.exports = {
	RouterLink,
	RouterView,
	createRouter: () => ( {
		install() {},
		isReady: () => Promise.resolve(),
		push() {}
	} ),
	createWebHistory: () => ( {} )
};
