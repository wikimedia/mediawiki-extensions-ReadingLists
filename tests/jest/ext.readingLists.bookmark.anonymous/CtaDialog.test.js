const { mount } = require( '@vue/test-utils' );
const CtaDialog = require( '../../../resources/ext.readingLists.bookmark.anonymous/CtaDialog.vue' );

mw.testKitchen = {
	compat: {
		getExperiment: jest.fn( () => ( {
			send: jest.fn() // Properly mock the `send` method
		} ) )
	}
};

describe( 'CtaDialog', () => {
	test( 'matches the snapshot', () => {
		const wrapper = mount( CtaDialog );

		expect( wrapper.element ).toMatchSnapshot();
	} );
} );
