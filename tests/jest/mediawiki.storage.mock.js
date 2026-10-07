// Minimal stand-in for the `mediawiki.storage` ResourceLoader module, which
// Jest cannot resolve via its usual require() mapping (see jest.config.js).
// Only implements what ext.readingLists.api/index.js actually uses.
const store = new Map();

const session = {
	get: jest.fn( ( key ) => ( store.has( key ) ? store.get( key ) : null ) ),
	set: jest.fn( ( key, value ) => {
		store.set( key, value );
		return true;
	} ),
	remove: jest.fn( ( key ) => {
		store.delete( key );
		return true;
	} )
};

function resetMockSession() {
	store.clear();
	session.get.mockClear();
	session.set.mockClear();
	session.remove.mockClear();
}

module.exports = { session, resetMockSession };
