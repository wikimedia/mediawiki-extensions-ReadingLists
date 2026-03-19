const i18n = require( './i18n/en.json' );

function ApiMock() {
	this.get = jest.fn();
	this.post = jest.fn();
	this.postWithEditToken = jest.fn();
}

const mw = {
	Api: ApiMock,
	config: {
		get: jest.fn(),
		set: jest.fn()
	},
	msg: jest.fn( ( key ) => i18n[ key ] || key ),
	message: jest.fn( ( key ) => ( {
		text: jest.fn( () => i18n[ key ] || key ),
		parse: jest.fn( () => i18n[ key ] || key )
	} ) ),
	language: {
		convertNumber: ( num ) => num.toLocaleString()
	},
	user: {
		getName: jest.fn()
	},
	util: {
		getUrl: jest.fn()
	},
	storage: {
		get: jest.fn(),
		set: jest.fn()
	},
	notify: jest.fn(),
	hook: jest.fn().mockReturnValue( {
		add: jest.fn(),
		fire: jest.fn()
	} ),
	loader: {
		using: jest.fn( () => Promise.resolve() ),
		require: jest.fn( ( moduleName ) => require( moduleName ) )
	},
	requestIdleCallback: jest.fn( ( fn ) => fn() ),
	log: {
		error: jest.fn()
	}
};

global.mw = mw;
