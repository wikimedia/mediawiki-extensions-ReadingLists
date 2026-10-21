import { mount } from '@vue/test-utils';

// Imported rather than required so the test and the component under test share
// the same module instance, which is what makes `api.stubApi()` take effect.
import apiModule from '../../../resources/ext.readingLists.api/index.js';
import BookmarkPopover from '../../../resources/ext.readingLists.bookmark.bookmarkPopover/BookmarkPopover.vue';

const configPopoverStub = {
	name: 'ConfigPopover',
	props: [ 'open' ],
	emits: [ 'update:open' ],
	template: '<div v-if="open"><slot name="header" /></div>'
};

describe( 'BookmarkPopover', () => {
	let onDismiss;
	let wrapper;

	beforeEach( () => {
		onDismiss = vi.fn();
		mw.message.mockReturnValue( { parse: vi.fn( () => 'Save success title' ) } );
	} );

	afterEach( () => {
		if ( wrapper ) {
			wrapper.unmount();
			wrapper = null;
		}
	} );

	function mountPopover( props = {} ) {
		wrapper = mount( BookmarkPopover, {
			props: {
				title: 'Test Page',
				isCurrentlySaved: false,
				onDismiss,
				...props
			},
			global: {
				stubs: {
					ConfigPopover: configPopoverStub,
					CdxButton: {
						name: 'CdxButton',
						template: '<button @click="$emit( \'click\' )"><slot /></button>',
						emits: [ 'click' ]
					},
					CdxIcon: {
						name: 'CdxIcon',
						props: [ 'icon' ],
						template: '<span />'
					}
				}
			}
		} );
	}

	describe( 'renders correctly', () => {
		test( 'when article is not yet saved', () => {
			mountPopover( { isCurrentlySaved: false } );
			expect( wrapper.html() ).toMatchSnapshot();
		} );

		test( 'when article is already saved', () => {
			mountPopover( { isCurrentlySaved: true } );
			expect( wrapper.html() ).toMatchSnapshot();
		} );
	} );

	describe( 'handleOpenChange', () => {
		test( 'calls onDismiss with false when the popover closes', () => {
			mountPopover();
			wrapper.vm.handleOpenChange( false );
			expect( onDismiss ).toHaveBeenCalledWith( false );
		} );

		test( 'does not call onDismiss when the popover emits open', () => {
			mountPopover();
			wrapper.vm.handleOpenChange( true );
			expect( onDismiss ).not.toHaveBeenCalled();
		} );
	} );

	describe( 'addToCollection', () => {
		const collectionPickerStub = {
			name: 'CollectionPicker',
			props: [ 'title' ],
			emits: [ 'create-collection', 'add-to-collection' ],
			template: '<div />'
		};

		function mountPopoverWithCollectionPickerStub( props = {} ) {
			wrapper = mount( BookmarkPopover, {
				props: {
					title: 'Test Page',
					isCurrentlySaved: false,
					onDismiss,
					...props
				},
				global: {
					stubs: {
						ConfigPopover: configPopoverStub,
						CdxButton: {
							name: 'CdxButton',
							template: '<button @click="$emit( \'click\' )"><slot /></button>',
							emits: [ 'click' ]
						},
						CdxIcon: {
							name: 'CdxIcon',
							props: [ 'icon' ],
							template: '<span />'
						},
						CollectionPicker: collectionPickerStub,
						CollectionNameDialog: {
							name: 'CollectionNameDialog',
							emits: [ 'success' ],
							template: '<div />'
						}
					}
				}
			} );
		}

		test( 'adds to the collection without deleting existing entries', async () => {
			const postWithEditToken = vi.fn( () => Promise.resolve( {} ) );
			apiModule.stubApi( { postWithEditToken } );

			mountPopoverWithCollectionPickerStub();
			await wrapper.vm.addToCollection( 42, 'My List' );

			expect( postWithEditToken ).toHaveBeenCalledTimes( 1 );
			expect( postWithEditToken ).toHaveBeenCalledWith( expect.objectContaining( {
				command: 'createentry',
				list: 42,
				title: 'Test Page',
				project: '@local'
			} ) );
		} );

		test( 'calls onDismiss with true, listId and listName after add-to-collection', async () => {
			apiModule.stubApi( { postWithEditToken: vi.fn( () => Promise.resolve( {} ) ) } );

			mountPopoverWithCollectionPickerStub();
			await wrapper.vm.addToCollection( 42, 'My List' );

			expect( onDismiss ).toHaveBeenCalledWith( true, 42, 'My List' );
		} );

		test( 'closes the config popover after add-to-collection', async () => {
			apiModule.stubApi( { postWithEditToken: vi.fn( () => Promise.resolve( {} ) ) } );

			mountPopoverWithCollectionPickerStub();

			expect( wrapper.vm.isConfigPopoverOpen ).toBe( true );

			await wrapper.vm.addToCollection( 42, 'My List' );

			expect( wrapper.vm.isConfigPopoverOpen ).toBe( false );
		} );

		test( 'waits for createEntry before closing the popover and calling onDismiss', async () => {
			let resolveCreate;
			const createEntryPromise = new Promise( ( resolve ) => {
				resolveCreate = resolve;
			} );
			const postWithEditToken = vi.fn( () => createEntryPromise );
			apiModule.stubApi( { postWithEditToken } );

			mountPopoverWithCollectionPickerStub();
			const addToCollectionPromise = wrapper.vm.addToCollection( 42, 'My List' );

			expect( postWithEditToken ).toHaveBeenCalledWith( expect.objectContaining( { command: 'createentry' } ) );
			expect( wrapper.vm.isConfigPopoverOpen ).toBe( true );
			expect( onDismiss ).not.toHaveBeenCalled();

			resolveCreate( {} );
			await addToCollectionPromise;

			expect( wrapper.vm.isConfigPopoverOpen ).toBe( false );
			expect( onDismiss ).toHaveBeenCalledTimes( 1 );
			expect( onDismiss ).toHaveBeenCalledWith( true, 42, 'My List' );
		} );
	} );
} );
