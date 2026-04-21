const { mount } = require( '@vue/test-utils' );

const CtaDialog = require( '../../../resources/ext.readingLists.bookmark.anonymous/CtaDialog.vue' );

describe( 'CtaDialog', () => {
	test( 'matches the snapshot', () => {
		const wrapper = mount( CtaDialog );

		expect( wrapper.element ).toMatchSnapshot();
	} );
} );
