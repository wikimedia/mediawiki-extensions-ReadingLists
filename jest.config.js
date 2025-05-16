// For a detailed explanation regarding each configuration property, visit:
// https://jestjs.io/docs/en/configuration.html

module.exports = {
	testEnvironmentOptions: {
		customExportConditions: [ 'node', 'node-addons' ]
	},

	transform: {
		'^.+\\.vue$': '<rootDir>/node_modules/@vue/vue3-jest'
	},

	// Automatically clear mock calls and instances between every test
	clearMocks: true,

	// Indicates whether the coverage information should be collected while executing the test
	collectCoverage: true,

	// An array of glob patterns indicating a set of files fo
	// which coverage information should be collected
	collectCoverageFrom: [
		'resources/**/*.(js|vue)'
	],

	// The directory where Jest should output its coverage files
	coverageDirectory: 'coverage',

	// An array of regexp pattern strings used to skip coverage collection
	coveragePathIgnorePatterns: [
		'/node_modules/',
		// We do not typically test coverage on wire up code
		// since it has side effects.
		'/resources/ext.readingLists.bookmark/index.js',
		'/resources/ext.readingLists.special/index.js'
	],

	// An object that configures minimum threshold enforcement for coverage results
	coverageThreshold: {
		global: {
			branches: 19,
			functions: 32,
			lines: 28,
			statements: 29
		}
	},

	// A set of global variables that need to be available in all test environments
	globals: {
		'vue-jest': {
			babelConfig: false,
			hideStyleWarn: true,
			experimentalCSSCompile: true
		}
	},

	// An array of file extensions your modules use
	moduleFileExtensions: [
		'js',
		'json',
		'vue'
	],

	// The paths to modules that run some code to configure or
	// set up the testing environment before each test
	setupFiles: [
		'./jest.setup.js'
	],

	testEnvironment: 'jsdom',

	moduleNameMapper: {
		"^ext\.readingLists\.(.*)$": "<rootDir>/resources/ext.readingLists.$1/index.js"
	},
};
