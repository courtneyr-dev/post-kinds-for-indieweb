/**
 * Visual regression tests for block appearance.
 *
 * Matrix-driven: for every block in field-matrix.json, insert it
 * programmatically with fully-populated sample attributes (same insert
 * pattern as tests/e2e/block-field-matrix.spec.js) and screenshot the block
 * in the editor canvas. Baselines are committed under
 * tests/e2e/__screenshots__/ (chromium only — see project note below).
 *
 * Run `npm run test:visual` to (re)generate baselines after an intentional
 * appearance change.
 */

const { test, expect } = require( '@playwright/test' );
const matrix = require( '../phpunit/fixtures/field-matrix.json' );

const BASE = process.env.WP_BASE_URL || 'http://localhost:8888';

// sampleFor duplicated here intentionally (specs run without bundling); MUST
// match tests/js/sample-values.js rule order EXACTLY (type before name
// patterns — Task 1 review finding). Do not reorder.
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

// media-lookup's selectedItem is typed "object" — the sampler has no object
// rule (locked rule order), so give it a real representative object instead
// of the flat string every other attribute gets. Same shape used by
// tests/js/field-matrix-static.test.js and block-field-matrix.spec.js.
const SELECTED_ITEM_SAMPLE = {
	title: 'Sample item title',
	author: 'Sample item author',
	cover: 'https://example.com/sample-item-cover',
	description: 'Sample item description',
	year: 1999,
	url: 'https://example.com/sample-item-url',
	id: 'sample-item-id',
};

test.slow();

test.describe( 'Visual Regression', () => {
	test.beforeEach( async ( { page } ) => {
		// Login to WordPress admin
		await page.goto( '/wp-login.php' );
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass', 'password' );
		await page.click( '#wp-submit' );
		await page.waitForURL( '**/wp-admin/**' );
	} );

	// Blocks whose editor preview renders live query results can't be
	// pixel-snapshotted: checkins-feed lists whatever posts exist in the
	// DB (count varies with which specs ran first) and shows each post's
	// creation date, which is always the run date on a fresh CI database.
	// Behavioral coverage lives in block-field-matrix.spec.js.
	const QUERY_DRIVEN_EXCLUSIONS = [ 'post-kinds-indieweb/checkins-feed' ];

	for ( const [ name, def ] of Object.entries( matrix ) ) {
		if ( QUERY_DRIVEN_EXCLUSIONS.includes( name ) ) {
			continue;
		}
		const slug = name.replace( 'post-kinds-indieweb/', '' );

		test( `${ slug } block appearance (populated)`, async ( { page } ) => {
			await page.goto( `${ BASE }/wp-admin/post-new.php` );
			// Dismiss welcome guide via preference (never click-race the modal).
			await page.evaluate( () =>
				window.wp.data
					.dispatch( 'core/preferences' )
					.set( 'core', 'welcomeGuide', false )
			);

			const editor = page.frameLocator( 'iframe[name="editor-canvas"]' );
			await editor
				.locator( '.is-root-container' )
				.waitFor( { timeout: 30000 } );

			const attrs = Object.fromEntries(
				Object.entries( def.attributes ).map( ( [ attr, attrDef ] ) => [
					attr,
					attr === 'selectedItem'
						? SELECTED_ITEM_SAMPLE
						: sampleFor( attr, attrDef ),
				] )
			);

			await page.evaluate(
				( { blockName, blockAttrs } ) => {
					const block = window.wp.blocks.createBlock(
						blockName,
						blockAttrs
					);
					window.wp.data
						.dispatch( 'core/block-editor' )
						.insertBlocks( block );
				},
				{ blockName: name, blockAttrs: attrs }
			);

			const block = editor.locator( `[data-type="${ name }"]` );
			await expect( block ).toBeVisible();

			// Several blocks (checkin-dashboard, checkins-feed, venue-detail,
			// checkin-card…) fetch live data on mount and show a spinner
			// until it resolves; the rendered height differs between the
			// loading and settled states, so snapshotting mid-fetch makes
			// the baseline non-deterministic. Wait for both this project's
			// custom loading class and core's <Spinner/> to clear. Harmless
			// no-op for blocks with neither (locator never matches).
			await block
				.locator( '.checkin-loading, .components-spinner' )
				.waitFor( { state: 'detached', timeout: 10000 } )
				.catch( () => {} );

			await expect( block ).toHaveScreenshot( `${ slug }-populated.png`, {
				maxDiffPixelRatio: 0.02,
			} );
		} );
	}

	test( 'Settings page appearance', async ( { page } ) => {
		// Plugin's main admin slug is `post-kinds-for-indieweb` (the menu
		// label is "Reactions"). Settings page chrome is what we snapshot.
		await page.goto( '/wp-admin/admin.php?page=post-kinds-for-indieweb' );
		await page.waitForLoadState( 'networkidle' );

		const content = page.locator( '#wpcontent' );
		await expect( content ).toHaveScreenshot( 'settings-page.png', {
			maxDiffPixelRatio: 0.02,
		} );
	} );
} );
