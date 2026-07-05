/**
 * E2E: the "Promote to main archive" toggle persists the pkiw_promote meta.
 */

const { test, expect } = require( '@playwright/test' );

const BASE = process.env.WP_BASE_URL || 'http://localhost:8888';

test( 'promote toggle persists pkiw_promote meta', async ( { page } ) => {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await page.waitForURL( '**/wp-admin/**' );

	await page.goto( `${ BASE }/wp-admin/post-new.php` );
	// Dismiss welcome guide via preference (never click-race the modal).
	await page.evaluate( () =>
		window.wp.data
			.dispatch( 'core/preferences' )
			.set( 'core', 'welcomeGuide', false )
	);
	await page
		.frameLocator( 'iframe[name="editor-canvas"]' )
		.locator( '.is-root-container' )
		.waitFor( { timeout: 30000 } );

	// Open the "Post surface" panel and flip the toggle.
	await page.getByRole( 'button', { name: /Post surface/i } ).click();
	await page.getByLabel( /Promote to main archive/i ).check();

	await expect
		.poll( () =>
			page.evaluate(
				() =>
					window.wp.data
						.select( 'core/editor' )
						.getEditedPostAttribute( 'meta' ).pkiw_promote
			)
		)
		.toBe( true );
} );
