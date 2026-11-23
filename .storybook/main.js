import { transformWithOxc } from 'vite';

// wp-scripts lets src/ keep JSX in .js files. Vite infers the parser from the
// extension, so parse those files as JSX first.
const jsxInJs = {
	name: 'pkiw-jsx-in-js',
	enforce: 'pre',
	transform( code, id ) {
		if ( ! /\.js$/.test( id ) || id.includes( '/node_modules/' ) ) {
			return null;
		}
		return transformWithOxc( code, id, {
			lang: 'jsx',
			jsx: { runtime: 'automatic' },
		} );
	},
};

/** @type {import('@storybook/react-vite').StorybookConfig} */
export default {
	framework: '@storybook/react-vite',
	stories: [ '../stories/**/*.stories.js' ],
	addons: [ '@storybook/addon-a11y' ],
	core: { disableTelemetry: true },
	viteFinal: ( config ) => ( {
		...config,
		plugins: [ jsxInJs, ...( config.plugins ?? [] ) ],
		// `storybook dev` pre-bundles dependencies without the plugin above.
		optimizeDeps: {
			...config.optimizeDeps,
			include: [
				...( config.optimizeDeps?.include ?? [] ),
				'react',
				'react-dom',
				'react/jsx-runtime',
			],
			rolldownOptions: {
				...config.optimizeDeps?.rolldownOptions,
				moduleTypes: { '.js': 'jsx' },
			},
		},
	} ),
};
