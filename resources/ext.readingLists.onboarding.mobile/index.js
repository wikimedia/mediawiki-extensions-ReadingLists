const mountOnboarding = require( 'ext.readingLists.onboarding' );

// Keep this module for old cached code. New code uses ext.readingLists.onboarding.
module.exports = function ( config ) {
	return mountOnboarding( Object.assign( { variant: 'mobile' }, config ) );
};
