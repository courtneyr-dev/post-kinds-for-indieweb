/**
 * Accessibility E2E tests using axe-core
 */

const { test, expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;

test.describe( 'Accessibility', () => {
	test.beforeEach( async ( { page } ) => {
		// Login to WordPress admin
		await page.goto( '/wp-login.php' );
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass', 'password' );
		await page.click( '#wp-submit' );
		await page.waitForURL( '**/wp-admin/**' );
	} );

	test( 'settings page should have no critical accessibility violations', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/admin.php?page=post-kinds-settings' );

		const accessibilityScanResults = await new AxeBuilder( { page } )
			.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa' ] )
			.exclude( '#wpadminbar' ) // Exclude WordPress admin bar
			.analyze();

		// Filter to only critical and serious issues
		const criticalViolations = accessibilityScanResults.violations.filter(
			( v ) => v.impact === 'critical' || v.impact === 'serious'
		);

		expect( criticalViolations ).toEqual( [] );
	} );

	// TODO: rewrite for iframed block editor.
	//
	// WP 6.5+ runs the block editor inside an `iframe[name="editor-canvas"]`.
	// The locators below (`.block-editor-writing-flow`, `[data-type="..."]`)
	// resolve inside the iframe and aren't visible to `page.waitForSelector`
	// on the outer frame. Proper fix: wrap the in-editor selectors in
	// `page.frameLocator('iframe[name="editor-canvas"]')`. axe-core scans
	// also need to target the iframe's document. Skipping until that
	// rework lands.
	test.skip( 'block editor with blocks should be accessible', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/post-new.php' );
		await page.waitForSelector( '.block-editor-writing-flow' );

		// Insert a Listen Card block
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

		// Wait for block to be inserted
		await page.waitForSelector(
			'[data-type="post-kinds-indieweb/listen-card"]'
		);

		// Run accessibility scan on the editor area
		const accessibilityScanResults = await new AxeBuilder( { page } )
			.withTags( [ 'wcag2a', 'wcag2aa' ] )
			.include( '.block-editor-writing-flow' )
			.analyze();

		const criticalViolations = accessibilityScanResults.violations.filter(
			( v ) => v.impact === 'critical' || v.impact === 'serious'
		);

		expect( criticalViolations ).toEqual( [] );
	} );
} );

test.describe( 'Keyboard Navigation', () => {
	test.beforeEach( async ( { page } ) => {
		await page.goto( '/wp-login.php' );
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass', 'password' );
		await page.click( '#wp-submit' );
		await page.waitForURL( '**/wp-admin/**' );
	} );

	// TODO: rewrite for iframed block editor — see the matching skip on
	// `block editor with blocks should be accessible` above for context.
	test.skip( 'star rating is keyboard accessible', async ( { page } ) => {
		await page.goto( '/wp-admin/post-new.php' );
		await page.waitForSelector( '.block-editor-writing-flow' );

		// Insert Star Rating block
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

		// Wait for block
		await page.waitForSelector(
			'[data-type="post-kinds-indieweb/star-rating"]'
		);

		// Tab to the star rating and verify focus
		await page.keyboard.press( 'Tab' );
		await page.keyboard.press( 'Tab' );

		// Verify focus is visible (basic check). The lint rule
		// `@wordpress/no-global-active-element` is meant for product code
		// inside Block Editor iframes; in a Playwright eval the page's
		// own `document` is exactly what we want.
		const focusedElement = await page.evaluate( () => {
			// eslint-disable-next-line @wordpress/no-global-active-element
			return document.activeElement?.tagName;
		} );

		expect( focusedElement ).toBeTruthy();
	} );
} );
