/**
 * E2E: kind archive templates and layouts (issue 233).
 *
 * Seeds eat and listen posts over REST as the admin, then checks the
 * rendered /kind/eat/ menu and /kind/listen/ shelf as an anonymous
 * visitor: one h1, section headings, text ratings, hidden leaders, the
 * privacy gate, one link per shelf item, visible focus, reduced motion,
 * and a single column with no horizontal scroll at 320 CSS px.
 */

const { test, expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;

const BASE = process.env.WP_BASE_URL || 'http://localhost:8888';
const RUN = Date.now().toString( 36 );

let created = [];

async function adminRequest( browser ) {
	const context = await browser.newContext();
	const page = await context.newPage();
	await page.goto( `${ BASE }/wp-login.php` );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await page.waitForURL( '**/wp-admin/**' );
	const nonce = await page.evaluate( () =>
		fetch( '/wp-admin/admin-ajax.php?action=rest-nonce' ).then( ( r ) =>
			r.text()
		)
	);
	return { context, page, nonce };
}

async function kindId( page, nonce, slug ) {
	const res = await page.request.get(
		`${ BASE }/?rest_route=/wp/v2/kind&slug=${ slug }`,
		{ headers: { 'X-WP-Nonce': nonce } }
	);
	const terms = await res.json();
	return terms[ 0 ].id;
}

async function createPost( page, nonce, kind, title, content, meta = {} ) {
	const res = await page.request.post( `${ BASE }/?rest_route=/wp/v2/posts`, {
		headers: { 'X-WP-Nonce': nonce },
		data: {
			title,
			content,
			status: 'publish',
			kind: [ kind ],
			meta,
		},
	} );
	expect( res.ok() ).toBeTruthy();
	const post = await res.json();
	created.push( post.id );
	return post;
}

test.describe( 'kind archive layouts', () => {
	// One seeding pass shared by every test in this file.
	test.describe.configure( { mode: 'serial' } );

	test.beforeAll( async ( { browser } ) => {
		const { context, page, nonce } = await adminRequest( browser );
		const eat = await kindId( page, nonce, 'eat' );
		const listen = await kindId( page, nonce, 'listen' );

		await createPost(
			page,
			nonce,
			eat,
			`Pasta ${ RUN }`,
			`<!-- wp:post-kinds-indieweb/eat-card {"name":"Cacio ${ RUN }","cuisine":"Italian","rating":4,"restaurant":"Roscioli ${ RUN }"} /-->`
		);
		await createPost(
			page,
			nonce,
			eat,
			`Toast ${ RUN }`,
			`<!-- wp:post-kinds-indieweb/eat-card {"name":"Toast ${ RUN }","restaurant":"Hidden ${ RUN }"} /-->`,
			{ _pkiw_geo_privacy: 'private' }
		);
		await createPost(
			page,
			nonce,
			listen,
			`Album ${ RUN }`,
			'<!-- wp:paragraph --><p>listened</p><!-- /wp:paragraph -->'
		);
		await context.close();
	} );

	test.afterAll( async ( { browser } ) => {
		const { context, page, nonce } = await adminRequest( browser );
		for ( const id of created ) {
			await page.request.delete(
				`${ BASE }/?rest_route=/wp/v2/posts/${ id }&force=true`,
				{ headers: { 'X-WP-Nonce': nonce } }
			);
		}
		created = [];
		await context.close();
	} );

	test( 'eat archive renders a grouped, privacy-gated menu', async ( {
		page,
	} ) => {
		await page.goto( `${ BASE }/?kind=eat` );

		await expect( page.locator( 'h1' ) ).toHaveCount( 1 );

		const sections = page.locator( '.pkiw-menu-entry__section' );
		await expect( sections.first() ).toHaveText( 'Italian' );
		await expect( sections.last() ).toHaveText( 'Other' );

		const line = page.locator( '.pkiw-menu-entry', {
			hasText: `Cacio ${ RUN }`,
		} );
		await expect( line ).toContainText( 'Rated 4 of 5' );
		await expect( line ).toContainText( `Roscioli ${ RUN }` );
		await expect(
			line.locator( '.pkiw-menu-entry__leader' )
		).toHaveAttribute( 'aria-hidden', 'true' );

		await expect(
			page.locator( '.pkiw-menu-entry', {
				hasText: `Toast ${ RUN }`,
			} )
		).toBeVisible();
		await expect( page.getByText( `Hidden ${ RUN }` ) ).toHaveCount( 0 );
	} );

	test( 'listen shelf: one link per item, visible focus, reduced motion', async ( {
		page,
	} ) => {
		await page.emulateMedia( { reducedMotion: 'reduce' } );
		await page.goto( `${ BASE }/?kind=listen` );

		await expect( page.locator( 'h1' ) ).toHaveCount( 1 );

		const item = page.locator( '.is-style-pkiw-shelf > li', {
			hasText: `Album ${ RUN }`,
		} );
		await expect( item.locator( 'a' ) ).toHaveCount( 1 );

		await item.locator( 'a' ).focus();
		await page.keyboard.press( 'Shift+Tab' );
		await page.keyboard.press( 'Tab' );
		const outline = await item.evaluate(
			( el ) =>
				el.ownerDocument.defaultView.getComputedStyle( el ).outlineStyle
		);
		expect( outline ).toBe( 'solid' );

		const translate = await item.evaluate(
			( el ) =>
				el.ownerDocument.defaultView.getComputedStyle( el ).translate
		);
		expect( translate ).toBe( 'none' );
	} );

	test( 'shelf and menu reflow to one column at 320 CSS px', async ( {
		page,
	} ) => {
		await page.setViewportSize( { width: 320, height: 800 } );

		for ( const kind of [ 'listen', 'eat' ] ) {
			await page.goto( `${ BASE }/?kind=${ kind }` );
			const overflow = await page.evaluate(
				() =>
					document.documentElement.scrollWidth -
					document.documentElement.clientWidth
			);
			expect(
				overflow,
				`${ kind } scrolls horizontally`
			).toBeLessThanOrEqual( 0 );
		}

		await page.goto( `${ BASE }/?kind=listen` );
		const columns = await page
			.locator( '.is-style-pkiw-shelf' )
			.evaluate(
				( el ) =>
					el.ownerDocument.defaultView
						.getComputedStyle( el )
						.gridTemplateColumns.split( ' ' )
						.filter( Boolean ).length
			);
		expect( columns ).toBe( 1 );
	} );

	test( 'menu and shelf archives have no axe violations in main', async ( {
		page,
	} ) => {
		for ( const kind of [ 'eat', 'listen' ] ) {
			await page.goto( `${ BASE }/?kind=${ kind }` );
			const results = await new AxeBuilder( { page } )
				.include( 'main' )
				.withTags( [
					'wcag2a',
					'wcag2aa',
					'wcag21a',
					'wcag21aa',
					'wcag22aa',
				] )
				.analyze();
			expect( results.violations, kind ).toEqual( [] );
		}
	} );
} );
