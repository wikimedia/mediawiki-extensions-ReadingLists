const { createRouter, createWebHistory } = require( 'vue-router' );
const Entries = require( './pages/Entries.vue' );
const base = require( './base.js' );

const { ReadingListsCustomLists } = require( './config.json' );

const routes = [
	// A specific list: /{user}/{listId}
	{
		path: '/:user/:listId(\\d+)',
		name: 'list',
		component: Entries,
		props: ( route ) => ( {
			listId: Number( route.params.listId ),
			isCustomListsEnabled: ReadingListsCustomLists
		} )
	},
	// The all-items view: /{user}
	{
		path: '/:user',
		name: 'all',
		component: Entries,
		props: { isCustomListsEnabled: ReadingListsCustomLists }
	},
	// Anything else (e.g. the bare page) also falls back to the all-items view.
	{
		path: '/:pathMatch(.*)*',
		name: 'fallback',
		component: Entries,
		props: { isCustomListsEnabled: ReadingListsCustomLists }
	}
];

module.exports = createRouter( {
	history: createWebHistory( base ),
	routes,
	// Land at the top of the newly rendered view, like a real navigation.
	scrollBehavior: () => ( { top: 0 } )
} );
