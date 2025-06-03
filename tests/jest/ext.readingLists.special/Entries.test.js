const { mount } = require( '@vue/test-utils' );
const Entries = require( '../../../resources/ext.readingLists.special/pages/Entries.vue' );
const api = require( '../../../resources/ext.readingLists.api/index.js' );

const LIST = require( '../fixtures/list.json' );
const ENTRIES = require( '../fixtures/entries.json' );
const PAGES = require( '../fixtures/pages.json' );

describe( 'Entries', () => {
	test( 'renders properly', () => {
		api.stubApi( {
			get: jest.fn( ( { action, meta, rllist, list, rlelists, prop } ) => {
				if ( action === 'query' ) {
					if ( meta === 'readinglists' && rllist === 12345 ) {
						return LIST;
					} else if ( list === 'readinglistentries' && rlelists === 12345 ) {
						return ENTRIES;
					} else if ( prop !== undefined ) {
						return PAGES;
					}
				}
			} )
		} );

		mw.util = { getUrl: jest.fn( ( path ) => `/wiki/${ path }` ) };

		const wrapper = mount( Entries, { props: { listId: 12345 } } );
		expect( wrapper.element ).toMatchSnapshot();
	} );
} );
