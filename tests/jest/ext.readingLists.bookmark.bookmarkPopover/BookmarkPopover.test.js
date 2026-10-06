const { mount } = require( '@vue/test-utils' );

const apiModule = require( '../../../resources/ext.readingLists.api/index.js' );
const BookmarkPopover = require( '../../../resources/ext.readingLists.bookmark.bookmarkPopover/BookmarkPopover.vue' );

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
		onDismiss = jest.fn();
		mw.message.mockReturnValue( { parse: jest.fn( () => 'Save success title' ) } );
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
						CreateCollectionDialog: {
							name: 'CreateCollectionDialog',
							emits: [ 'success' ],
							template: '<div />'
						}
					}
				}
			} );
		}

		test( 'calls deleteEntryByPageTitle and createEntry when add-to-collection is triggered', async () => {
			const postWithEditToken = jest.fn( () => Promise.resolve( {} ) );
			apiModule.stubApi( { postWithEditToken } );

			mountPopoverWithCollectionPickerStub();
			wrapper.vm.addToCollection( 42, 'My List' );
			await Promise.resolve();

			expect( postWithEditToken ).toHaveBeenCalledWith( expect.objectContaining( {
				command: 'deleteentry',
				title: 'Test Page',
				project: '@local'
			} ) );
			expect( postWithEditToken ).toHaveBeenCalledWith( expect.objectContaining( {
				command: 'createentry',
				list: 42,
				title: 'Test Page'
			} ) );
		} );

		test( 'calls onDismiss with true, listId and listName after add-to-collection', () => {
			apiModule.stubApi( { postWithEditToken: jest.fn( () => Promise.resolve( {} ) ) } );

			mountPopoverWithCollectionPickerStub();
			wrapper.vm.addToCollection( 42, 'My List' );

			expect( onDismiss ).toHaveBeenCalledWith( true, 42, 'My List' );
		} );

		test( 'closes the config popover after add-to-collection', async () => {
			apiModule.stubApi( { postWithEditToken: jest.fn( () => Promise.resolve( {} ) ) } );

			mountPopoverWithCollectionPickerStub();

			expect( wrapper.vm.isConfigPopoverOpen ).toBe( true );

			wrapper.vm.addToCollection( 42, 'My List' );

			expect( wrapper.vm.isConfigPopoverOpen ).toBe( false );
		} );
	} );
} );
