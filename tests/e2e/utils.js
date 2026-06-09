/**
 * Shared Playwright helpers for the block-editor E2E tests.
 */

/**
 * Dismiss the "Welcome to the block editor" guide modal if it is showing.
 *
 * A fresh wp-env install (and CI's throwaway environment) opens this guide on
 * the first editor load, and its overlay intercepts pointer events on the
 * toolbar — so the inserter toggle is unclickable until the guide is closed.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 * @return {Promise<void>}
 */
async function dismissWelcomeGuide( page ) {
	const close = page
		.locator( '.components-modal__frame' )
		.getByRole( 'button', { name: 'Close' } );

	if ( await close.isVisible().catch( () => false ) ) {
		await close.click();
		await close.waitFor( { state: 'hidden' } ).catch( () => {} );
	}
}

/**
 * Open the block inserter from the editor toolbar.
 *
 * The toolbar toggle is exposed with the accessible name "Block Inserter" in
 * the Gutenberg shipped with WP 6.9 (older versions used "Toggle block
 * inserter"). The welcome guide is dismissed first so the button is clickable.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 * @return {Promise<void>}
 */
async function openBlockInserter( page ) {
	await dismissWelcomeGuide( page );
	await page.getByRole( 'button', { name: 'Block Inserter' } ).click();
}

module.exports = { dismissWelcomeGuide, openBlockInserter };
