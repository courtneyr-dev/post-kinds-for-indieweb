/**
 * Visual regression tests for block appearance.
 *
 * These tests capture screenshots of blocks and compare against committed
 * baselines. Run `npm run test:visual` to update snapshots.
 *
 * **Skipped in CI by default** — visual regression suites need three things
 * before they're useful as a PR gate, and we don't have all three yet:
 *
 * 1. Committed baseline images. Without them, the first CI run can't
 *    compare anything. Generate via `npm run test:visual` (which uses
 *    `--update-snapshots`), then commit the `*-snapshots/` directories.
 * 2. Cross-environment font rendering parity. CI's headless Chromium and a
 *    local Mac headed Chromium produce slightly different antialiased
 *    glyphs even at the same resolution. The `maxDiffPixelRatio: 0.05`
 *    setting absorbs typical drift but won't survive font-stack changes.
 * 3. A stable theme. The default `wp-env` theme can shift between WP
 *    versions; visual baselines need to be regenerated whenever the
 *    underlying theme paint changes.
 *
 * The tests themselves are correctly written for the iframed editor
 * (WP 6.5+'s `iframe[name="editor-canvas"]`). Drop the `.skip` on the
 * describe blocks once baselines are committed.
 */

const { test, expect } = require( '@playwright/test' );

test.describe.skip( 'Visual Regression', () => {
	test.beforeEach( async ( { page } ) => {
		// Login to WordPress admin
		await page.goto( '/wp-login.php' );
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass', 'password' );
		await page.click( '#wp-submit' );
		await page.waitForURL( '**/wp-admin/**' );
	} );

	test( 'Listen Card block appearance', async ( { page } ) => {
		await page.goto( '/wp-admin/post-new.php' );

		const editor = page.frameLocator( 'iframe[name="editor-canvas"]' );
		await editor
			.locator( '.block-editor-writing-flow' )
			.waitFor( { timeout: 30000 } );

		await page
			.getByRole( 'button', { name: 'Toggle block inserter' } )
			.click();
		await page.getByPlaceholder( 'Search' ).fill( 'Listen Card' );
		await page.getByRole( 'option', { name: /Listen Card/ } ).click();

		const block = editor.locator(
			'[data-type="post-kinds-indieweb/listen-card"]'
		);
		await expect( block ).toBeVisible();

		await expect( block ).toHaveScreenshot( 'listen-card-default.png', {
			maxDiffPixelRatio: 0.05,
		} );
	} );

	test( 'Watch Card block appearance', async ( { page } ) => {
		await page.goto( '/wp-admin/post-new.php' );

		const editor = page.frameLocator( 'iframe[name="editor-canvas"]' );
		await editor
			.locator( '.block-editor-writing-flow' )
			.waitFor( { timeout: 30000 } );

		await page
			.getByRole( 'button', { name: 'Toggle block inserter' } )
			.click();
		await page.getByPlaceholder( 'Search' ).fill( 'Watch Card' );
		await page.getByRole( 'option', { name: /Watch Card/ } ).click();

		const block = editor.locator(
			'[data-type="post-kinds-indieweb/watch-card"]'
		);
		await expect( block ).toBeVisible();

		await expect( block ).toHaveScreenshot( 'watch-card-default.png', {
			maxDiffPixelRatio: 0.05,
		} );
	} );

	test( 'Read Card block appearance', async ( { page } ) => {
		await page.goto( '/wp-admin/post-new.php' );

		const editor = page.frameLocator( 'iframe[name="editor-canvas"]' );
		await editor
			.locator( '.block-editor-writing-flow' )
			.waitFor( { timeout: 30000 } );

		await page
			.getByRole( 'button', { name: 'Toggle block inserter' } )
			.click();
		await page.getByPlaceholder( 'Search' ).fill( 'Read Card' );
		await page.getByRole( 'option', { name: /Read Card/ } ).click();

		const block = editor.locator(
			'[data-type="post-kinds-indieweb/read-card"]'
		);
		await expect( block ).toBeVisible();

		await expect( block ).toHaveScreenshot( 'read-card-default.png', {
			maxDiffPixelRatio: 0.05,
		} );
	} );

	test( 'Star Rating block appearance', async ( { page } ) => {
		await page.goto( '/wp-admin/post-new.php' );

		const editor = page.frameLocator( 'iframe[name="editor-canvas"]' );
		await editor
			.locator( '.block-editor-writing-flow' )
			.waitFor( { timeout: 30000 } );

		await page
			.getByRole( 'button', { name: 'Toggle block inserter' } )
			.click();
		await page.getByPlaceholder( 'Search' ).fill( 'Star Rating' );
		await page.getByRole( 'option', { name: /Star Rating/ } ).click();

		const block = editor.locator(
			'[data-type="post-kinds-indieweb/star-rating"]'
		);
		await expect( block ).toBeVisible();

		await expect( block ).toHaveScreenshot( 'star-rating-default.png', {
			maxDiffPixelRatio: 0.05,
		} );
	} );

	test( 'Settings page appearance', async ( { page } ) => {
		// Plugin's main admin slug is `post-kinds-for-indieweb` (the menu
		// label is "Reactions"). Settings page chrome is what we snapshot.
		await page.goto( '/wp-admin/admin.php?page=post-kinds-for-indieweb' );
		await page.waitForLoadState( 'networkidle' );

		const content = page.locator( '#wpcontent' );
		await expect( content ).toHaveScreenshot( 'settings-page.png', {
			maxDiffPixelRatio: 0.05,
		} );
	} );
} );

test.describe.skip( 'Dark Mode Visual Regression', () => {
	test.beforeEach( async ( { page } ) => {
		// Emulate dark mode
		await page.emulateMedia( { colorScheme: 'dark' } );

		await page.goto( '/wp-login.php' );
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass', 'password' );
		await page.click( '#wp-submit' );
		await page.waitForURL( '**/wp-admin/**' );
	} );

	test( 'Listen Card in dark mode', async ( { page } ) => {
		await page.goto( '/wp-admin/post-new.php' );

		const editor = page.frameLocator( 'iframe[name="editor-canvas"]' );
		await editor
			.locator( '.block-editor-writing-flow' )
			.waitFor( { timeout: 30000 } );

		await page
			.getByRole( 'button', { name: 'Toggle block inserter' } )
			.click();
		await page.getByPlaceholder( 'Search' ).fill( 'Listen Card' );
		await page.getByRole( 'option', { name: /Listen Card/ } ).click();

		const block = editor.locator(
			'[data-type="post-kinds-indieweb/listen-card"]'
		);
		await expect( block ).toBeVisible();

		await expect( block ).toHaveScreenshot( 'listen-card-dark.png', {
			maxDiffPixelRatio: 0.05,
		} );
	} );
} );
