const getEnabledMessage = ( key, params ) => {
	// eslint-disable-next-line mediawiki/msg-doc
	const text = mw.msg( key, params );
	return text === '-' ? '' : text;
};

module.exports = {
	getEnabledMessage
};
