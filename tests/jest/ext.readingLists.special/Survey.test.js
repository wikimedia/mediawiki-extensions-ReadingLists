const { nextTick } = require( 'vue' );
const { mount } = require( '@vue/test-utils' );
const { CdxTextArea } = require( '@wikimedia/codex' );
const Survey = require( '../../../resources/ext.readingLists.special/components/Survey.vue' );

const SURVEY_ANSWER_NEGATIVE = 'readinglists-betafeature-quicksurvey-answer-negative';

describe( 'Survey', () => {
	beforeEach( () => {
		mw.loader = {
			using: jest.fn().mockReturnValue( {
				then: () => {}
			} )
		};
	} );

	afterEach( () => {
		jest.restoreAllMocks();
	} );

	it( 'matches the snapshot', async () => {
		const wrapper = mount( Survey );

		expect( wrapper.element ).toMatchSnapshot();
	} );

	describe( 'when the positive button is clicked', () => {
		it( 'opens the dialog and renders dialog content', async () => {
			const wrapper = mount( Survey );

			const positiveButton = wrapper.find( '.reading-lists-survey__option-button' );
			await positiveButton.trigger( 'click' );
			await nextTick();

			expect( wrapper.vm.dialogOpen ).toBe( true );
			expect( wrapper.find( '.cdx-dialog' ).exists() ).toBe( true );
			expect( wrapper.find( '.cdx-dialog__header__title' ).exists() ).toBe( true );
			expect( wrapper.find( '.cdx-dialog__footer__primary-action' ).exists() ).toBe( true );
		} );
	} );

	describe( 'when the negative button is clicked', () => {
		it( 'opens the dialog', async () => {
			const wrapper = mount( Survey );

			const negativeButton = wrapper.findAll( '.reading-lists-survey__option-button' )[ 1 ];
			await negativeButton.trigger( 'click' );
			await nextTick();

			expect( wrapper.vm.dialogOpen ).toBe( true );
		} );
	} );

	describe( 'when the dialog is closed', () => {
		describe( 'via the primary action button', () => {
			it( 'closes the dialog and shows the thank you message', async () => {
				const wrapper = mount( Survey );

				const positiveButton = wrapper.find( '.reading-lists-survey__option-button' );
				await positiveButton.trigger( 'click' );
				await nextTick();

				const primaryButton = wrapper.find( '.cdx-dialog__footer__primary-action' );
				await primaryButton.trigger( 'click' );

				expect( wrapper.vm.dialogOpen ).toBe( false );
				expect( wrapper.find( '.cdx-message' ).exists() ).toBe( true );
			} );
		} );

		describe( 'via the close button', () => {
			it( 'closes the dialog and shows the thank you message', async () => {
				const wrapper = mount( Survey );

				const positiveButton = wrapper.find( '.reading-lists-survey__option-button' );
				await positiveButton.trigger( 'click' );
				await nextTick();

				const closeButton = wrapper.find( '.cdx-dialog__header__close-button' );
				await closeButton.trigger( 'click' );

				expect( wrapper.vm.dialogOpen ).toBe( false );
				expect( wrapper.find( '.cdx-message' ).exists() ).toBe( true );
			} );
		} );
	} );

	describe( 'when the survey is completed', () => {
		it( 'contains the correct', async () => {
			const wrapper = mount( Survey );

			const negativeButton = wrapper.findAll( '.reading-lists-survey__option-button' )[ 1 ];
			await negativeButton.trigger( 'click' );

			expect( wrapper.vm.selectedOption ).toBe( SURVEY_ANSWER_NEGATIVE );

			const textareaComponent = wrapper.findComponent( CdxTextArea );
			await textareaComponent.get( 'textarea' ).setValue( 'Here is some feedback' );
			expect( wrapper.vm.freeTextAnswer ).toBe( 'Here is some feedback' );
		} );
	} );
} );
