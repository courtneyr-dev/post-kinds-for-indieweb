/* eslint-disable no-console */
import { createServer } from 'node:http';
import { existsSync } from 'node:fs';
import { readFile } from 'node:fs/promises';
import { extname, join, normalize, resolve as resolvePath } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from '@playwright/test';
import { AxeBuilder } from '@axe-core/playwright';
import { WCAG_TAGS } from '../.storybook/a11y-rules.js';

// Defaults to `npm run build-storybook` output; the docs workflow passes
// docs/dist/storybook, the copy it publishes.
const ROOT = process.argv[ 2 ]
	? resolvePath( process.argv[ 2 ] )
	: fileURLToPath( new URL( '../storybook-static', import.meta.url ) );
const TYPES = {
	'.html': 'text/html',
	'.js': 'text/javascript',
	'.css': 'text/css',
	'.json': 'application/json',
	'.svg': 'image/svg+xml',
	'.woff2': 'font/woff2',
};

// Pre-existing violations in shipped components. Each entry must still occur,
// so a fix forces its removal here.
const KNOWN = [];

const known = ( story, rule, target ) =>
	KNOWN.find(
		( k ) => k.story === story && k.rule === rule && k.target.test( target )
	);

function serve() {
	const server = createServer( async ( req, res ) => {
		const path = normalize(
			decodeURIComponent(
				new URL( req.url, 'http://localhost' ).pathname
			)
		);
		const file = join(
			ROOT,
			path.endsWith( '/' ) ? `${ path }index.html` : path
		);
		try {
			const body = await readFile( file );
			res.writeHead( 200, {
				'content-type':
					TYPES[ extname( file ) ] ?? 'application/octet-stream',
			} );
			res.end( body );
		} catch {
			res.writeHead( 404 ).end();
		}
	} );
	return new Promise( ( resolve ) =>
		server.listen( 0, '127.0.0.1', () =>
			resolve( {
				server,
				url: `http://127.0.0.1:${ server.address().port }`,
			} )
		)
	);
}

if ( ! existsSync( join( ROOT, 'index.json' ) ) ) {
	throw new Error(
		`${ ROOT }/index.json is missing. Run \`npm run build-storybook\` first.`
	);
}

const index = JSON.parse(
	await readFile( join( ROOT, 'index.json' ), 'utf8' )
);
const stories = Object.values( index.entries ).filter(
	( e ) => e.type === 'story'
);
const { server, url } = await serve();
const browser = await chromium.launch( { channel: 'chrome' } );
let failures = 0;
const seenKnown = new Set();

const fail = ( storyId, reason, details = [] ) => {
	failures += 1;
	console.log( `FAIL  ${ storyId }  ${ reason }` );
	for ( const line of details ) {
		console.log( `        ${ line }` );
	}
};

try {
	const context = await browser.newContext();
	// Stories must not reach the network: abort anything off the local server.
	await context.route( '**/*', ( route ) => {
		const target = route.request().url();
		if ( target.startsWith( url ) || target.startsWith( 'data:' ) ) {
			return route.continue();
		}
		return route.abort();
	} );

	for ( const story of stories ) {
		const page = await context.newPage();
		const pageErrors = [];
		page.on( 'pageerror', ( error ) => pageErrors.push( error.message ) );
		// A throwing play function only logs console.error. The server has no
		// favicon, and Chrome requests one on the first page load.
		page.on( 'console', ( message ) => {
			if (
				message.type() === 'error' &&
				! message.location().url.endsWith( '/favicon.ico' )
			) {
				pageErrors.push( message.text() );
			}
		} );
		page.on( 'response', ( response ) => {
			if (
				response.status() >= 400 &&
				! response.url().endsWith( '/favicon.ico' )
			) {
				pageErrors.push(
					`HTTP ${ response.status() } ${ response.url() }`
				);
			}
		} );
		page.on( 'requestfailed', ( request ) => {
			if ( ! request.url().endsWith( '/favicon.ico' ) ) {
				pageErrors.push( `request blocked: ${ request.url() }` );
			}
		} );
		await page.goto(
			`${ url }/iframe.html?id=${ story.id }&viewMode=story`
		);
		const phase = await page
			.waitForFunction(
				() => {
					const current =
						window.__STORYBOOK_PREVIEW__?.currentRender?.phase;
					return (
						[ 'finished', 'errored', 'aborted' ].includes(
							current
						) && current
					);
				},
				null,
				{ timeout: 30000 }
			)
			.then( ( handle ) => handle.jsonValue() )
			.catch( () => 'timeout' );
		const threw = await page.$( 'body.sb-show-errordisplay' );
		if ( phase !== 'finished' || threw || pageErrors.length ) {
			fail( story.id, `story errored (phase "${ phase }")`, pageErrors );
			await page.close();
			continue;
		}

		const { violations } = await new AxeBuilder( { page } )
			.withTags( WCAG_TAGS )
			.analyze();
		// An async render error can land while axe runs or just after it.
		await page.waitForTimeout( 250 );
		const lateErrors = [ ...pageErrors ];
		const lateThrew = await page.$( 'body.sb-show-errordisplay' );
		await page.close();
		if ( lateErrors.length || lateThrew ) {
			fail( story.id, 'errored after render', lateErrors );
			continue;
		}

		const fresh = [];
		for ( const v of violations ) {
			for ( const node of v.nodes ) {
				const target = node.target.join( ' ' );
				const match = known( story.id, v.id, target );
				if ( match ) {
					seenKnown.add( match );
				} else {
					fresh.push( `${ v.id }: ${ target }  ${ v.help }` );
				}
			}
		}
		if ( fresh.length ) {
			fail( story.id, `${ fresh.length } axe violations`, fresh );
		} else {
			console.log( `pass  ${ story.id }` );
		}
	}

	for ( const k of KNOWN ) {
		if ( seenKnown.has( k ) ) {
			console.log(
				`known ${ k.story } ${ k.rule } ${ k.target.source }`
			);
		} else {
			fail(
				k.story,
				`known violation no longer occurs, remove it from KNOWN: ${ k.rule }`
			);
		}
	}
	console.log(
		`\n${ stories.length } stories, ${ failures } failures, ${
			seenKnown.size
		} known (axe tags: ${ WCAG_TAGS.join( ', ' ) })`
	);
} finally {
	await browser.close();
	server.close();
}

if ( failures > 0 ) {
	process.exitCode = 1;
}
