const i18n = require( './i18n/en.json' );

mw = {
	Api: function () {},
	msg: ( key ) => i18n[ key ],
};
