/**
 * Full-flow e2e: Read card ISBN -> completed meta -> Kindle iframe.
 *
 * Exercises the whole Task 7-12 chain in one pass: insert a Read card with
 * only an ISBN plus a marked Kindle-preview embed, publish, and assert the
 * front end renders an iframe whose src is the derived print-ASIN Kindle
 * embed URL (Kindle_Embed_Bridge::render(), reading _postkind_read_asin /
 * _postkind_read_isbn meta filled by Book_Completion_Controller::complete_on_save()).
 *
 * DETERMINISM: the real completion cascade (Book_Completion) hits Open
 * Library live over HTTP on the ISBN lookup path. That's fine for manual
 * testing but makes this e2e flaky in CI (network availability, upstream
 * response drift) through no fault of the plugin code under test. Per the
 * task-13 brief, this spec relies on a wp-env-only fixture,
 * tests/env/pkiw-test-book-completion.php, which stubs the
 * `pkiw_book_completion_service` filter (see
 * Book_Completion_Controller::service()) with a fixed responder for ISBN
 * 9781649374042 (Fourth Wing), returning the same derived ASIN
 * (1649374046 = Isbn::to10() of that ISBN-13) the real cascade would
 * eventually produce. The fixture is mapped into wp-content/mu-plugins via
 * the existing directory mapping in .wp-env.json (same mechanism as
 * tests/env/pkiw-test-auth.php) — never shipped outside the test env.
 *
 * EMBED INSERTION: the embed block is created with a non-empty placeholder
 * url (see comment at the insertBlocks() call below) rather than the blank
 * url used in patterns/read-with-kindle-preview.php. core/embed's save() is
 * a real save() call for a JS-created block, and empty-url embeds serialize
 * as self-closing blocks with no innerHTML for Kindle_Embed_Bridge to
 * rewrite. The pattern file's blank-url wrapper markup is only safe because
 * it's a literal HTML string parsed once, never re-serialized through
 * save(). This mirrors exactly what the read-card's inspector toggle inserts.
 */

const { test, expect } = require( '@playwright/test' );

const BASE = process.env.WP_BASE_URL || 'http://localhost:8888';

test.beforeEach( async ( { page } ) => {
	// Login to WordPress admin (same pattern as tests/e2e/block-field-matrix.spec.js).
	await page.goto( `${ BASE }/wp-login.php` );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await page.waitForURL( '**/wp-admin/**' );
} );

test( 'read post: isbn -> completed meta -> kindle iframe on the front end', async ( {
	page,
} ) => {
	await page.goto( `${ BASE }/wp-admin/post-new.php` );

	// Dismiss welcome guide via preference (never click-race the modal).
	await page.evaluate( () =>
		window.wp.data
			.dispatch( 'core/preferences' )
			.set( 'core', 'welcomeGuide', false )
	);
	await page
		.locator( 'iframe[name="editor-canvas"]' )
		.contentFrame()
		.locator( '.is-root-container' )
		.waitFor( { timeout: 30000 } );

	await page.evaluate( () => {
		const read = window.wp.blocks.createBlock(
			'post-kinds-indieweb/read-card',
			{
				bookTitle: 'Fourth Wing',
				isbn: '9781649374042',
			}
		);
		// Mirrors what the read-card's "Show Kindle preview" inspector
		// toggle inserts (src/blocks/read-card/edit.js): a non-empty
		// placeholder url so core/embed's save() emits wrapper markup for
		// Kindle_Embed_Bridge to rewrite — a blank url serializes as a
		// self-closing block with no innerHTML to rewrite (verified against
		// wp.blocks.serialize() while writing this spec). The placeholder
		// itself is never shown; the render bridge replaces the wrapper's
		// contents with an iframe derived from the post's ISBN/ASIN.
		const embed = window.wp.blocks.createBlock( 'core/embed', {
			providerNameSlug: 'amazon-kindle',
			className: 'pkiw-kindle-preview',
			url: 'https://read.amazon.com/kp/embed',
			type: 'video',
		} );
		window.wp.data
			.dispatch( 'core/block-editor' )
			.insertBlocks( [ read, embed ] );
	} );

	await page.evaluate( () =>
		window.wp.data
			.dispatch( 'core/editor' )
			.editPost( { status: 'publish' } )
	);
	await page.evaluate( () =>
		window.wp.data.dispatch( 'core/editor' ).savePost()
	);
	await page.waitForFunction(
		() => ! window.wp.data.select( 'core/editor' ).isSavingPost()
	);

	const link = await page.evaluate( () =>
		window.wp.data.select( 'core/editor' ).getPermalink()
	);
	await page.goto( link );

	const iframe = page.locator( '.pkiw-kindle-preview iframe' );
	await expect( iframe ).toHaveAttribute(
		'src',
		'https://read.amazon.com/kp/embed?asin=1649374046&preview=inline'
	);
} );
