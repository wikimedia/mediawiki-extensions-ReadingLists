const { mount } = require( '@vue/test-utils' );
const Lists = require( '../../../resources/ext.readingLists.special/pages/Lists.vue' );
const api = require( '../../../resources/ext.readingLists.api/index.js' );

const LISTS = require( '../fixtures/lists.json' );

describe( 'Entries', () => {
	test( 'renders properly', () => {
		api.stubApi( {
			get: jest.fn( ( { action, meta } ) => {
				if ( action === 'query' && meta === 'readinglists' ) {
					return LISTS;
				}
			} )
		} );

		mw.user = { getName: jest.fn( () => 'Bob' ) };
		mw.util = { getUrl: jest.fn( ( path ) => `/wiki/${ path }` ) };

		const wrapper = mount( Lists );
		expect( wrapper.element ).toMatchSnapshot();
	} );
} );
