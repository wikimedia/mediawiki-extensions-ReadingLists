const { mount } = require( '@vue/test-utils' );
const api = require( '../../../resources/ext.readingLists.api/index.js' );

const LIST = require( '../fixtures/list.json' );
const ENTRIES = require( '../fixtures/entries.json' );
const PAGES = require( '../fixtures/pages.json' );

function setupEntriesApiStub() {
	return api.stubApi( {
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
}

describe( 'Entries', () => {
	test( 'renders with toolbar disabled', async () => {
		setupEntriesApiStub();
		mw.util = { getUrl: jest.fn( ( path ) => `/wiki/${ path }` ) };

		const Entries = require( '../../../resources/ext.readingLists.special/pages/Entries.vue' );
		const wrapper = mount( Entries, { props: { listId: 12345 } } );

		Object.defineProperty( wrapper.vm, 'enableToolbar', { value: false, writable: true } );

		await wrapper.vm.$forceUpdate();
		await wrapper.vm.$nextTick();

		expect( wrapper.element ).toMatchSnapshot();
	} );

	test( 'renders with toolbar enabled', async () => {
		setupEntriesApiStub();
		mw.util = { getUrl: jest.fn( ( path ) => `/wiki/${ path }` ) };

		const Entries = require( '../../../resources/ext.readingLists.special/pages/Entries.vue' );
		const wrapper = mount( Entries, { props: { listId: 12345 } } );

		Object.defineProperty( wrapper.vm, 'enableToolbar', { value: true, writable: true } );

		wrapper.vm.ready = true;
		wrapper.vm.loadingEntries = true;

		await wrapper.vm.$forceUpdate();
		await wrapper.vm.$nextTick();

		expect( wrapper.element ).toMatchSnapshot();
	} );
} );
