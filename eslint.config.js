/**
 * ESLint configuration (flat config).
 *
 * wp-scripts 32+ lints with ESLint flat config and no longer reads
 * .eslintrc.js. Extends the wp-scripts default config, then layers in
 * project globals and JSDoc types that describe the runtime environment.
 */
const wpScriptsConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	...wpScriptsConfig,

	// Jest globals for the shared test setup. The default config only maps
	// the test-unit preset onto *.test.js and __tests__ files, which misses
	// tests/js/setup.js.
	{
		files: [ 'tests/js/**/*.js' ],
		languageOptions: {
			globals: {
				jest: 'readonly',
				describe: 'readonly',
				it: 'readonly',
				test: 'readonly',
				expect: 'readonly',
				beforeEach: 'readonly',
				afterEach: 'readonly',
				beforeAll: 'readonly',
				afterAll: 'readonly',
			},
		},
	},

	{
		rules: {
			'jsdoc/no-undefined-types': [
				'error',
				{
					// Browser-global and TypeScript-namespace types that
					// eslint-plugin-jsdoc doesn't auto-detect. JSX is an
					// implicit TypeScript namespace used in @returns
					// annotations like `@returns {JSX.Element}`.
					definedTypes: [
						'JSX',
						'jQuery',
						'KeyboardEvent',
						'MouseEvent',
						'CustomEvent',
						'Event',
						'HTMLElement',
						'Element',
					],
				},
			],
		},
	},

	// Non-bundled scripts enqueued on admin and front-end pages. These run
	// in the browser alongside globals provided by wp_localize_script()
	// (postKindsIndieWeb, reactionsCheckinDashboard) and enqueued libraries
	// (jQuery, Leaflet's L).
	{
		files: [ 'admin/js/**/*.js', 'assets/js/**/*.js' ],
		languageOptions: {
			globals: {
				alert: 'readonly',
				confirm: 'readonly',
				location: 'readonly',
				jQuery: 'readonly',
				L: 'readonly',
				postKindsIndieWeb: 'readonly',
				reactionsCheckinDashboard: 'readonly',
			},
		},
	},
];
