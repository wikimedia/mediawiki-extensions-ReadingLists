const { createRouter, createWebHistory } = require( 'vue-router' );
const Entries = require( './pages/Entries.vue' );
const { actions } = require( 'ext.readingLists.common' );
const { collectionsBaseUrl } = require( 'ext.readingLists.api' );
const { ReadingListsCustomLists } = require( './config.json' );
const isCustomListsEnabled = ReadingListsCustomLists;
const { actionLoadPage } = actions;

const routes = [
	// A specific list: /{listId}/{title?}
	{
		path: '/:listId(\\d+)/:title?',
		name: 'list',
		component: Entries,
		props: ( route ) => ( {
			listId: Number( route.params.listId ),
			isCustomListsEnabled
		} )
	},
	// The collections view: /collections.
	{
		path: '/collections',
		name: 'collections',
		component: Entries,
		props: () => ( {
			isCollections: true,
			isCustomListsEnabled
		} )
	},
	// The all-items view and fallback (base URL or unrecognized path).
	{
		path: '/:pathMatch(.*)*',
		name: 'all',
		component: Entries,
		props: { isCustomListsEnabled }
	}
];

const base = mw.util.getUrl( collectionsBaseUrl );

const router = createRouter( {
	history: createWebHistory( base ),
	routes,
	// Land at the top of the newly rendered view, like a real navigation.
	scrollBehavior: () => ( { top: 0 } )
} );

router.afterEach( ( to ) => {
	// When visiting /wiki/Special:Saved, the path is '/', which gets added to the URL.
	// Remove this trailing slash.
	if ( to.path === '/' ) {
		window.history.replaceState(
			window.history.state,
			'',
			base + window.location.search + window.location.hash
		);
	}

	switch ( to.name ) {
		case 'list':
			actionLoadPage( 'load_custom_list_page' );
			break;
		case 'all':
			actionLoadPage( 'load_all_items_page' );
			break;
		case 'collections':
			actionLoadPage( 'load_collections_page' );
			break;
		default:
			actionLoadPage( 'load_reading_list_fallback' );
	}
} );

module.exports = router;
