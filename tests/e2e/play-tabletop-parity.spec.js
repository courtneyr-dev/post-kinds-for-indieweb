/**
 * Editor parity for the board game tabletop (issue 232 PL9).
 *
 * A board game play card that isn't selected shows the server's tabletop
 * in the editor canvas. This spec publishes one, then compares the card
 * region in the canvas with the card region on the published single: the
 * same parts in the same order, the same text, and boxes within a few
 * pixels of each other. The single loads at the canvas iframe's own
 * width, because a theme's fluid type sizes text from the viewport, and
 * the canvas is narrower than the browser window around it. The
 * template's H1 and featured image sit outside the card, so the
 * comparison leaves them out. Screenshots of both regions are attached to
 * the report.
 */

const { test, expect } = require( '@playwright/test' );

const BASE = process.env.WP_BASE_URL || 'http://localhost:8888';
const BLOCK = 'post-kinds-indieweb/play-card';
const TOLERANCE = 4;

const ATTRIBUTES = {
	title: 'Forest Paths',
	platform: 'Board Game',
	status: 'completed',
	hoursPlayed: 2.5,
	rating: 4,
	review: 'Tense to the last turn.',
	gameUrl: 'https://example.test/games/forest-paths',
	officialUrl: 'https://forest.example/',
	bggId: '9990001',
	playedAt: '2026-09-20',
	// tests/uploads is the env's uploads folder, so the box image loads
	// in both views without the network.
	cover: `${ BASE }/wp-content/uploads/pkiw-test-photo.jpg`,
	coverAlt: 'Forest Paths box',
};

const PARTS = [
	'figure.pk-box',
	'dl.pk-facts',
	'div.pk-links',
	'section.pk-scorepad',
];

test.slow();

test.beforeEach( async ( { page } ) => {
	await page.goto( `${ BASE }/wp-login.php` );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await page.waitForURL( '**/wp-admin/**' );
} );

/**
 * The card's parts, their text and their boxes relative to the card.
 *
 * @param {import('@playwright/test').Locator} card  Card article.
 * @param {string[]}                           parts Part selectors.
 * @return {Promise<Object>} Measurements.
 */
async function measure( card, parts ) {
	// The editor canvas is an iframe that starts loading the theme's web
	// fonts when the server preview first lays out, so measure only after
	// that document's fonts and images have settled.
	await card.evaluate( async ( element ) => {
		const doc = element.ownerDocument;
		await Promise.all(
			[ ...element.querySelectorAll( 'img' ) ].map( ( img ) =>
				img.complete ? null : img.decode().catch( () => null )
			)
		);
		await doc.fonts.ready;
		await new Promise( ( resolve ) =>
			doc.defaultView.requestAnimationFrame( () =>
				doc.defaultView.requestAnimationFrame( resolve )
			)
		);
	} );
	await expect
		.poll( () =>
			card.evaluate( ( element ) => element.ownerDocument.fonts.status )
		)
		.toBe( 'loaded' );

	return card.evaluate( ( element, selectors ) => {
		const origin = element.getBoundingClientRect();
		const box = ( node ) => {
			const rect = node.getBoundingClientRect();
			return {
				x: rect.left - origin.left,
				y: rect.top - origin.top,
				width: rect.width,
				height: rect.height,
			};
		};
		const text = ( node ) => node.innerText.replace( /\s+/g, ' ' ).trim();

		return {
			order: [ ...element.children ]
				.filter( ( child ) => ! child.hidden )
				.map(
					( child ) =>
						`${ child.tagName.toLowerCase() }.${ child.classList[ 0 ] }`
				),
			card: { width: origin.width, height: origin.height },
			text: text( element ),
			parts: Object.fromEntries(
				selectors.map( ( selector ) => {
					const node = element.querySelector(
						`:scope > ${ selector }`
					);
					return [
						selector,
						node ? { box: box( node ), text: text( node ) } : null,
					];
				} )
			),
		};
	}, parts );
}

test( 'a board game card matches between the editor canvas and its single', async ( {
	page,
}, testInfo ) => {
	await page.setViewportSize( { width: 1280, height: 1000 } );
	await page.goto( `${ BASE }/wp-admin/post-new.php` );
	await page.evaluate( () =>
		window.wp.data
			.dispatch( 'core/preferences' )
			.set( 'core', 'welcomeGuide', false )
	);
	const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
	await canvas.locator( '.is-root-container' ).waitFor( { timeout: 30000 } );

	await page.evaluate(
		( { name, attributes } ) => {
			window.wp.data
				.dispatch( 'core/editor' )
				.editPost( { title: attributes.title, status: 'publish' } );
			window.wp.data
				.dispatch( 'core/block-editor' )
				.insertBlocks(
					window.wp.blocks.createBlock( name, attributes )
				);
		},
		{ name: BLOCK, attributes: ATTRIBUTES }
	);

	// The card sets the play kind once it mounts; save after that.
	await page.waitForFunction(
		() =>
			(
				window.wp.data
					.select( 'core/editor' )
					.getEditedPostAttribute( 'kind' ) || []
			).length > 0
	);
	await page.evaluate( () =>
		window.wp.data.dispatch( 'core/editor' ).savePost()
	);
	await page.waitForFunction(
		() =>
			! window.wp.data.select( 'core/editor' ).isSavingPost() &&
			'publish' ===
				window.wp.data
					.select( 'core/editor' )
					.getCurrentPostAttribute( 'status' )
	);

	// Unselected, the card shows the server's tabletop.
	await page.evaluate( () =>
		window.wp.data.dispatch( 'core/block-editor' ).clearSelectedBlock()
	);
	const editorCard = canvas.locator(
		`[data-type="${ BLOCK }"] article.pk-card--tabletop`
	);
	await expect( editorCard ).toBeVisible( { timeout: 30000 } );
	await expect(
		canvas.locator( `[data-type="${ BLOCK }"] .post-kinds-card` )
	).toHaveCount( 0 );
	const editor = await measure( editorCard, PARTS );
	await testInfo.attach( 'editor-card', {
		body: await editorCard.screenshot(),
		contentType: 'image/png',
	} );

	const canvasWidth = await editorCard.evaluate(
		( element ) => element.ownerDocument.defaultView.innerWidth
	);
	const link = await page.evaluate( () =>
		window.wp.data.select( 'core/editor' ).getPermalink()
	);
	await page.setViewportSize( { width: canvasWidth, height: 1000 } );
	await page.goto( link );
	expect( await page.evaluate( () => window.innerWidth ) ).toBe(
		canvasWidth
	);
	const singleCard = page.locator( 'article.pk-card--tabletop' );
	await expect( singleCard ).toHaveCount( 1 );
	const single = await measure( singleCard, PARTS );
	await testInfo.attach( 'single-card', {
		body: await singleCard.screenshot(),
		contentType: 'image/png',
	} );

	expect( single.order ).toEqual( PARTS );
	expect( editor.order ).toEqual( single.order );
	expect( editor.text ).toBe( single.text );
	expect(
		Math.abs( editor.card.width - single.card.width )
	).toBeLessThanOrEqual( TOLERANCE );
	expect(
		Math.abs( editor.card.height - single.card.height )
	).toBeLessThanOrEqual( TOLERANCE );

	for ( const selector of PARTS ) {
		expect( single.parts[ selector ], selector ).not.toBeNull();
		expect( editor.parts[ selector ], selector ).not.toBeNull();
		expect( editor.parts[ selector ].text, selector ).toBe(
			single.parts[ selector ].text
		);
		for ( const side of [ 'x', 'y', 'width', 'height' ] ) {
			expect(
				Math.abs(
					editor.parts[ selector ].box[ side ] -
						single.parts[ selector ].box[ side ]
				),
				`${ selector } ${ side }`
			).toBeLessThanOrEqual( TOLERANCE );
		}
	}
} );

test( 'selecting the card brings back the edit UI', async ( { page } ) => {
	await page.goto( `${ BASE }/wp-admin/post-new.php` );
	await page.evaluate( () =>
		window.wp.data
			.dispatch( 'core/preferences' )
			.set( 'core', 'welcomeGuide', false )
	);
	const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
	await canvas.locator( '.is-root-container' ).waitFor( { timeout: 30000 } );

	const clientId = await page.evaluate(
		( { name, attributes } ) => {
			const block = window.wp.blocks.createBlock( name, attributes );
			window.wp.data
				.dispatch( 'core/block-editor' )
				.insertBlocks( block );
			window.wp.data.dispatch( 'core/block-editor' ).clearSelectedBlock();
			return block.clientId;
		},
		{ name: BLOCK, attributes: ATTRIBUTES }
	);
	const block = canvas.locator( `[data-type="${ BLOCK }"]` );
	await expect( block.locator( 'article.pk-card--tabletop' ) ).toBeVisible( {
		timeout: 30000,
	} );

	await page.evaluate(
		( id ) =>
			window.wp.data.dispatch( 'core/block-editor' ).selectBlock( id ),
		clientId
	);
	await expect( block.locator( '.post-kinds-card' ) ).toBeVisible();
	await expect( block.locator( 'article.pk-card--tabletop' ) ).toHaveCount(
		0
	);
} );
