/**
 * Jest configuration for Post Kinds for IndieWeb
 *
 * @see https://jestjs.io/docs/configuration
 */

const defaultConfig = require( '@wordpress/scripts/config/jest-unit.config' );

module.exports = {
	...defaultConfig,
	testEnvironment: 'jsdom',
	roots: [ '<rootDir>/src/', '<rootDir>/tests/js/' ],
	testMatch: [
		'**/__tests__/**/*.[jt]s?(x)',
		'**/?(*.)+(spec|test).[jt]s?(x)',
	],
	moduleNameMapper: {
		...defaultConfig.moduleNameMapper,
		'^@/(.*)$': '<rootDir>/src/$1',
	},
	setupFilesAfterEnv: [ '<rootDir>/tests/js/setup.js' ],
	collectCoverageFrom: [
		'src/**/*.{js,jsx,ts,tsx}',
		'!src/**/*.d.ts',
		'!src/**/index.{js,ts}',
		'!**/node_modules/**',
	],
	// Coverage is collected and reported but not gated. Test coverage on
	// the JS side is being built up incrementally — the existing components
	// are mostly Block Editor UI that needs a wp-env-style integration
	// runner rather than jsdom. Tighten this threshold as the unit test
	// suite grows.
	coverageThreshold: {
		global: {
			branches: 0,
			functions: 0,
			lines: 0,
			statements: 0,
		},
	},
	coverageReporters: [ 'text', 'lcov', 'html' ],
	coverageDirectory: 'coverage/js',
	testPathIgnorePatterns: [ '/node_modules/', '/build/', '/vendor/' ],
	transformIgnorePatterns: [
		'/node_modules/(?!(@wordpress|parsel-js|is-plain-obj|dot-prop)/)',
	],
	globals: {
		wp: {},
		ajaxurl: '/wp-admin/admin-ajax.php',
	},
};
