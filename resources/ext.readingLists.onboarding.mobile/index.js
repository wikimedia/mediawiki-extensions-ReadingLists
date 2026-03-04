const mountApp = require( 'ext.readingLists.onboarding' );
const MobileOnboardingPopover = require( './MobileOnboardingPopover.vue' );

/**
 * @param {Object} config Configuration object for the onboarding popover.
 * @param {Element} config.target DOM element to anchor the popover to.
 * @param {string} config.storageKey Local storage key for popover display status.
 * @param {string} config.titleMsgKey i18n message key for popover title.
 * @param {string} config.bodyMsgKey i18n message key for popover body text.
 * @return {Promise<void>}
 */
module.exports = function ( config ) {
	return mountApp( Object.assign( { component: MobileOnboardingPopover }, config ) );
};
