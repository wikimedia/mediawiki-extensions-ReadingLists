const { mount, flushPromises } = require( '@vue/test-utils' );
const api = require( '../../../resources/ext.readingLists.api/index.js' );

const LIST = require( '../fixtures/list.json' );
const ENTRIES = require( '../fixtures/entries.json' );
const PAGES = require( '../fixtures/pages.json' );
const ALL_ENTRIES = require( '../fixtures/allentries.json' );
const ALL_PAGES = require( '../fixtures/allpages.json' );

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

function setupAllItemsApiStub() {
	return api.stubApi( {
		get: jest.fn( ( { action, list, rlelists, prop } ) => {
			if ( action === 'query' ) {
				if ( list === 'readinglistentries' && rlelists === undefined ) {
					return ALL_ENTRIES;
				} else if ( prop !== undefined ) {
					return ALL_PAGES;
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

	test( 'renders all items from all lists on special page', async () => {
		setupAllItemsApiStub();
		mw.util = { getUrl: jest.fn( ( path ) => `/wiki/${ path }` ) };

		const Entries = require( '../../../resources/ext.readingLists.special/pages/Entries.vue' );
		const wrapper = mount( Entries );

		Object.defineProperty( wrapper.vm, 'enableToolbar', { value: false, writable: true } );

		await flushPromises();

		expect( wrapper.vm.isAllListItems ).toBe( true );
		expect( wrapper.vm.isDefaultList ).toBe( false );
		expect( wrapper.vm.entries.length ).toBeGreaterThan( 0 );
		expect( wrapper.element ).toMatchSnapshot();
	} );
} );
