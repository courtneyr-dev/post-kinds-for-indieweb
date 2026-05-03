/**
 * Visual regression tests for block appearance
 *
 * These tests capture screenshots of blocks and compare against baselines.
 * Run `npm run test:visual` to update snapshots.
 *
 * Skipped in CI for now — two reasons:
 *
 * 1. The block-editor tests use `.block-editor-writing-flow` selectors
 *    that live inside `iframe[name="editor-canvas"]` (WP 6.5+ iframed
 *    the editor). These need a `page.frameLocator()` rewrite to work
 *    against a current WordPress.
 * 2. The settings-page snapshot test has no committed baseline; without
 *    one, the first CI run can't compare anything. Visual regression
 *    suites typically need baselines generated via
 *    `npm run test:visual` and committed before they can gate PRs.
 *
 * To re-enable: rewrite the selectors with frameLocator, generate
 * baselines with `npm run test:visual`, and commit the
 * `*-snapshots/` directories.
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
		await page.waitForSelector( '.block-editor-writing-flow' );

		// Insert Listen Card
		const inserterButton = page.getByRole( 'button', {
			name: 'Toggle block inserter',
		} );
		await inserterButton.click();

		const searchInput = page.getByPlaceholder( 'Search' );
		await searchInput.fill( 'Listen Card' );

		const listenCardBlock = page.getByRole( 'option', {
			name: /Listen Card/,
		} );
		await listenCardBlock.click();

		// Wait for block to render
		const block = page.locator(
			'[data-type="post-kinds-indieweb/listen-card"]'
		);
		await expect( block ).toBeVisible();

		// Take screenshot of the block
		await expect( block ).toHaveScreenshot( 'listen-card-default.png', {
			maxDiffPixelRatio: 0.05,
		} );
	} );

	test( 'Watch Card block appearance', async ( { page } ) => {
		await page.goto( '/wp-admin/post-new.php' );
		await page.waitForSelector( '.block-editor-writing-flow' );

		const inserterButton = page.getByRole( 'button', {
			name: 'Toggle block inserter',
		} );
		await inserterButton.click();

		const searchInput = page.getByPlaceholder( 'Search' );
		await searchInput.fill( 'Watch Card' );

		const watchCardBlock = page.getByRole( 'option', {
			name: /Watch Card/,
		} );
		await watchCardBlock.click();

		const block = page.locator(
			'[data-type="post-kinds-indieweb/watch-card"]'
		);
		await expect( block ).toBeVisible();

		await expect( block ).toHaveScreenshot( 'watch-card-default.png', {
			maxDiffPixelRatio: 0.05,
		} );
	} );

	test( 'Read Card block appearance', async ( { page } ) => {
		await page.goto( '/wp-admin/post-new.php' );
		await page.waitForSelector( '.block-editor-writing-flow' );

		const inserterButton = page.getByRole( 'button', {
			name: 'Toggle block inserter',
		} );
		await inserterButton.click();

		const searchInput = page.getByPlaceholder( 'Search' );
		await searchInput.fill( 'Read Card' );

		const readCardBlock = page.getByRole( 'option', {
			name: /Read Card/,
		} );
		await readCardBlock.click();

		const block = page.locator(
			'[data-type="post-kinds-indieweb/read-card"]'
		);
		await expect( block ).toBeVisible();

		await expect( block ).toHaveScreenshot( 'read-card-default.png', {
			maxDiffPixelRatio: 0.05,
		} );
	} );

	test( 'Star Rating block appearance', async ( { page } ) => {
		await page.goto( '/wp-admin/post-new.php' );
		await page.waitForSelector( '.block-editor-writing-flow' );

		const inserterButton = page.getByRole( 'button', {
			name: 'Toggle block inserter',
		} );
		await inserterButton.click();

		const searchInput = page.getByPlaceholder( 'Search' );
		await searchInput.fill( 'Star Rating' );

		const starRatingBlock = page.getByRole( 'option', {
			name: /Star Rating/,
		} );
		await starRatingBlock.click();

		const block = page.locator(
			'[data-type="post-kinds-indieweb/star-rating"]'
		);
		await expect( block ).toBeVisible();

		await expect( block ).toHaveScreenshot( 'star-rating-default.png', {
			maxDiffPixelRatio: 0.05,
		} );
	} );

	test( 'Settings page appearance', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=post-kinds-settings' );

		// Wait for page to fully load
		await page.waitForLoadState( 'networkidle' );

		// Take screenshot of main content area (excluding admin bar)
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
		await page.waitForSelector( '.block-editor-writing-flow' );

		const inserterButton = page.getByRole( 'button', {
			name: 'Toggle block inserter',
		} );
		await inserterButton.click();

		const searchInput = page.getByPlaceholder( 'Search' );
		await searchInput.fill( 'Listen Card' );

		const listenCardBlock = page.getByRole( 'option', {
			name: /Listen Card/,
		} );
		await listenCardBlock.click();

		const block = page.locator(
			'[data-type="post-kinds-indieweb/listen-card"]'
		);
		await expect( block ).toBeVisible();

		await expect( block ).toHaveScreenshot( 'listen-card-dark.png', {
			maxDiffPixelRatio: 0.05,
		} );
	} );
} );
