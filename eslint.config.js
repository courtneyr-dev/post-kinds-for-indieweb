/**
 * ESLint configuration (flat config, ESLint 9 / @wordpress/scripts 32+).
 *
 * Extends the @wordpress/scripts default flat config and registers the JSX
 * type as a known global so JSDoc references like `@returns {JSX.Element}`
 * and `@param {JSX.Element} children` validate cleanly. JSX is an implicit
 * TypeScript namespace that eslint-plugin-jsdoc doesn't auto-detect.
 */

const wpScriptsConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	...wpScriptsConfig,
	{
		settings: {
			jsdoc: {
				preferredTypes: {
					'JSX.Element': 'JSX.Element',
				},
			},
		},
		rules: {
			'jsdoc/no-undefined-types': [
				'error',
				{
					// Browser-global and TypeScript-namespace types that
					// eslint-plugin-jsdoc doesn't auto-detect.
					definedTypes: [
						'JSX',
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
];
