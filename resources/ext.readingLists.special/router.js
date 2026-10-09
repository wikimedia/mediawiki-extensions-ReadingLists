const { createRouter, createWebHistory } = require( 'vue-router' );
const Entries = require( './pages/Entries.vue' );
const base = require( './base.js' );

const { ReadingListsCustomLists } = require( './config.json' );
const isCustomListsEnabled = ReadingListsCustomLists;

const routes = [
	// A specific list: /{user}/{listId} or /{user}/{listId}/{title}
	{
		path: '/:user/:listId(\\d+)/:title?',
		name: 'list',
		component: Entries,
		props: ( route ) => ( {
			listId: Number( route.params.listId ),
			isCustomListsEnabled
		} )
	},
	// The all-items view: /{user}
	{
		path: '/:user',
		name: 'all',
		component: Entries,
		props: { isCustomListsEnabled }
	},
	// Anything else (e.g. the bare page) also falls back to the all-items view.
	{
		path: '/:pathMatch(.*)*',
		name: 'fallback',
		component: Entries,
		props: { isCustomListsEnabled }
	}
];

const router = createRouter( {
	history: createWebHistory( base ),
	routes,
	// Land at the top of the newly rendered view, like a real navigation.
	scrollBehavior: () => ( { top: 0 } )
} );

const titleElement = document.querySelector( '.reading-lists-title-text' );

let loaded;
router.beforeEach( ( to ) => {
	if ( titleElement && loaded ) {
		const title = to.params.title ? mw.msg(
			'readinglists-special-custom-list-title',
			to.params.title.replace( /_/g, ' ' )
		) : mw.msg( 'readinglists-special-subpage-title' );

		titleElement.textContent = title;
		document.title = mw.msg( 'pagetitle', title );
	}
	loaded = true;
} );

module.exports = router;
