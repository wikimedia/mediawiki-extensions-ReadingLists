<template>
	<div class="reading-lists-survey">
		<template v-if="!completed">
			<div class="reading-lists-survey__question">{{ msgQuestion }}</div>
			<!-- eslint-disable-next-line vue/no-v-html -->
			<div class="reading-lists-survey__privacy-policy" v-html="msgPrivacyPolicy"></div>
			<div class="reading-lists-survey__options">
				<cdx-button
					v-for="option in options"
					:key="option.key"
					class="reading-lists-survey__option-button"
					@click="onClickOption( option.key )">
					{{ option.label }}
				</cdx-button>
			</div>
			<cdx-dialog
				id="reading-lists-survey-dialog"
				v-model:open="dialogOpen"
				class="reading-lists-survey__dialog"
				:title="msgFeedbackQuestion"
				:render-in-place="true"
				:use-close-button="true"
				:primary-action="{ label: msgSubmit, actionType: 'progressive' }"
				@primary="submit"
				@update:open="onUpdateOpen"
			>
				<cdx-text-area
					v-model="freeTextAnswer"
					:placeholder="msgPlaceholder"
					aria-labelledby="#reading-lists-survey-dialog .cdx-dialog__header__title"
				></cdx-text-area>
			</cdx-dialog>
		</template>
		<template v-else>
			<cdx-message type="success" allow-user-dismiss>
				{{ msgThankYou }}
			</cdx-message>
		</template>
	</div>
</template>

<script>
const {
	CdxButton,
	CdxDialog,
	CdxMessage,
	CdxTextArea
} = require( '../../../codex.js' );

const SURVEY_NAME = 'ReadingLists beta feature survey';
const SURVEY_QUESTION = 'readinglists-betafeature-quicksurvey-question';
const SURVEY_ANSWER_POSITIVE = 'readinglists-betafeature-quicksurvey-answer-positive';
const SURVEY_ANSWER_NEGATIVE = 'readinglists-betafeature-quicksurvey-answer-negative';

/**
 * User sentiment survey.
 */
// @vue/component
module.exports = exports = {
	name: 'UserSentimentSurvey',
	components: {
		CdxButton,
		CdxDialog,
		CdxMessage,
		CdxTextArea
	},
	emits: [ 'survey-completed' ],
	data() {
		return {
			options: [
				{ key: SURVEY_ANSWER_POSITIVE, label: mw.msg( SURVEY_ANSWER_POSITIVE ) },
				{ key: SURVEY_ANSWER_NEGATIVE, label: mw.msg( SURVEY_ANSWER_NEGATIVE ) }
			],
			selectedOption: null,
			freeTextAnswer: '',
			completed: false,
			dialogOpen: false,
			msgQuestion: mw.msg( 'readinglists-betafeature-quicksurvey-question' ),
			msgFeedbackQuestion: mw.msg( 'readinglists-betafeature-quicksurvey-question-feedback' ),
			msgPlaceholder: mw.msg( 'readinglists-betafeature-quicksurvey-feedback-placeholder' ),
			// .parse() to convert the wikitext link in the message to HTML
			msgPrivacyPolicy: mw.message( 'readinglists-betafeature-quicksurvey-privacy-policy' ).parse(),
			msgSubmit: mw.msg( 'readinglists-betafeature-quicksurvey-submit' ),
			msgThankYou: mw.msg( 'readinglists-betafeature-quicksurvey-thank-you' )
		};
	},
	methods: {
		/**
		 * Handle option button click.
		 *
		 * @param {string} optionKey Message key of the selected option
		 */
		onClickOption( optionKey ) {
			this.selectedOption = optionKey;
			this.dialogOpen = true;
		},
		/**
		 * Complete the survey and log survey data via the logSurveyAnswer API from QuickSurveys.
		 */
		submit() {
			this.dialogOpen = false;
			this.completed = true;
			mw.loader.using( 'ext.quicksurveys.lib' ).then( ( req ) => {
				req( 'ext.quicksurveys.lib' ).logSurveyAnswer(
					SURVEY_NAME,
					SURVEY_QUESTION,
					{ [ this.selectedOption ]: this.freeTextAnswer }
				);
			} );
			this.$emit( 'survey-completed' );
		},
		/**
		 * Handle change to dialog open state.
		 *
		 * @param {boolean} open New state
		 */
		onUpdateOpen( open ) {
			// If the dialog is closed via close button, esc, or backdrop click, submit the survey.
			if ( !open ) {
				this.submit();
			}
		}
	}
};
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

.reading-lists-survey {
	max-width: @size-2400;
	margin: 0 auto @spacing-100;

	&__question {
		text-align: center;
	}

	&__privacy-policy {
		text-align: center;
		margin-top: @spacing-50;
		font-size: @font-size-small;
	}

	&__options {
		display: flex;
		justify-content: center;
		gap: @spacing-75;
		margin-top: @spacing-75;
	}
}
</style>
