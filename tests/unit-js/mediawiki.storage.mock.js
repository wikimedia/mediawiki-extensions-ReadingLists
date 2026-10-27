// Minimal stand-in for the `mediawiki.storage` ResourceLoader module, which
// is mapped here via `resolve.alias` (see vitest.config.mjs).
// Only implements what ext.readingLists.api/index.js actually uses.
const store = new Map();

export const session = {
	get: vi.fn( ( key ) => ( store.has( key ) ? store.get( key ) : null ) ),
	set: vi.fn( ( key, value ) => {
		store.set( key, value );
		return true;
	} ),
	remove: vi.fn( ( key ) => {
		store.delete( key );
		return true;
	} )
};

export function resetMockSession() {
	store.clear();
	session.get.mockClear();
	session.set.mockClear();
	session.remove.mockClear();
}
