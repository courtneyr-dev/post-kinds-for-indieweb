/**
 * E2E tests for plugin activation and basic functionality
 */

const { test, expect } = require( '@playwright/test' );

test.describe( 'Plugin Activation', () => {
	test.beforeEach( async ( { page } ) => {
		// Login to WordPress admin
		await page.goto( '/wp-login.php' );
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass', 'password' );
		await page.click( '#wp-submit' );
		await page.waitForURL( '**/wp-admin/**' );
	} );

	test( 'plugin is activated and visible in admin menu', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/' );

		// Check for Post Kinds menu item
		const menuItem = page.locator( '#adminmenu' ).getByText( 'Post Kinds' );
		await expect( menuItem ).toBeVisible();
	} );

	test( 'settings page loads correctly', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=post-kinds-settings' );

		// Check page title
		await expect( page.locator( 'h1' ) ).toContainText( 'Post Kinds' );
	} );
} );

test.describe( 'Block Editor Integration', () => {
	test.beforeEach( async ( { page } ) => {
		// Login
		await page.goto( '/wp-login.php' );
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass', 'password' );
		await page.click( '#wp-submit' );
		await page.waitForURL( '**/wp-admin/**' );
	} );

	// TODO: rewrite for iframed block editor (WP 6.5+).
	// `.block-editor-writing-flow` and the inserted-block selectors live
	// inside `iframe[name="editor-canvas"]` and aren't visible to outer-
	// frame locators. Wrap in `page.frameLocator('iframe[name="editor-canvas"]')`
	// when picking this up.
	test.skip( 'post kind blocks are available in inserter', async ( {
		page,
	} ) => {
		// Create a new post
		await page.goto( '/wp-admin/post-new.php' );

		// Wait for editor to load
		await page.waitForSelector( '.block-editor-writing-flow' );

		// Open block inserter
		const inserterButton = page.getByRole( 'button', {
			name: 'Toggle block inserter',
		} );
		await inserterButton.click();

		// Search for our blocks
		const searchInput = page.getByPlaceholder( 'Search' );
		await searchInput.fill( 'Listen Card' );

		// Verify block appears
		const listenCardBlock = page.getByRole( 'option', {
			name: /Listen Card/,
		} );
		await expect( listenCardBlock ).toBeVisible();
	} );

	// TODO: rewrite for iframed block editor — see the matching skip on
	// `post kind blocks are available in inserter` above.
	test.skip( 'can insert Listen Card block', async ( { page } ) => {
		await page.goto( '/wp-admin/post-new.php' );
		await page.waitForSelector( '.block-editor-writing-flow' );

		// Open inserter
		const inserterButton = page.getByRole( 'button', {
			name: 'Toggle block inserter',
		} );
		await inserterButton.click();

		// Search and insert
		const searchInput = page.getByPlaceholder( 'Search' );
		await searchInput.fill( 'Listen Card' );

		const listenCardBlock = page.getByRole( 'option', {
			name: /Listen Card/,
		} );
		await listenCardBlock.click();

		// Verify block is inserted
		const block = page.locator(
			'[data-type="post-kinds-indieweb/listen-card"]'
		);
		await expect( block ).toBeVisible();
	} );
} );
