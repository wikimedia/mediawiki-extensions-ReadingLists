const { mount } = require( '@vue/test-utils' );
const api = require( '../../../resources/ext.readingLists.api/index.js' );

const LISTS = require( '../fixtures/lists.json' );

function setupListsApiStub() {
	return api.stubApi( {
		get: jest.fn( ( { action, meta } ) => {
			if ( action === 'query' && meta === 'readinglists' ) {
				return LISTS;
			}
		} )
	} );
}

describe( 'Lists', () => {
	test( 'renders with toolbar disabled', async () => {
		setupListsApiStub();
		mw.user = { getName: jest.fn( () => 'Bob' ) };
		mw.util = { getUrl: jest.fn( ( path ) => `/wiki/${ path }` ) };

		const Lists = require( '../../../resources/ext.readingLists.special/pages/Lists.vue' );
		const wrapper = mount( Lists );

		Object.defineProperty( wrapper.vm, 'enableToolbar', { value: false, writable: true } );

		await wrapper.vm.$forceUpdate();
		await wrapper.vm.$nextTick();

		expect( wrapper.element ).toMatchSnapshot();
	} );

	test( 'renders with toolbar enabled', async () => {
		setupListsApiStub();
		mw.user = { getName: jest.fn( () => 'Bob' ) };
		mw.util = { getUrl: jest.fn( ( path ) => `/wiki/${ path }` ) };

		const Lists = require( '../../../resources/ext.readingLists.special/pages/Lists.vue' );
		const wrapper = mount( Lists );

		Object.defineProperty( wrapper.vm, 'enableToolbar', { value: true, writable: true } );

		await wrapper.vm.$forceUpdate();
		await wrapper.vm.$nextTick();

		expect( wrapper.element ).toMatchSnapshot();
	} );
} );
