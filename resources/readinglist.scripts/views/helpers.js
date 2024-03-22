const getEnabledMessage = ( key, params ) => {
	// eslint-disable-next-line mediawiki/msg-doc
	const text = mw.message( key, params ).parse();
	return text === '-' ? '' : text;
};

module.exports = {
	getEnabledMessage
};
