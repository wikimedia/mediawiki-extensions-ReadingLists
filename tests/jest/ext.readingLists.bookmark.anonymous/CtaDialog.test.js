const { mount } = require( '@vue/test-utils' );

import CtaDialog from '../../../resources/ext.readingLists.bookmark.anonymous/CtaDialog.vue';

mw.testKitchen = {
	compat: {
		getExperiment: vi.fn( () => ( {
			send: vi.fn() // Properly mock the `send` method
		} ) )
	}
};

describe( 'CtaDialog', () => {
	test( 'matches the snapshot', () => {
		const wrapper = mount( CtaDialog );

		expect( wrapper.element ).toMatchSnapshot();
	} );

	describe( 'remembers the page to save after log in or account creation', () => {
		beforeEach( () => {
			mw.config.get.mockImplementation( ( key ) => ( key === 'wgPageName' ? 'Test_Page' : undefined ) );
			mw.storage.session = { set: vi.fn() };
		} );

		test.each( [
			[ 'create account', 0 ],
			[ 'log in', 1 ]
		] )( 'when the %s link is clicked', async ( _label, index ) => {
			const wrapper = mount( CtaDialog );

			await wrapper.findAll( '.readinglists-cta-dialog__actions a' )[ index ].trigger( 'click' );

			expect( mw.storage.session.set ).toHaveBeenCalledWith(
				'readinglists-cta-autosave',
				'Test_Page',
				3600
			);
		} );
	} );
} );
