/**
 * Reactions for IndieWeb - Webpack Configuration
 *
 * Extends the default @wordpress/scripts webpack config.
 *
 * @package ReactionsForIndieWeb
 * @since   1.0.0
 */

const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		index: path.resolve( process.cwd(), 'src/editor', 'index.js' ),
		blocks: path.resolve( process.cwd(), 'src/blocks', 'index.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( process.cwd(), 'build' ),
	},
};
