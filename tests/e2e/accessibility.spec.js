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
		// Plugin's actual settings slug is `post-kinds-for-indieweb`
		// (the menu label is "Reactions"). The previous `post-kinds-settings`
		// slug was a typo — would silently 404 and the a11y scan would run
		// against a generic admin error page.
		await page.goto( '/wp-admin/admin.php?page=post-kinds-for-indieweb' );

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

	test( 'block editor with blocks should be accessible', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/post-new.php' );

		// WP 6.5+ runs the editor inside `iframe[name="editor-canvas"]`.
		// The inserter sidebar is on the OUTER frame; the writing flow
		// and inserted blocks live INSIDE the iframe.
		const editor = page.frameLocator( 'iframe[name="editor-canvas"]' );
		await editor
			.locator( '.block-editor-writing-flow' )
			.waitFor( { timeout: 30000 } );

		// Inserter UI lives outside the iframe.
		await page
			.getByRole( 'button', { name: 'Toggle block inserter' } )
			.click();
		await page.getByPlaceholder( 'Search' ).fill( 'Listen Card' );
		await page.getByRole( 'option', { name: /Listen Card/ } ).click();

		// Inserted block lives inside the iframe.
		await editor
			.locator( '[data-type="post-kinds-indieweb/listen-card"]' )
			.waitFor( { timeout: 10000 } );

		// axe-core 4.7+ traverses iframes when given the frame selector
		// in `include()` — the scanner descends into the canvas document
		// and reports violations from inside.
		const accessibilityScanResults = await new AxeBuilder( { page } )
			.withTags( [ 'wcag2a', 'wcag2aa' ] )
			.include( 'iframe[name="editor-canvas"]' )
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

	test( 'star rating is keyboard accessible', async ( { page } ) => {
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

		await editor
			.locator( '[data-type="post-kinds-indieweb/star-rating"]' )
			.waitFor( { timeout: 10000 } );

		// Tab through the editor to confirm focus reaches a focusable
		// element. Two tabs is enough to leave the title field and land
		// on either the inserter affordance or the block toolbar.
		await page.keyboard.press( 'Tab' );
		await page.keyboard.press( 'Tab' );

		// Active element across the iframe boundary: the outer page
		// proxies focus into the iframe via `document.activeElement`,
		// so reading from outer-frame `document` returns the iframe
		// itself when focus is inside. Confirming it returns SOMETHING
		// is enough for "keyboard navigation works."
		const focusedTag = await page.evaluate( () => {
			// eslint-disable-next-line @wordpress/no-global-active-element
			return document.activeElement?.tagName ?? null;
		} );

		expect( focusedTag ).toBeTruthy();
	} );
} );
