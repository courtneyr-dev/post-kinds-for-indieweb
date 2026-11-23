// Link check over the built site. Internal links always; external links
// too unless SKIP_EXTERNAL=1 (CI skips externals to stay deterministic).
// The docs workflow builds the root Storybook into dist/storybook/. Nothing in
// the docs links to its two entry pages, so seed both; CI always does.
import { existsSync } from 'node:fs';
import { LinkChecker } from 'linkinator';
import { startPreview } from './preview-server.mjs';

const { base, stop } = await startPreview( 4326 );
const skipExternal = process.env.SKIP_EXTERNAL === '1';
const storybook =
	process.env.CI === 'true' ||
	existsSync( new URL( '../dist/storybook/index.html', import.meta.url ) );

try {
	const checker = new LinkChecker();
	const result = await checker.check( {
		path: [
			base,
			...( storybook
				? [ `${ base }storybook/`, `${ base }storybook/iframe.html` ]
				: [] ),
		],
		recurse: true,
		checkCss: true,
		...( skipExternal && {
			linksToSkip: async ( link ) =>
				! link.startsWith( 'http://localhost' ),
		} ),
	} );
	const broken = result.links.filter( ( l ) => l.state === 'BROKEN' );
	console.log(
		`Checked ${ result.links.length } links; ${ broken.length } broken.`
	);
	if ( storybook ) {
		const under = result.links.filter( ( l ) =>
			l.url.startsWith( `${ base }storybook/` )
		);
		console.log( `${ under.length } of them under ${ base }storybook/.` );
	}
	for ( const l of broken ) {
		console.error( `BROKEN ${ l.status } ${ l.url } (on ${ l.parent })` );
	}
	stop();
	process.exit( broken.length ? 1 : 0 );
} catch ( e ) {
	console.error( e );
	stop();
	process.exit( 1 );
}
