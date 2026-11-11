const mountApp = require( './mountApp.js' );
const OnboardingPopover = require( './OnboardingPopover.vue' );
const MobileOnboardingPopover = require( './MobileOnboardingPopover.vue' );

const components = {
	desktop: OnboardingPopover,
	mobile: MobileOnboardingPopover
};

/**
 * @param {Object} config Configuration object for the onboarding popover.
 * @param {string} config.variant Popover variant, 'desktop' or 'mobile'.
 * @param {Element} config.target DOM element to anchor the popover to.
 * @param {string} config.storageKey Local storage key for popover display status.
 * @param {string} config.titleMsgKey i18n message key for popover title.
 * @param {string} config.bodyMsgKey i18n message key for popover body text.
 * @param {string|null} [config.bannerImagePath] Path to banner image (desktop only).
 * @return {Promise<void>}
 */
module.exports = function ( config ) {
	if ( !config || !Object.prototype.hasOwnProperty.call( components, config.variant ) ) {
		return Promise.reject( new Error(
			`config.variant must be 'desktop' or 'mobile', got "${ config && config.variant }"`
		) );
	}
	return mountApp( Object.assign( { component: components[ config.variant ] }, config ) );
};
