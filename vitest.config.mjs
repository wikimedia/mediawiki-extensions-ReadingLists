import MagicString from 'magic-string';
import { defineConfig } from 'vitest/config';
import { resolve } from 'node:path';
import vue from '@vitejs/plugin-vue';

// The plugins below edit through MagicString and return its source map, so that coverage and
// stack traces point at the original source lines instead of the transformed ones.

// MagicString counterpart of `code.replace( pattern, replacer )`. Matches run against the
// original code, so they don't see earlier edits. Returning `undefined` leaves the match as is.
function replaceAll( s, code, pattern, replacer ) {
	for ( const match of code.matchAll( pattern ) ) {
		const replacement = replacer( ...match );
		if ( replacement === undefined ) {
			continue;
		}
		const end = match.index + match[ 0 ].length;
		if ( replacement ) {
			s.overwrite( match.index, end, replacement );
		} else {
			s.remove( match.index, end );
		}
	}
}

function toResult( s, id ) {
	return { code: s.toString(), map: s.generateMap( { hires: true, source: id } ) };
}

function resolveModulePath( path ) {
	const match = /^ext\.readingLists\.(.+)$/.exec( path );
	return match ? resolve( import.meta.dirname, `resources/ext.readingLists.${ match[ 1 ] }/index.js` ) : path;
}

// Redirects `require('ext.readingLists.<name>')` to the actual index.js path.
function redirectResourceLoader( s, code ) {
	replaceAll(
		s, code,
		/require\s*\(\s*['"](ext\.readingLists\.[^'"]+)['"]\s*\)/g,
		( _match, path ) => `require( '${ resolveModulePath( path ) }' )`
	);
}

// Absolute JSON paths cannot be resolved by Vite's import analysis, so those requires are left
// alone. Relative JSON paths are fine: they go through `resolve.alias` like any other specifier,
// which is what allows a test to intercept them with `vi.doMock()`.
function skipHoist( path ) {
	return path.endsWith( '.json' ) && path.startsWith( '/' );
}

// Converts `const X = require(...)` and `const { A, B } = require(...)` to hoisted ESM imports.
// Removes the require statements from `s` and returns the collected imports separately so the
// caller can insert them at the correct position.
function hoistRequires( s, code ) {
	const imports = [];
	// Destructured: const { A, B } = require('path') → import { A, B } from 'path'.
	replaceAll(
		s, code,
		/const\s+\{\s*([^}]+?)\s*\}\s*=\s*require\s*\(\s*['"]([^'"]+)['"]\s*\)\s*;?/g,
		( _match, names, path ) => {
			if ( skipHoist( path ) ) {
				return;
			}
			const cleanNames = names.split( ',' ).map( ( n ) => n.trim() ).join( ', ' );
			imports.push( `import { ${ cleanNames } } from '${ resolveModulePath( path ) }';` );
			return '';
		}
	);
	// Default: const X = require('path') → import X from 'path'.
	replaceAll(
		s, code,
		/const\s+(\w+)\s*=\s*require\s*\(\s*['"]([^'"]+)['"]\s*\)\s*;?/g,
		( _match, varName, path ) => {
			if ( skipHoist( path ) ) {
				return;
			}
			imports.push( `import ${ varName } from '${ resolveModulePath( path ) }';` );
			return '';
		}
	);
	return imports;
}

// Transform plain JS resource files that contain ResourceLoader module names.
// Only activates when the file has a ResourceLoader require to avoid touching unrelated files.
const fixJsRequires = {
	name: 'fix-js-requires',
	enforce: 'pre',
	transform( code, id ) {
		if ( !id.includes( '/resources/' ) || !id.endsWith( '.js' ) ) {
			return;
		}
		const s = new MagicString( code );

		// Step 1: Redirect ResourceLoader module names to actual file paths.
		redirectResourceLoader( s, code );
		// Also transform resource files that directly require .vue components.
		// Those require() calls must become hoisted ESM imports so that
		// vi.mock() can intercept them through Vite's module pipeline.
		const hasVueRequire = /require\s*\(\s*['"][^'"]+\.vue['"]/.test( code );
		// "Wire-up" entry files (e.g. ext.readingLists.bookmark/index.js) require
		// only sibling resource modules by relative path and run side-effecting
		// top-level code. They still need their require() calls hoisted to ESM
		// imports so vi.doMock() can intercept them.
		const hasRequire = /\bconst\s+[\w{}\s,]+=\s*require\s*\(/.test( code );
		if ( !s.hasChanged() && !hasVueRequire && !hasRequire ) {
			return;
		}

		// Step 2: Convert `module.exports = { A, B }` to named exports plus a default
		//   export of the same object, as hoisted `import X from` relies on the default.
		//   `exports.foo()` calls go through that object, so `vi.spyOn()` on the
		//   default export also intercepts the module's internal calls.
		replaceAll(
			s, code,
			/module\.exports\s*=\s*(?:exports\s*=\s*)?\{\s*([\w,\s]+?)\s*\}\s*;?/g,
			( _match, exportsList ) => {
				const names = exportsList.split( ',' ).map( ( n ) => n.trim() ).filter( Boolean ).join( ', ' );
				return `const moduleExports = { ${ names } };\n` +
					`export default moduleExports;\nexport { ${ names } };`;
			}
		);
		replaceAll( s, code, /(?<![.\w])exports\.(?=\w)/g, () => 'moduleExports.' );

		// Step 3: Convert all `const X = require(...)` to hoisted ESM imports.
		const imports = hoistRequires( s, code );
		if ( imports.length ) {
			s.prepend( imports.join( '\n' ) + '\n' );
		}
		return toResult( s, id );
	}
};

// Pre-process Vue SFCs before @vitejs/plugin-vue to fix incompatibilities with
// the Node.js / Vitest ESM environment. MediaWiki Vue SFCs use CJS-style
// require() and module.exports which don't work in an ESM context:
//
// 1. Strip <style lang="less"> blocks: `less` is not installed, and MediaWiki
//    Less files referenced by @import don't exist in the test tree.
//
// 2. Rewrite `module.exports = exports = {...}` to `export default {...}`:
//    Vite/Vitest treats all JS as ESM, where module.exports is a read-only
//    shim. @vitejs/plugin-vue needs `export default` to detect the component.
//
// 3. Redirect ResourceLoader module names (e.g. `require('ext.readingLists.api')`)
//    to their actual file paths so Node.js's CJS require() can resolve them.
//
// `icons.json` and `config.json` need no rewriting here: hoisting them to ESM
// imports lets `resolve.alias` map them, which keeps them mockable from tests.
const fixVueSfc = {
	name: 'fix-vue-sfc',
	enforce: 'pre',
	transform( code, id ) {
		if ( !id.endsWith( '.vue' ) ) {
			return;
		}
		const s = new MagicString( code );
		// 1. Strip style blocks.
		replaceAll( s, code, /<style[\s\S]*?<\/style>/g, () => '' );
		// 2. Convert CJS component export to ESM.
		replaceAll( s, code, /module\.exports\s*=\s*exports\s*=/g, () => 'export default' );
		// 3. Redirect ResourceLoader module names to actual index.js paths.
		redirectResourceLoader( s, code );
		// 4. Hoist all `const X = require(...)` to ESM imports at the top of the
		//    <script> block so every load goes through Vite's module runner and shares
		//    the same registry as the test files – required for `stubApi()` to take effect.
		const imports = hoistRequires( s, code );
		const scriptTag = /<script[^>]*>/.exec( code );
		if ( imports.length && scriptTag ) {
			s.appendLeft( scriptTag.index + scriptTag[ 0 ].length, `\n${ imports.join( '\n' ) }` );
		}
		return toResult( s, id );
	}
};

export default defineConfig( {
	plugins: [ fixJsRequires, fixVueSfc, vue() ],

	// Vite-style module resolution (replaces Jest's `moduleNameMapper`).
	resolve: {
		alias: [
			{
				// Jest: `'^ext\\.readingLists\\.(.+)$':
				//  '<rootDir>/resources/ext.readingLists.$1/index.js'`
				find: /^ext\.readingLists\.(.+)$/,
				replacement: resolve( import.meta.dirname, 'resources/ext.readingLists.$1/index.js' )
			},
			{
				// Jest: `'config\\.json$': '<rootDir>/resources/config.json'`.
				// Full-string match (with optional leading path) so the replacement
				// discards the relative prefix instead of being appended to it.
				find: /^(.*\/)?config\.json$/,
				replacement: resolve( import.meta.dirname, 'resources/config.json' )
			},
			{
				// Jest: `'codex\\.js$': '@wikimedia/codex'`.
				// Full-string match (with optional leading path) so the replacement
				// discards the relative prefix and resolves to the npm package name.
				find: /^(.*\/)?codex\.js$/,
				replacement: '@wikimedia/codex'
			},
			{
				// Jest: `'icons\\.json$': '@wikimedia/codex-icons'`.
				// The file does not exist in the repo, so this always resolves to
				// the npm package. Full-string match, as for `config.json` above.
				find: /^(.*\/)?icons\.json$/,
				replacement: '@wikimedia/codex-icons'
			},
			{
				// Jest: `'^mediawiki\\.storage$'` → tests/jest/mediawiki.storage.mock.js.
				find: /^mediawiki\.storage$/,
				replacement: resolve( import.meta.dirname, 'tests/jest/mediawiki.storage.mock.js' )
			},
			{
				// Jest: `'^vue-router$': '<rootDir>/tests/jest/vueRouterStub.js'`.
				find: /^vue-router$/,
				replacement: resolve( import.meta.dirname, 'tests/jest/vueRouterStub.js' )
			}
		]
	},

	test: {
		// Jest: `testEnvironment: 'jsdom'`.
		environment: 'jsdom',

		// Jest: `verbose: true`.
		reporters: [ 'verbose' ],

		// Jest: globals like `describe`, `test`, `expect`, `vi` without imports.
		globals: true,

		// Jest: `clearMocks: true`.
		clearMocks: true,

		setupFiles: [ './vitest.setup.js' ],

		// Jest: `collectCoverageFrom`, `coverageDirectory`,
		//  `coveragePathIgnorePatterns`, `coverageThreshold`.
		coverage: {
			// Use Istanbul for equal output coverage instrumentation.
			provider: 'istanbul',
			reportsDirectory: 'coverage',

			// Jest emitted `[ 'clover', 'json', 'lcov', 'text' ]` by default, which
			// is not Vitest's default; set it explicitly so CI keeps finding
			// `coverage/lcov.info`.
			reporter: [ 'clover', 'json', 'lcov', 'text' ],

			// Jest: `collectCoverageFrom: [ 'resources/**/*.(js|vue)' ]`
			include: [ 'resources/**/*.{js,vue}' ],

			// Jest: `coveragePathIgnorePatterns`.
			exclude: [
				'node_modules/**',
				// Ignore the specific "wire up" files, as testing has side effects.
				'resources/ext.readingLists.bookmark/index.js',
				'resources/ext.readingLists.special/index.js'
			],

			// Jest: `coverageThreshold.global`.
			thresholds: {
				statements: 50,
				branches: 44,
				functions: 30,
				lines: 50
			}
		}
	}
} );
