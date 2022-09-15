/**
 * @param {string} ownerName person who owns the list
 * @param {number} [id] of the list
 * @param {string} [title] of the list
 * @return {string}
 */
const getReadingListUrl = ( ownerName, id, title ) => {
	let titlePath = 'ReadingLists';
	if ( ownerName ) {
		titlePath += `/${ownerName}`;
	}
	if ( id ) {
		titlePath += `/${id}`;
	}
	const titleWithName = title ? `${titlePath}/${encodeURIComponent( title )}` : titlePath;
	try {
		return (
			new mw.Title( titleWithName, -1 )
		).getUrl();
	} catch ( e ) {
		// Uncaught Error: Unable to parse title
		// e.g. Special:ReadingLists/1/<script>
		return (
			new mw.Title( titlePath, -1 )
		).getUrl();
	}
};

module.exports = {
	getReadingListUrl
};
