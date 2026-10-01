const { ref, onMounted, onUnmounted } = require( 'vue' );

const MAX_WIDTH_MOBILE = 639;

/**
 * Track a boolean that depends on the viewport width, recalculating on (debounced) window
 * resize for the lifetime of the component. Returns true if the viewport is mobile.
 *
 * @param {Function} predicate function that takes the current window width and returns a boolean.
 * @param {number} [debounceMs] Debounce delay in ms for the resize listener.
 * @return {Object} A Vue ref wrapping the current boolean value.
 */
function isResolution( predicate, debounceMs = 100 ) {
	const value = ref( predicate( window.innerWidth ) );

	function onResize() {
		value.value = predicate( window.innerWidth );
	}

	const debouncedOnResize = mw.util.debounce( onResize, debounceMs );

	onMounted( () => {
		window.addEventListener( 'resize', debouncedOnResize );
	} );

	onUnmounted( () => {
		window.removeEventListener( 'resize', debouncedOnResize );
	} );

	return value;
}

const useIsMobileResolution = () => isResolution(
	( width ) => width < MAX_WIDTH_MOBILE
);

const useIsAboveMobileResolution = () => isResolution(
	( width ) => width >= MAX_WIDTH_MOBILE
);

module.exports = {
	MAX_WIDTH_MOBILE,
	useIsMobileResolution,
	useIsAboveMobileResolution
};
