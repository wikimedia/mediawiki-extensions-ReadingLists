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
	// DEPRECATED: A specific list: /{user}/{listId} or /{user}/{listId}/{title}
	{
		path: '/:user/:listId(\\d+)/:title?',
		name: 'list-deprecated',
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
	// DEPRECATED: The lists view: /${user}/collections
	{
		path: '/:user/collections',
		name: 'collections-deprecated',
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
	},
	// DEPRECATED: The all-items view: /{user}
	{
		path: '/:user',
		name: 'all-deprecated',
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

const titleElement = document.querySelector( '.reading-lists-title-text' );

// Skip the first beforeEach call, which fires on the initial page load where the
// PHP has already set the correct title.
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
		case 'list-deprecated':
			actionLoadPage( 'load_custom_list_page' );
			break;
		case 'all':
		case 'all-deprecated':
			actionLoadPage( 'load_all_items_page' );
			break;
		case 'collections':
		case 'collections-deprecated':
			actionLoadPage( 'load_collections_page' );
			break;
		default:
			actionLoadPage( 'load_reading_list_fallback' );
	}
} );

module.exports = router;
