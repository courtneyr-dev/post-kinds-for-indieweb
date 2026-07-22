#!/usr/bin/env node
/* eslint-disable no-console, import/no-extraneous-dependencies -- CLI tool: progress output goes to the terminal, and `playwright` is resolved opportunistically with an `@playwright/test` fallback. */
/**
 * Capture documentation screenshots for Post Kinds for IndieWeb.
 *
 * Boots a disposable WordPress via WordPress Playground CLI (no Docker needed),
 * mounts this plugin, seeds demo content from blueprints/docs-screenshots.json,
 * and captures the screens listed in docs/src/content/docs/screenshots.md into
 * docs/src/assets/screenshots/ (the directory the docs pages read from).
 * Requires a Playground WordPress of 7.0+ (the CLI's "latest" satisfies this).
 *
 * Prerequisites:
 *   - Node.js 18+
 *   - npm install (installs Playwright from devDependencies)
 *   - npx playwright install chromium (once, to download the browser)
 *
 * Usage:
 *   node scripts/capture-docs-screenshots.js
 *
 * Environment variables:
 *   WP_BASE_URL      Capture against an already-running WordPress instead of
 *                    launching Playground (must be logged-in-accessible or a
 *                    Playground --login server seeded with the docs blueprint).
 *                    No credentials are stored here.
 *   PLAYGROUND_PORT  Port for the disposable Playground server (default 9400).
 */

const { spawn } = require( 'child_process' );
const fs = require( 'fs' );
const path = require( 'path' );

const REPO_ROOT = path.resolve( __dirname, '..' );
const OUT_DIR = path.join( REPO_ROOT, 'docs', 'src', 'assets', 'screenshots' );
const BLUEPRINT = path.join( REPO_ROOT, 'blueprints', 'docs-screenshots.json' );
const PORT = process.env.PLAYGROUND_PORT || '9400';
const EXTERNAL_URL = process.env.WP_BASE_URL || '';
const BASE = EXTERNAL_URL || `http://127.0.0.1:${ PORT }`;

// Deterministic sample values, mirroring tests/js/sample-values.js (type rules
// before name patterns — do not reorder). Docs-only deltas are applied per
// block below: enum attributes need valid values, and image attributes need a
// URL that actually renders (the seeded placeholder cover in wp-uploads).
function sampleFor( attr, def ) {
	const type = Array.isArray( def.type )
		? def.type[ 0 ]
		: def.type || 'string';
	if ( attr === 'layout' ) {
		return def.default ?? 'horizontal';
	}
	if ( type === 'boolean' ) {
		return true;
	}
	if ( type === 'number' || type === 'integer' ) {
		return 4;
	}
	if ( /(url|photo|cover|image)$/i.test( attr ) ) {
		return 'https://example.com/sample-' + attr.toLowerCase();
	}
	if ( /(At|Date)$/.test( attr ) || attr === 'publishDate' ) {
		return '2026-07-04';
	}
	return `Sample ${ attr } value`;
}

function sampleAttrs( blockJsonPath, overrides = {}, omit = [] ) {
	const def = JSON.parse( fs.readFileSync( blockJsonPath, 'utf8' ) );
	const attrs = {};
	for ( const [ attr, attrDef ] of Object.entries( def.attributes ) ) {
		if ( omit.includes( attr ) ) {
			continue;
		}
		attrs[ attr ] = sampleFor( attr, attrDef );
	}
	return { ...attrs, ...overrides };
}

function resolveChromium() {
	try {
		return require( 'playwright' ).chromium;
	} catch {
		try {
			return require( '@playwright/test' ).chromium;
		} catch {
			console.error(
				'Playwright is not installed. Run `npm install` in the repo root, then `npx playwright install chromium`.'
			);
			process.exit( 1 );
		}
	}
}

async function waitForServer( url, timeoutMs ) {
	const deadline = Date.now() + timeoutMs;
	while ( Date.now() < deadline ) {
		try {
			const res = await fetch( url, { redirect: 'manual' } );
			if ( res.status > 0 && res.status < 500 ) {
				return;
			}
		} catch {
			// Not up yet.
		}
		await new Promise( ( r ) => setTimeout( r, 2000 ) );
	}
	throw new Error(
		`WordPress did not become reachable at ${ url } within ${
			timeoutMs / 1000
		}s.`
	);
}

function launchPlayground() {
	console.log(
		`Starting WordPress Playground on port ${ PORT } (downloads WordPress on first run)...`
	);
	const child = spawn(
		'npx',
		[
			'--yes',
			'@wp-playground/cli@latest',
			'server',
			'--auto-mount',
			REPO_ROOT,
			'--blueprint',
			BLUEPRINT,
			'--login',
			'--port',
			PORT,
		],
		{ stdio: [ 'ignore', 'pipe', 'pipe' ] }
	);
	child.stdout.on( 'data', ( d ) =>
		process.stdout.write( `[playground] ${ d }` )
	);
	child.stderr.on( 'data', ( d ) =>
		process.stderr.write( `[playground] ${ d }` )
	);
	child.on( 'exit', ( code ) => {
		if ( code && code !== 0 && ! shuttingDown ) {
			console.error(
				`Playground exited unexpectedly with code ${ code }.`
			);
			process.exit( 1 );
		}
	} );
	return child;
}

let shuttingDown = false;

// Open a fresh post editor, dismiss the welcome guide, and wait for the canvas.
async function openNewPost( page ) {
	await page.goto( BASE + '/wp-admin/post-new.php', {
		waitUntil: 'domcontentloaded',
	} );
	await page
		.waitForFunction(
			() => window.wp && window.wp.data && window.wp.blocks,
			{ timeout: 30000 }
		)
		.catch( () => {} );
	await page.evaluate( () => {
		window.wp.data
			.dispatch( 'core/preferences' )
			.set( 'core', 'welcomeGuide', false );
		window.wp.data
			.dispatch( 'core/preferences' )
			.set( 'core/edit-post', 'welcomeGuide', false );
		window.wp.data
			.dispatch( 'core/preferences' )
			.set( 'core', 'fullscreenMode', false );
	} );
	const editor = page.frameLocator( 'iframe[name="editor-canvas"]' );
	await editor.locator( '.is-root-container' ).waitFor( { timeout: 30000 } );
	// Close the welcome guide or any other modal (e.g. a pattern chooser) that
	// could cover the editor; the guide can open a moment after the canvas is
	// ready, so retry a few times.
	for ( let i = 0; i < 4; i++ ) {
		await page.waitForTimeout( 1200 );
		const closeButton = page.locator(
			'.components-modal__header button[aria-label="Close"]'
		);
		if ( await closeButton.count() ) {
			await closeButton
				.first()
				.click()
				.catch( () => {} );
			await page.waitForTimeout( 500 );
		} else if ( i > 0 ) {
			break;
		}
	}
	return editor;
}

// Insert a block with attributes into the open editor and select it with the
// block inspector sidebar open (matches the style of the existing shots).
async function insertBlock( page, blockName, attrs, title ) {
	await page.evaluate(
		( { name, blockAttrs, postTitle } ) => {
			if ( postTitle ) {
				window.wp.data
					.dispatch( 'core/editor' )
					.editPost( { title: postTitle } );
			}
			const block = window.wp.blocks.createBlock( name, blockAttrs );
			window.wp.data
				.dispatch( 'core/block-editor' )
				.insertBlocks( block );
			const openSidebar =
				window.wp.data.dispatch( 'core/edit-post' )
					?.openGeneralSidebar ||
				window.wp.data.dispatch( 'core/editor' )?.openGeneralSidebar;
			if ( openSidebar ) {
				openSidebar( 'edit-post/block' );
			}
		},
		{ name: blockName, blockAttrs: attrs, postTitle: title }
	);
	const editor = page.frameLocator( 'iframe[name="editor-canvas"]' );
	const block = editor.locator( `[data-type="${ blockName }"]` );
	await block.waitFor( { timeout: 15000 } );
	// Wait for any loading spinners inside the block to settle.
	await block
		.locator( '.components-spinner, .checkin-loading' )
		.waitFor( { state: 'detached', timeout: 10000 } )
		.catch( () => {} );
	// Wait for every image inside the block to finish loading.
	await block
		.evaluate( ( el ) =>
			Promise.race( [
				Promise.all(
					Array.from( el.querySelectorAll( 'img' ) ).map(
						( img ) =>
							img.complete ||
							new Promise( ( resolve ) => {
								img.addEventListener( 'load', resolve );
								img.addEventListener( 'error', resolve );
							} )
					)
				),
				new Promise( ( resolve ) => setTimeout( resolve, 15000 ) ),
			] )
		)
		.catch( () => {} );
	return block;
}

( async () => {
	fs.mkdirSync( OUT_DIR, { recursive: true } );
	const chromium = resolveChromium();

	let playground = null;
	if ( ! EXTERNAL_URL ) {
		playground = launchPlayground();
	}

	try {
		await waitForServer( BASE + '/', 300000 );
		console.log( `WordPress is up at ${ BASE }` );

		const browser = await chromium.launch();
		const ctx = await browser.newContext( {
			viewport: { width: 1280, height: 800 },
			deviceScaleFactor: 2,
		} );
		const page = await ctx.newPage();

		// Prime the logged-in admin session (Playground --login authenticates the first visit).
		await page.goto( BASE + '/wp-admin/', { waitUntil: 'networkidle' } );
		if (
			! /wp-admin/.test( page.url() ) ||
			/wp-login/.test( page.url() )
		) {
			throw new Error(
				`Could not reach a logged-in wp-admin at ${ BASE }. If you passed WP_BASE_URL, make sure the session does not require interactive login.`
			);
		}

		const shoot = async ( file, options = {} ) => {
			const target = path.join( OUT_DIR, file );
			await page.screenshot( { path: target, ...options } );
			console.log( `captured ${ path.relative( REPO_ROOT, target ) }` );
		};

		// 1. Reactions → Settings (General tab).
		await page.goto(
			BASE +
				'/wp-admin/admin.php?page=post-kinds-for-indieweb-in-block-themes',
			{
				waitUntil: 'networkidle',
			}
		);
		await shoot( 'admin-general-settings.png' );

		// 2. Reactions → Settings, Integrations tab (IndieBlocks + Webmention active).
		await page.goto(
			BASE +
				'/wp-admin/admin.php?page=post-kinds-for-indieweb-in-block-themes&tab=integrations',
			{ waitUntil: 'networkidle' }
		);
		await page
			.locator( '.integration-card .status-badge' )
			.first()
			.waitFor( { timeout: 15000 } );
		await shoot( 'admin-integrations-tab.png' );

		// 3. Reactions → API Connections.
		await page.goto(
			BASE + '/wp-admin/admin.php?page=post-kinds-indieweb-apis',
			{ waitUntil: 'networkidle' }
		);
		await shoot( 'admin-api-connections.png' );

		// 4. Reactions → Import.
		await page.goto(
			BASE + '/wp-admin/admin.php?page=post-kinds-indieweb-import',
			{ waitUntil: 'networkidle' }
		);
		await shoot( 'admin-import-page.png' );

		// 5. Reactions → Webhooks.
		await page.goto(
			BASE + '/wp-admin/admin.php?page=post-kinds-indieweb-webhooks',
			{ waitUntil: 'networkidle' }
		);
		await shoot( 'admin-webhooks-page.png' );

		// 6. Reactions → Quick Post with the media search box and manual entry
		// form visible. Live search results are not captured: every admin-side
		// lookup either needs an API key or is currently broken keyless (the
		// admin book lookup passes a result limit where Open Library expects an
		// author, so it always returns zero results, and MusicBrainz cannot be
		// enabled from any settings UI).
		await page.goto(
			BASE + '/wp-admin/admin.php?page=post-kinds-indieweb-quick-post',
			{
				waitUntil: 'networkidle',
			}
		);
		await page.locator( '.kind-tab[data-kind="read"]' ).click();
		await page
			.locator( '.quick-form[data-kind="read"] .quick-post-form' )
			.waitFor( { timeout: 15000 } );
		await page.waitForTimeout( 500 );
		await shoot( 'admin-quick-post.png' );

		// 7. Block editor with the Post Kind panel in the document sidebar.
		await openNewPost( page );
		const kindPanel = page
			.getByRole( 'button', { name: /post kind/i } )
			.first();
		if ( await kindPanel.count() ) {
			await kindPanel.scrollIntoViewIfNeeded().catch( () => {} );
			const expanded = await kindPanel
				.getAttribute( 'aria-expanded' )
				.catch( () => null );
			if ( expanded === 'false' ) {
				await kindPanel.click().catch( () => {} );
			}
			await page.waitForTimeout( 1500 );
		}
		await shoot( 'editor-post-kind-panel.png' );

		// 8. RSVP Card in the editor with the response selector visible.
		await openNewPost( page );
		await insertBlock(
			page,
			'post-kinds-indieweb/rsvp-card',
			sampleAttrs(
				path.join( REPO_ROOT, 'src/blocks/rsvp-card/block.json' ),
				{
					rsvpStatus: 'yes',
					eventStart: '2026-08-15T18:00',
					eventEnd: '2026-08-15T21:00',
				},
				// The sample URL renders a broken image; the card reads fine without one.
				[ 'eventImage', 'eventImageAlt' ]
			),
			'Sample RSVP'
		);
		await page.waitForTimeout( 1000 );
		await shoot( 'editor-rsvp-card.png' );

		// 9. Play Card in the editor with cover and platform fields populated.
		await openNewPost( page );
		await insertBlock(
			page,
			'post-kinds-indieweb/play-card',
			sampleAttrs(
				path.join( REPO_ROOT, 'src/blocks/play-card/block.json' ),
				{
					status: 'playing',
					cover: '/wp-content/uploads/pkiw-docs-game-cover.svg',
					coverAlt: 'Sample game cover art',
				}
			),
			'Sample play session'
		);
		await page.waitForTimeout( 1000 );
		await shoot( 'editor-play-card.png' );

		// 10. Star Rating block with a half-star rating selected.
		await openNewPost( page );
		await insertBlock(
			page,
			'post-kinds-indieweb/star-rating',
			sampleAttrs(
				path.join( REPO_ROOT, 'src/blocks/star-rating/block.json' ),
				{
					rating: 3.5,
					maxRating: 5,
					allowHalf: true,
					label: 'Rating',
					size: 'medium',
					style: 'stars',
				}
			),
			'Sample rating'
		);
		await page.waitForTimeout( 1000 );
		await shoot( 'editor-star-rating.png' );

		// 11. Media Lookup block with live search results (Open Library needs no
		// API key for book search).
		await openNewPost( page );
		await insertBlock(
			page,
			'post-kinds-indieweb/media-lookup',
			{},
			'Sample media lookup'
		);
		const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
		const lookup = canvas.locator(
			'[data-type="post-kinds-indieweb/media-lookup"]'
		);
		await lookup
			.locator( '.post-kinds-media-search input[type="text"]' )
			.fill( 'The Hobbit' );
		await lookup
			.locator( '.post-kinds-media-search .search-input-group button' )
			.click();
		await lookup
			.locator( '.search-results .search-result-item' )
			.first()
			.waitFor( { timeout: 45000 } );
		await page.waitForTimeout( 2500 ); // Let result cover thumbnails load.
		await shoot( 'editor-media-lookup.png' );

		// 12. Frontend: three published check-ins at each privacy level.
		await page.goto( BASE + '/checkin-privacy-levels/', {
			waitUntil: 'networkidle',
		} );
		// The map embeds are lazy-loaded cross-origin iframes: they only load
		// and paint while genuinely inside the viewport, so grow the viewport
		// to the full page height instead of using a stitched fullPage shot.
		const pageHeight = await page.evaluate(
			() => document.body.scrollHeight
		);
		await page.setViewportSize( {
			width: 1280,
			height: Math.min( pageHeight, 2400 ),
		} );
		await page.waitForTimeout( 12000 ); // Let both OpenStreetMap embeds finish painting.
		await shoot( 'frontend-checkin-privacy-levels.png' );
		await page.setViewportSize( { width: 1280, height: 800 } );

		// 13. Frontend: Check-in Dashboard — stats + grid, fed by
		// kind=checkin posts whose card attrs Card_Meta_Sync mirrors into
		// _pkiw_* meta. (Grid only: the map view needs Leaflet, which is
		// not shipped yet — see assets/vendor references in render.php.)
		await page.goto( BASE + '/checkin-dashboard/', {
			waitUntil: 'networkidle',
		} );
		// An empty grid means the dashboard query regressed — fail the
		// capture loudly instead of shooting the empty state.
		await page
			.locator( '.checkin-card' )
			.first()
			.waitFor( { timeout: 15000 } );
		await page.waitForTimeout( 1000 );
		await shoot( 'frontend-checkin-dashboard.png' );

		await browser.close();
		console.log(
			`Done. Screenshots are in ${ path.relative( REPO_ROOT, OUT_DIR ) }/`
		);
	} finally {
		if ( playground ) {
			shuttingDown = true;
			playground.kill( 'SIGTERM' );
		}
	}
} )().catch( ( e ) => {
	console.error( e.message || e );
	process.exit( 1 );
} );
