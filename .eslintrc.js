/**
 * ESLint configuration.
 *
 * Extends @wordpress/scripts default config and registers the JSX type as a
 * known global so JSDoc references like `@returns {JSX.Element}` and
 * `@param {JSX.Element} children` validate cleanly. JSX is an implicit
 * TypeScript namespace that eslint-plugin-jsdoc doesn't auto-detect.
 */
module.exports = {
	extends: [ require.resolve( '@wordpress/scripts/config/.eslintrc.js' ) ],
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
};
