<template>
	<div>
		<cdx-dialog
			v-model:open="isOpen"
			class="readinglists-cta-dialog"
			:title="title"
			:subtitle="subtitle"
			:use-close-button="true"
			:render-in-place="true"
			@update:open="onUpdateOpen"
		>
			<p>{{ callToAction }}</p>

			<div
				class="readinglists-cta-dialog__illustration"
				:style="{ backgroundImage: `url( ${illustrationPath} )` }"
			></div>

			<template #footer>
				<div class="readinglists-cta-dialog__actions">
					<a
						:class="getFakeButtonClasses( 'primary' )"
						:href="createAccountUrl"
						@click="handlePrimaryActionClick">
						{{ primaryActionLabel }}
					</a>
					<a
						:class="getFakeButtonClasses( 'default' )"
						:href="loginUrl"
						@click="handleDefaultActionClick">
						{{ defaultActionLabel }}
					</a>
				</div>
			</template>
		</cdx-dialog>
	</div>
</template>

<script>
const { computed, ref } = require( 'vue' );
const { CdxDialog } = require( '../../codex.js' );

/**
 * Dialog shown to anonymous users when they click the bookmark button, prompting sign-in.
 */
// @vue/component
module.exports = exports = {
	name: 'CtaDialog',
	components: {
		CdxDialog
	},
	props: {
		onClose: {
			type: Function,
			default: () => {}
		}
	},
	setup( props ) {
		const isOpen = ref( true );

		const title = computed( () => mw.msg( 'readinglists-cta-dialog-title' ) );
		const subtitle = computed( () => mw.msg( 'readinglists-cta-dialog-subtitle' ) );
		const callToAction = computed( () => mw.msg( 'readinglists-cta-dialog-call-to-action' ) );

		const illustrationPath = computed( () => mw.config.get( 'wgExtensionAssetsPath' ) +
			'/ReadingLists/resources/assets/cta-dialog-illustration.svg' );

		const primaryActionLabel = computed( () => mw.msg( 'readinglists-cta-dialog-create-account' ) );
		const defaultActionLabel = computed( () => mw.msg( 'readinglists-cta-dialog-log-in' ) );

		const returnTo = mw.config.get( 'wgPageName' );
		const urlParams = {
			returnto: returnTo
		};

		// Signup instrumentation relies on type=signup and a returntoquery marker
		// so the redirect flow can be attributed back to this dialog.
		const createAccountUrl = computed( () => mw.util.getUrl( 'Special:CreateAccount', {
			returnto: returnTo,
			returntoquery: 'readingListsAccountCreationCta=1',
			type: 'signup',
			// Needed to skip the newcomer welcome survey. See T422169.
			campaign: 'account-creation-reading-list-cta'
		} ) );
		const loginUrl = computed( () => mw.util.getUrl( 'Special:UserLogin', urlParams ) );

		function getFakeButtonClasses( action ) {
			return {
				'cdx-button cdx-button--fake-button cdx-button--fake-button--enabled': true,
				'cdx-button--weight-primary cdx-button--action-progressive': action === 'primary'
			};
		}

		function onUpdateOpen( newOpenState ) {
			if ( !newOpenState ) {
				props.onClose();
			}
		}

		const experiment = mw.testKitchen.compat.getExperiment( 'account-creation-reading-list-cta' );
		if ( experiment ) {
			// eslint-disable-next-line camelcase
			experiment.send( 'init', { action_subtype: 'init_sign_up' } );
		}

		function handlePrimaryActionClick() {
			if ( experiment ) {
				// eslint-disable-next-line camelcase
				experiment.send( 'click', { action_subtype: 'sign_up' } );
			}
		}

		function handleDefaultActionClick() {
			if ( experiment ) {
				// eslint-disable-next-line camelcase
				experiment.send( 'click', { action_subtype: 'login' } );
			}
		}

		return {
			isOpen,
			title,
			subtitle,
			callToAction,
			illustrationPath,
			primaryActionLabel,
			createAccountUrl,
			defaultActionLabel,
			loginUrl,
			getFakeButtonClasses,
			onUpdateOpen,
			handlePrimaryActionClick,
			handleDefaultActionClick
		};
	}
};
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

.readinglists-cta-dialog {
	&__illustration {
		background-repeat: no-repeat;
		background-position: center;
		height: 10em;
		margin-top: @spacing-150;
	}

	&__actions {
		display: flex;
		flex-direction: column;
		gap: @spacing-75;
		width: @size-full;

		.cdx-button {
			max-width: none;
		}
	}
}
</style>
