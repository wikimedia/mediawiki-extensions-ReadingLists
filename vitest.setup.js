import { config } from '@vue/test-utils';
import i18n from './i18n/en.json';

function ApiMock() {
	this.get = vi.fn();
	this.post = vi.fn();
	this.postWithEditToken = vi.fn();
}

// Note: this relies on `unstubGlobals` staying off (the default). Setup files run
// once per test file, so enabling it would restore `mw` to undefined before every
// test but the first.
vi.stubGlobal( 'mw', {
	Api: ApiMock,
	config: {
		get: vi.fn(),
		set: vi.fn()
	},
	msg: vi.fn( ( key ) => i18n[ key ] || key ),
	message: vi.fn( ( key ) => ( {
		text: vi.fn( () => i18n[ key ] || key ),
		parse: vi.fn( () => i18n[ key ] || key ),
		exists: vi.fn( () => true )
	} ) ),
	language: {
		convertNumber: ( num ) => num.toLocaleString()
	},
	user: {
		getName: vi.fn(),
		options: {
			get: vi.fn()
		}
	},
	util: {
		throttle: ( fn ) => fn,
		getUrl: vi.fn(),
		debounce: vi.fn( ( fn ) => fn )
	},
	storage: {
		get: vi.fn(),
		set: vi.fn()
	},
	notify: vi.fn( () => Promise.resolve() ),
	hook: vi.fn().mockReturnValue( {
		add: vi.fn(),
		fire: vi.fn()
	} ),
	loader: {
		using: vi.fn( () => Promise.resolve() )
	},
	requestIdleCallback: vi.fn( ( fn ) => fn() ),
	log: {
		error: vi.fn()
	}
} );

// Register the MediaWiki `v-i18n-html` directive used by Vue SFCs so mounted
// components resolve message content the same way they do in production.
config.global.directives = {
	'i18n-html': ( el, binding ) => {
		el.innerHTML = mw.msg( binding.arg, binding.value );
	}
};

config.global.mocks = {
	$i18n: ( str ) => ( {
		text: () => str,
		parse: () => str
	} )
};
