const { createRouter, createWebHistory } = require( 'vue-router' );
const Entries = require( './pages/Entries.vue' );
const { collectionsBaseUrl, actions } = require( 'ext.readingLists.common' );
const { ReadingListsCustomLists } = require( './config.json' );
const isCustomListsEnabled = ReadingListsCustomLists;
const { actionLoadPage } = actions;

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
	// The lists view: /${user}/collections
	{
		path: '/:user/collections',
		name: 'collections',
		component: Entries,
		props: () => ( {
			isCollections: true,
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
	history: createWebHistory( mw.util.getUrl( collectionsBaseUrl ) ),
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

router.afterEach( ( to ) => {
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
