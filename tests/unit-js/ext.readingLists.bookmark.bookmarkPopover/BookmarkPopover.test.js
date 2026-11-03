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
		mw.errorLogger = { logError: vi.fn() };
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
				isNewSave: false,
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
			mountPopover( { isNewSave: true } );
			expect( wrapper.html() ).toMatchSnapshot();
		} );

		test( 'when article is already saved', () => {
			mountPopover( { isNewSave: false } );
			expect( wrapper.html() ).toMatchSnapshot();
		} );
	} );

	describe( 'handleOpenChange', () => {
		test( 'calls onDismiss with isSaved:true and showNotification:false when the popover closes', () => {
			mountPopover();
			wrapper.vm.handleOpenChange( false );
			expect( onDismiss ).toHaveBeenCalledWith( { isSaved: true, showNotification: false } );
		} );

		test( 'does not call onDismiss when the popover emits open', () => {
			mountPopover();
			wrapper.vm.handleOpenChange( true );
			expect( onDismiss ).not.toHaveBeenCalled();
		} );
	} );

	describe( 'truncatedFormattedTitle', () => {
		test( 'replaces underscores with spaces', () => {
			mountPopover( { title: 'Test_Page_With_Underscores' } );
			expect( wrapper.vm.truncatedFormattedTitle ).toBe( 'Test Page With Underscores' );
		} );

		test( 'truncates long words', () => {
			const longTitle = 'Taumatawhakatangi­hangakoauauotamatea­turipukakapikimaunga­horonukupokaiwhen­uakitanatahu';
			mountPopover( { title: longTitle } );
			expect( wrapper.vm.truncatedFormattedTitle ).toBe( '$1…' );
		} );
	} );

	describe( 'addToCollection', () => {
		const collectionPickerStub = {
			name: 'CollectionPicker',
			props: [ 'title', 'collections' ],
			emits: [ 'create-collection', 'add-to-collection', 'unsave-click' ],
			template: '<div />'
		};

		function mountPopoverWithCollectionPickerStub( props = {} ) {
			wrapper = mount( BookmarkPopover, {
				props: {
					title: 'Test Page',
					isNewSave: false,
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

		test( 'calls onDismiss with isSaved:true, listId and listName after add-to-collection', async () => {
			apiModule.stubApi( { postWithEditToken: vi.fn( () => Promise.resolve( {} ) ) } );

			mountPopoverWithCollectionPickerStub();
			await wrapper.vm.addToCollection( 42, 'My List' );

			expect( onDismiss ).toHaveBeenCalledWith( { isSaved: true, showNotification: true, listId: 42, listName: 'My List' } );
		} );

		test( 'preserves the saved state when adding to a collection fails', async () => {
			apiModule.stubApi( {
				postWithEditToken: vi.fn().mockRejectedValue( new Error( 'API failed' ) )
			} );

			mountPopoverWithCollectionPickerStub( { isNewSave: true } );
			await wrapper.vm.addToCollection( 42, 'My List' );

			expect( onDismiss ).toHaveBeenCalledTimes( 1 );
			expect( onDismiss ).toHaveBeenCalledWith( {
				isSaved: true,
				isError: true,
				showNotification: true
			} );
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
			expect( onDismiss ).toHaveBeenCalledWith( {
				isSaved: true,
				listId: 42,
				listName: 'My List',
				showNotification: true
			} );
		} );
	} );

	describe( 'removeFromCollection', () => {
		test( 'waits for removal before closing and notifying, preserving default-list membership', async () => {
			let resolveDelete;
			const deletePromise = new Promise( ( resolve ) => {
				resolveDelete = resolve;
			} );
			const postWithEditToken = vi.fn( () => deletePromise );
			apiModule.stubApi( { postWithEditToken } );

			mountPopover( {
				isInDefaultList: true,
				collections: [ { id: 42, name: 'My List', withTitle: true } ]
			} );
			const removePromise = wrapper.vm.removeFromCollection( 42, 'My List' );

			expect( postWithEditToken ).toHaveBeenCalledTimes( 1 );
			expect( postWithEditToken ).toHaveBeenCalledWith( expect.objectContaining( {
				command: 'deleteentry',
				list: 42,
				title: 'Test Page',
				project: '@local'
			} ) );
			expect( wrapper.vm.isConfigPopoverOpen ).toBe( true );
			expect( onDismiss ).not.toHaveBeenCalled();

			resolveDelete( {} );
			await removePromise;

			expect( wrapper.vm.isConfigPopoverOpen ).toBe( false );
			expect( onDismiss ).toHaveBeenCalledTimes( 1 );
			expect( onDismiss ).toHaveBeenCalledWith( {
				isSaved: true,
				isRemoved: true,
				showNotification: true,
				listId: 42,
				listName: 'My List'
			} );
		} );

		test.each( [ true, false ] )(
			'reports remaining custom-list membership when not in the default list (withTitle=%s)',
			async ( withTitle ) => {
				apiModule.stubApi( { postWithEditToken: vi.fn().mockResolvedValue( {} ) } );
				mountPopover( {
					isInDefaultList: false,
					collections: [
						{ id: 42, name: 'My List', withTitle: true },
						{ id: 43, name: 'Other List', withTitle }
					]
				} );

				await wrapper.vm.removeFromCollection( 42, 'My List' );

				expect( wrapper.vm.isConfigPopoverOpen ).toBe( false );
				expect( onDismiss ).toHaveBeenCalledTimes( 1 );
				expect( onDismiss ).toHaveBeenCalledWith( {
					isSaved: withTitle,
					isRemoved: true,
					showNotification: true,
					listId: 42,
					listName: 'My List'
				} );
			}
		);

		test( 'preserves the saved state and collection membership when removal fails', async () => {
			apiModule.stubApi( {
				postWithEditToken: vi.fn().mockRejectedValue( new Error( 'API failed' ) )
			} );

			mountPopover( {
				collections: [ { id: 42, name: 'My List', withTitle: true } ]
			} );
			await wrapper.vm.removeFromCollection( 42, 'My List' );

			expect( wrapper.vm.allCollections[ 0 ].withTitle ).toBe( true );
			expect( onDismiss ).toHaveBeenCalledTimes( 1 );
			expect( onDismiss ).toHaveBeenCalledWith( {
				isSaved: true,
				isError: true,
				showNotification: true
			} );
		} );
	} );

	describe( 'onUnsaveClick', () => {
		const collectionPickerStub = {
			name: 'CollectionPicker',
			props: [ 'title', 'collections' ],
			emits: [ 'create-collection', 'add-to-collection', 'unsave-click' ],
			template: '<div />'
		};

		const unsaveDialogStub = {
			name: 'UnsaveDialog',
			props: [ 'title', 'collectionsWithPage' ],
			emits: [ 'page-unsaved', 'close' ],
			template: '<div />'
		};

		function mountPopoverWithStubs( props = {} ) {
			wrapper = mount( BookmarkPopover, {
				props: {
					title: 'Test Page',
					isNewSave: false,
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
						UnsaveDialog: unsaveDialogStub
					}
				}
			} );
		}

		describe( 'when there are no collections with the page', () => {
			test( 'calls deleteEntryByPageTitle and closes the popover', async () => {
				const postWithEditToken = vi.fn( () => Promise.resolve( {} ) );
				apiModule.stubApi( { postWithEditToken } );

				mountPopoverWithStubs( { collections: [] } );
				await wrapper.vm.onUnsaveClick();

				expect( postWithEditToken ).toHaveBeenCalledWith( expect.objectContaining( {
					command: 'deleteentry',
					title: 'Test Page',
					project: '@local'
				} ) );
				expect( wrapper.vm.isConfigPopoverOpen ).toBe( false );
				expect( onDismiss ).toHaveBeenCalledWith( {
					isSaved: false,
					showNotification: true
				} );
			} );

			test( 'dismisses once with the saved state preserved when unsaving fails', async () => {
				apiModule.stubApi( {
					postWithEditToken: vi.fn().mockRejectedValue( new Error( 'API failed' ) )
				} );

				mountPopoverWithStubs( { collections: [] } );
				await wrapper.vm.onUnsaveClick();

				expect( onDismiss ).toHaveBeenCalledTimes( 1 );
				expect( onDismiss ).toHaveBeenCalledWith( {
					isSaved: true,
					isError: true,
					showNotification: true
				} );
			} );

			test( 'waits for deleteEntryByPageTitle before calling onDismiss', async () => {
				let resolveDelete;
				const deletePromise = new Promise( ( resolve ) => {
					resolveDelete = resolve;
				} );
				apiModule.stubApi( { postWithEditToken: vi.fn( () => deletePromise ) } );

				mountPopoverWithStubs( { collections: [] } );
				const unsavePromise = wrapper.vm.onUnsaveClick();

				expect( wrapper.vm.isConfigPopoverOpen ).toBe( true );
				expect( onDismiss ).not.toHaveBeenCalled();

				resolveDelete( {} );
				await unsavePromise;

				expect( wrapper.vm.isConfigPopoverOpen ).toBe( false );
				expect( onDismiss ).toHaveBeenCalledWith( {
					isSaved: false,
					showNotification: true
				} );
			} );
		} );

		describe( 'when the page is in one or more collections', () => {
			test( 'opens the unsave dialog instead of deleting directly', async () => {
				const postWithEditToken = vi.fn();
				apiModule.stubApi( { postWithEditToken } );

				mountPopoverWithStubs( {
					collections: [ { id: 1, name: 'My List', withTitle: true } ]
				} );
				await wrapper.vm.onUnsaveClick();

				expect( postWithEditToken ).not.toHaveBeenCalled();
				expect( wrapper.vm.isUnsaveDialogOpen ).toBe( true );
				expect( wrapper.vm.isConfigPopoverOpen ).toBe( true );
			} );
		} );
	} );

	describe( 'onPageUnsaved', () => {
		test( 'closes the unsave dialog and the popover, and calls onDismiss with isSaved:false', () => {
			mountPopover();
			wrapper.vm.isUnsaveDialogOpen = true;

			wrapper.vm.onPageUnsaved();

			expect( wrapper.vm.isUnsaveDialogOpen ).toBe( false );
			expect( wrapper.vm.isConfigPopoverOpen ).toBe( false );
			expect( onDismiss ).toHaveBeenCalledWith( { isSaved: false, showNotification: true } );
		} );
	} );
} );
