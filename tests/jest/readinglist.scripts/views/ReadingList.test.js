const { mount } = require( '@vue/test-utils' );
const ReadingList = require( '../../../../resources/readinglist.scripts/views/ReadingList.vue' );
const { CdxCard } = require( '@wikimedia/codex' );

describe( 'ReadingList', () => {
	it( 'renders an empty list', () => {
		const emptyMessage = 'No items on list';
		const wrapper = mount( ReadingList, {
			propsData: {
				listId: 2,
				cards: [],
				emptyMessage
			}
		} );
		expect(
			wrapper.props().emptyMessage
		).toBe( emptyMessage );
		expect(
			wrapper.element
		).toMatchSnapshot();
		expect(
			wrapper.findAllComponents( CdxCard ).length
		).toBe( 0 );
		expect(
			wrapper.find( '.readinglist-list-empty' ).exists()
		).toBe( true );
	} );

	const exampleCard = {
		id: 1,
		url: 'https://en.wikipedia.org/wiki/Super_Bowl',
		thumbnail: {
			url: 'https://upload.wikimedia.org/wikipedia/commons/d/d0/Super_Bowl_rings_on_display.jpg'
		},
		title: 'Super Bowl',
		project: 'https://en.wikipedia.org',
		description: 'The Super Bowl is the annual league championship game of the National Football League (NFL) of the United States.'
	};

	const exampleProps = {
		propsData: {
			listId: 1,
			cards: [ exampleCard ]
		}
	};

	it( 'renders a list of pages', () => {
		const wrapper = mount( ReadingList, exampleProps );
		expect(
			wrapper.element
		).toMatchSnapshot();
		expect(
			wrapper.findAllComponents( CdxCard ).length
		).toBe( 1 );
		expect(
			wrapper.find( '.cdx-card' ).exists()
		).toBe( true );
		expect(
			wrapper.find( '.cdx-card' ).attributes()
		).toHaveProperty( 'href', exampleCard.url );
		expect(
			wrapper.find( '.cdx-card__text__title' ).text()
		).toContain( exampleCard.title );
		expect(
			wrapper.find( '.cdx-card__text__description' ).text()
		).toContain( exampleCard.description );
	} );

	it( 'renders a delete button for each page', () => {
		const wrapper = mount( ReadingList, exampleProps );
		expect(
			wrapper.find( '.cdx-button--action-destructive' ).exists()
		).toBe( true );
	} );
} );
