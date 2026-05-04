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

		// Plugin registers `add_menu_page( 'Reactions', … )` —
		// "Reactions" reflects the user-facing taxonomy noun. Asserting
		// against the registered slug instead of human label keeps the
		// test stable if the label is later re-translated/relabelled.
		const menuItem = page.locator(
			'#adminmenu a[href*="page=post-kinds-for-indieweb"]'
		);
		await expect( menuItem.first() ).toBeVisible();
	} );

	test( 'settings page loads correctly', async ( { page } ) => {
		const response = await page.goto(
			'/wp-admin/admin.php?page=post-kinds-for-indieweb'
		);

		// The page resolves and renders the WordPress admin chrome.
		expect( response?.status() ).toBeLessThan( 400 );
		await expect( page.locator( '#wpcontent' ) ).toBeVisible();
		await expect( page.locator( 'h1' ).first() ).toBeVisible();
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

	test( 'post kind blocks are available in inserter', async ( { page } ) => {
		await page.goto( '/wp-admin/post-new.php' );

		// Editor canvas lives inside `iframe[name="editor-canvas"]` since
		// WP 6.5; the inserter sidebar stays on the outer frame.
		const editor = page.frameLocator( 'iframe[name="editor-canvas"]' );
		await editor
			.locator( '.block-editor-writing-flow' )
			.waitFor( { timeout: 30000 } );

		await page
			.getByRole( 'button', { name: 'Toggle block inserter' } )
			.click();
		await page.getByPlaceholder( 'Search' ).fill( 'Listen Card' );

		await expect(
			page.getByRole( 'option', { name: /Listen Card/ } )
		).toBeVisible();
	} );

	test( 'can insert Listen Card block', async ( { page } ) => {
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

		// Inserted block lives inside the canvas.
		await expect(
			editor.locator( '[data-type="post-kinds-indieweb/listen-card"]' )
		).toBeVisible();
	} );
} );
