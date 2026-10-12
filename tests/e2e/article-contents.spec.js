/**
 * E2E: article contents archive layout (issue 263).
 */

const { test, expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;

const BASE = process.env.WP_BASE_URL || 'http://localhost:8888';
const RUN = Date.now().toString( 36 );

let createdPosts = [];
let createdMedia = [];
let articleKind = 0;
let leadPost = null;

function words( count ) {
	return Array.from( { length: count }, () => 'word' ).join( ' ' );
}

function pngBuffer() {
	return Buffer.from(
		'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=',
		'base64'
	);
}

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
	expect( res.ok() ).toBeTruthy();
	const terms = await res.json();
	return terms[ 0 ].id;
}

async function uploadImage( page, nonce, name ) {
	const res = await page.request.post( `${ BASE }/?rest_route=/wp/v2/media`, {
		headers: {
			'X-WP-Nonce': nonce,
			'Content-Disposition': `attachment; filename="${ name }.png"`,
			'Content-Type': 'image/png',
		},
		data: pngBuffer(),
	} );
	expect( res.ok() ).toBeTruthy();
	const media = await res.json();
	createdMedia.push( media.id );
	return media.id;
}

async function createArticle( page, nonce, attrs ) {
	const media = attrs.image
		? await uploadImage( page, nonce, attrs.slug )
		: undefined;
	const res = await page.request.post( `${ BASE }/?rest_route=/wp/v2/posts`, {
		headers: { 'X-WP-Nonce': nonce },
		data: {
			title: attrs.title,
			content:
				attrs.content ||
				`<!-- wp:paragraph --><p>${ words( 200 ) }</p><!-- /wp:paragraph -->`,
			status: 'publish',
			date: attrs.date,
			kind: [ articleKind ],
			featured_media: media,
			password: attrs.password || '',
		},
	} );
	expect( res.ok() ).toBeTruthy();
	const post = await res.json();
	createdPosts.push( post.id );
	return post;
}

async function publishedArticlePosts( page, nonce ) {
	const res = await page.request.get(
		`${ BASE }/?rest_route=/wp/v2/posts&kind=${ articleKind }&status=publish&per_page=100`,
		{ headers: { 'X-WP-Nonce': nonce } }
	);
	expect( res.ok() ).toBeTruthy();
	return res.json();
}

async function draftPost( page, nonce, id ) {
	const res = await page.request.post(
		`${ BASE }/?rest_route=/wp/v2/posts/${ id }`,
		{
			headers: { 'X-WP-Nonce': nonce },
			data: { status: 'draft' },
		}
	);
	expect( res.ok() ).toBeTruthy();
}

async function articleTitlesInDom( page ) {
	return page
		.locator( '.is-style-pkiw-contents li .pkiw-contents__title a' )
		.evaluateAll( ( links ) =>
			links.map( ( link ) => link.textContent.trim() )
		);
}

async function articleTitlesByBox( page ) {
	return page
		.locator( '.is-style-pkiw-contents li' )
		.evaluateAll( ( items ) =>
			items
				.map( ( item ) => {
					const box = item.getBoundingClientRect();
					const title = item.querySelector(
						'.pkiw-contents__title a'
					);
					return {
						title: title ? title.textContent.trim() : '',
						top: Math.round( box.top ),
						left: Math.round( box.left ),
					};
				} )
				.sort( ( a, b ) => a.top - b.top || a.left - b.left )
				.map( ( item ) => item.title )
		);
}

test.describe( 'article contents archive', () => {
	test.describe.configure( { mode: 'serial' } );

	test.beforeAll( async ( { browser } ) => {
		const { context, page, nonce } = await adminRequest( browser );
		articleKind = await kindId( page, nonce, 'article' );
		const longTitle = `Long title ${ RUN } ${ '0123456789 '.repeat( 10 ) }`;
		const specs = [
			{
				slug: `lead-${ RUN }`,
				title: `Lead ${ RUN }`,
				date: '2026-08-20T10:00:00',
				image: true,
				content: `<!-- wp:paragraph --><p>${ words( 1000 ) }</p><!-- /wp:paragraph -->`,
			},
			{
				slug: `long-${ RUN }`,
				title: longTitle.slice( 0, 120 ),
				date: '2026-08-19T10:00:00',
				image: true,
			},
			{
				slug: `no-image-a-${ RUN }`,
				title: `No image A ${ RUN }`,
				date: '2026-08-18T10:00:00',
			},
			{
				slug: `no-image-b-${ RUN }`,
				title: `No image B ${ RUN }`,
				date: '2026-08-17T10:00:00',
			},
			{
				slug: `locked-${ RUN }`,
				title: `Locked ${ RUN }`,
				date: '2026-08-16T10:00:00',
				image: true,
				password: 'secret',
			},
			{
				slug: `one-word-${ RUN }`,
				title: `One word ${ RUN }`,
				date: '2026-07-20T10:00:00',
				image: true,
				content:
					'<!-- wp:paragraph --><p>Word</p><!-- /wp:paragraph -->',
			},
			{
				slug: `plain-jul-1-${ RUN }`,
				title: `Plain July 1 ${ RUN }`,
				date: '2026-07-19T10:00:00',
				image: true,
			},
			{
				slug: `plain-jul-2-${ RUN }`,
				title: `Plain July 2 ${ RUN }`,
				date: '2026-07-18T10:00:00',
				image: true,
			},
			{
				slug: `plain-jul-3-${ RUN }`,
				title: `Plain July 3 ${ RUN }`,
				date: '2026-07-17T10:00:00',
				image: true,
			},
			{
				slug: `plain-jul-4-${ RUN }`,
				title: `Plain July 4 ${ RUN }`,
				date: '2026-07-16T10:00:00',
				image: true,
			},
			{
				slug: `plain-jun-1-${ RUN }`,
				title: `Plain June 1 ${ RUN }`,
				date: '2026-06-20T10:00:00',
				image: true,
			},
			{
				slug: `plain-jun-2-${ RUN }`,
				title: `Plain June 2 ${ RUN }`,
				date: '2026-06-19T10:00:00',
				image: true,
			},
			{
				slug: `plain-jun-3-${ RUN }`,
				title: `Plain June 3 ${ RUN }`,
				date: '2026-06-18T10:00:00',
				image: true,
			},
			{
				slug: `plain-jun-4-${ RUN }`,
				title: `Plain June 4 ${ RUN }`,
				date: '2026-06-17T10:00:00',
				image: true,
			},
			{
				slug: `plain-jun-5-${ RUN }`,
				title: `Plain June 5 ${ RUN }`,
				date: '2026-06-16T10:00:00',
				image: true,
			},
		];

		for ( const spec of specs ) {
			const post = await createArticle( page, nonce, spec );
			if ( spec.slug.startsWith( 'lead-' ) ) {
				leadPost = post;
			}
		}
		await context.close();
	} );

	test.afterAll( async ( { browser } ) => {
		const { context, page, nonce } = await adminRequest( browser );
		for ( const id of createdPosts ) {
			await page.request.delete(
				`${ BASE }/?rest_route=/wp/v2/posts/${ id }&force=true`,
				{ headers: { 'X-WP-Nonce': nonce } }
			);
		}
		for ( const id of createdMedia ) {
			await page.request.delete(
				`${ BASE }/?rest_route=/wp/v2/media/${ id }&force=true`,
				{ headers: { 'X-WP-Nonce': nonce } }
			);
		}
		createdPosts = [];
		createdMedia = [];
		await context.close();
	} );

	for ( const width of [ 1440, 800, 375, 320 ] ) {
		test( `DOM order matches visual order at ${ width }px`, async ( {
			page,
		} ) => {
			await page.setViewportSize( { width, height: 900 } );
			await page.goto( `${ BASE }/?kind=article` );

			expect( await articleTitlesByBox( page ) ).toEqual(
				await articleTitlesInDom( page )
			);
		} );
	}

	test( 'the archive does not scroll horizontally at 320px, which is 400 percent of a 1280px window', async ( {
		page,
	} ) => {
		await page.setViewportSize( { width: 320, height: 900 } );
		await page.goto( `${ BASE }/?kind=article` );

		const overflow = await page.evaluate(
			() =>
				document.documentElement.scrollWidth -
				document.documentElement.clientWidth
		);
		expect( overflow ).toBeLessThanOrEqual( 0 );
	} );

	test( 'the page-one lead spans the grid and reflows its picture', async ( {
		page,
	} ) => {
		await page.setViewportSize( { width: 1440, height: 900 } );
		await page.goto( `${ BASE }/?kind=article` );
		const first = page.locator( '.is-style-pkiw-contents li' ).first();
		const second = page.locator( '.is-style-pkiw-contents li' ).nth( 1 );
		const firstBox = await first.boundingBox();
		const secondBox = await second.boundingBox();
		expect( firstBox.width ).toBeGreaterThan( secondBox.width * 1.5 );
		const imageBox = await first.locator( 'img' ).boundingBox();
		const titleBox = await first
			.locator( '.pkiw-contents__title' )
			.boundingBox();
		expect( imageBox.x ).toBeLessThan( titleBox.x );

		await page.setViewportSize( { width: 800, height: 900 } );
		await page.goto( `${ BASE }/?kind=article` );
		const smallFirst = page.locator( '.is-style-pkiw-contents li' ).first();
		const smallImage = await smallFirst.locator( 'img' ).boundingBox();
		const smallTitle = await smallFirst
			.locator( '.pkiw-contents__title' )
			.boundingBox();
		expect( smallImage.y + smallImage.height ).toBeLessThanOrEqual(
			smallTitle.y
		);
	} );

	test( 'page two has no full-span item', async ( { page } ) => {
		await page.setViewportSize( { width: 1440, height: 900 } );
		await page.goto( `${ BASE }/?kind=article&paged=2` );
		const widths = await page
			.locator( '.is-style-pkiw-contents li' )
			.evaluateAll( ( items ) =>
				items.map( ( item ) => item.getBoundingClientRect().width )
			);
		expect( Math.max( ...widths ) - Math.min( ...widths ) ).toBeLessThan(
			80
		);
	} );

	test( 'reading time stays at least twelve pixels', async ( { page } ) => {
		await page.goto( `${ BASE }/?kind=article` );
		const size = await page
			.locator( '.pkiw-reading-time' )
			.first()
			.evaluate( ( el ) =>
				parseFloat(
					el.ownerDocument.defaultView.getComputedStyle( el ).fontSize
				)
			);
		expect( size ).toBeGreaterThanOrEqual( 12 );
	} );

	test( 'clicking the lead picture opens the single through the title overlay', async ( {
		page,
	} ) => {
		await page.goto( `${ BASE }/?kind=article` );
		const href = await page
			.locator( '.is-style-pkiw-contents li' )
			.first()
			.locator( '.pkiw-contents__title a' )
			.getAttribute( 'href' );
		// The title link's ::after overlay sits over the picture, so Playwright's
		// own hit-target check would refuse the click. force: true sends the real
		// mouse click to the point, and the overlay is what receives it.
		await page
			.locator( '.is-style-pkiw-contents li img' )
			.first()
			.click( { force: true } );
		await expect( page ).toHaveURL( href );
	} );

	test( 'keyboard tab stops are title and pager links in DOM order with a visible item ring', async ( {
		page,
	} ) => {
		await page.goto( `${ BASE }/?kind=article` );
		const expected = await page
			.locator( 'main a' )
			.evaluateAll( ( links ) =>
				links
					.filter(
						( link ) =>
							link.closest( '.pkiw-contents__title' ) ||
							link.closest( '.wp-block-query-pagination' )
					)
					.map( ( link ) => link.href )
			);
		const seen = [];
		for ( let i = 0; i < expected.length + 24; i++ ) {
			await page.keyboard.press( 'Tab' );
			const href = await page
				.locator( 'body' )
				.evaluate(
					( body ) =>
						body.ownerDocument.activeElement &&
						body.ownerDocument.activeElement.href
				);
			if (
				href &&
				expected.includes( href ) &&
				! seen.includes( href )
			) {
				seen.push( href );
			}
		}
		expect( seen ).toEqual( expected );

		await page.locator( '.pkiw-contents__title a' ).first().focus();
		const outline = await page
			.locator( '.is-style-pkiw-contents li' )
			.first()
			.evaluate(
				( el ) =>
					el.ownerDocument.defaultView.getComputedStyle( el )
						.outlineStyle
			);
		expect( outline ).toBe( 'solid' );
	} );

	test( 'forced colors gives items a two pixel outline and reduced motion has no transitions', async ( {
		page,
	} ) => {
		await page.emulateMedia( {
			forcedColors: 'active',
			reducedMotion: 'reduce',
		} );
		await page.goto( `${ BASE }/?kind=article` );
		const styles = await page
			.locator( '.is-style-pkiw-contents li' )
			.first()
			.evaluate( ( el ) => {
				const computed =
					el.ownerDocument.defaultView.getComputedStyle( el );
				return {
					outlineWidth: computed.outlineWidth,
					transitionDuration: computed.transitionDuration,
				};
			} );
		expect( styles.outlineWidth ).toBe( '2px' );
		expect( styles.transitionDuration ).toBe( '0s' );
	} );

	test( 'pagination uses core labels and current page state', async ( {
		page,
	} ) => {
		await page.goto( `${ BASE }/?kind=article` );
		await expect(
			page.locator(
				'nav.wp-block-query-pagination[aria-label="Pagination"]'
			)
		).toBeVisible();
		await expect( page.locator( '[aria-current="page"]' ) ).toHaveText(
			'1'
		);
		const nav = page.locator( 'nav.wp-block-query-pagination' );
		await expect(
			nav.getByRole( 'link', { name: /Previous Page/ } )
		).toHaveCount( 0 );
		await expect(
			nav.getByRole( 'link', { name: /Next Page/ } )
		).toHaveCount( 1 );

		await page.goto( `${ BASE }/?kind=article&paged=2` );
		await expect(
			nav.getByRole( 'link', { name: /Previous Page/ } )
		).toHaveCount( 1 );
		await expect(
			nav.getByRole( 'link', { name: /Next Page/ } )
		).toHaveCount( 0 );
	} );

	test( 'article archive main has no axe violations', async ( { page } ) => {
		await page.goto( `${ BASE }/?kind=article` );
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
		expect( results.violations ).toEqual( [] );
	} );

	test( 'sparse article archive has one month, one lead and no pager', async ( {
		browser,
		page,
	} ) => {
		const { context, page: admin, nonce } = await adminRequest( browser );
		const posts = await publishedArticlePosts( admin, nonce );
		expect(
			posts.every( ( post ) => post.title.rendered.includes( RUN ) ),
			'article contents spec expects its seeded kind-article posts to be the only published article posts'
		).toBeTruthy();
		for ( const post of posts ) {
			if ( post.id !== leadPost.id ) {
				await draftPost( admin, nonce, post.id );
			}
		}
		await context.close();

		await page.goto( `${ BASE }/?kind=article` );
		await expect( page.locator( '.pkiw-group__heading' ) ).toHaveCount( 1 );
		await expect(
			page.locator( '.is-style-pkiw-contents li' )
		).toHaveCount( 1 );
		await expect(
			page.locator( 'nav.wp-block-query-pagination' )
		).toHaveCount( 0 );
	} );

	test( 'empty article archive prints the no-results copy and no pager', async ( {
		browser,
		page,
	} ) => {
		const { context, page: admin, nonce } = await adminRequest( browser );
		await draftPost( admin, nonce, leadPost.id );
		await context.close();

		await page.goto( `${ BASE }/?kind=article` );
		await expect( page.getByText( 'Nothing here yet.' ) ).toBeVisible();
		await expect(
			page.locator( 'nav.wp-block-query-pagination' )
		).toHaveCount( 0 );
	} );
} );
