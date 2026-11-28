/**
 * Post Kinds for IndieWeb in Block Themes - Webpack Configuration
 *
 * Extends the default `@wordpress/scripts` webpack config.
 *
 * @package
 * @since   1.0.0
 */

const [
	scriptConfig,
	moduleConfig,
] = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );
const buildPath = path.resolve( process.cwd(), 'build' );

module.exports = [
	{
		...scriptConfig,
		name: 'scripts',
		entry: {
			index: path.resolve( process.cwd(), 'src/editor', 'index.js' ),
			blocks: path.resolve( process.cwd(), 'src/blocks', 'index.js' ),
			// Every block editor, Site Editor included; index is post-only.
			bindings: path.resolve(
				process.cwd(),
				'src/editor/bindings',
				'kind-meta-source.js'
			),
		},
		output: {
			...scriptConfig.output,
			path: buildPath,
			clean: { keep: /^(fonts|images)\// },
		},
	},
	{
		...moduleConfig,
		name: 'modules',
		dependencies: [ 'scripts' ],
		output: { ...moduleConfig.output, path: buildPath },
	},
];
