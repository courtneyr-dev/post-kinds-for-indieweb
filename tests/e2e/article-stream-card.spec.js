/**
 * E2E: article Stream card overlay and caption links (issue 263).
 */

const { test, expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;

const BASE = process.env.WP_BASE_URL || 'http://localhost:8888';
const RUN = Date.now().toString( 36 );

let createdPosts = [];
let createdMedia = [];
let articleKind = 0;
let noteKind = 0;
let articlePage = null;
let notePage = null;
let a06Post = null;

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

async function uploadImage( page, nonce, postId, name, caption = '' ) {
	const res = await page.request.post(
		`${ BASE }/?rest_route=/wp/v2/media&post=${ postId }`,
		{
			headers: {
				'X-WP-Nonce': nonce,
				'Content-Disposition': `attachment; filename="${ name }.png"`,
				'Content-Type': 'image/png',
			},
			data: pngBuffer(),
		}
	);
	expect( res.ok() ).toBeTruthy();
	const media = await res.json();
	createdMedia.push( media.id );
	if ( caption ) {
		const update = await page.request.post(
			`${ BASE }/?rest_route=/wp/v2/media/${ media.id }`,
			{
				headers: { 'X-WP-Nonce': nonce },
				data: { caption },
			}
		);
		expect( update.ok() ).toBeTruthy();
	}
	return media.id;
}

async function createPost( page, nonce, data ) {
	const res = await page.request.post( `${ BASE }/?rest_route=/wp/v2/posts`, {
		headers: { 'X-WP-Nonce': nonce },
		data,
	} );
	expect( res.ok() ).toBeTruthy();
	const post = await res.json();
	createdPosts.push( post.id );
	return post;
}

// A post, not a page: the test environment's IndieAuth scopes allow creating
// posts over REST but refuse pages (403 rest_cannot_create). The post holds the
// Query Loop and is not itself a kind-article or kind-note post.
async function createPage( page, nonce, title, content ) {
	const res = await page.request.post( `${ BASE }/?rest_route=/wp/v2/posts`, {
		headers: { 'X-WP-Nonce': nonce },
		data: {
			title,
			content,
			status: 'publish',
		},
	} );
	if ( ! res.ok() ) {
		throw new Error(
			`Creating the page failed: ${ res.status() } ${ await res.text() }`
		);
	}
	const post = await res.json();
	createdPosts.push( post.id );
	return post;
}

function streamQueryPageContent( kind ) {
	return `<!-- wp:query {"queryId":263,"query":{"perPage":10,"postType":"post","inherit":false,"taxQuery":{"kind":[${ kind }]}}} -->
<div class="wp-block-query"><!-- wp:post-template -->
<!-- wp:post-kinds-indieweb/stream-card /-->
<!-- /wp:post-template --></div>
<!-- /wp:query -->`;
}

test.describe( 'article Stream card overlay', () => {
	test.describe.configure( { mode: 'serial' } );

	test.beforeAll( async ( { browser } ) => {
		const { context, page, nonce } = await adminRequest( browser );
		articleKind = await kindId( page, nonce, 'article' );
		noteKind = await kindId( page, nonce, 'note' );

		a06Post = await createPost( page, nonce, {
			title: `A06 article ${ RUN }`,
			content:
				'<!-- wp:paragraph --><p>Long body for the image article.</p><!-- /wp:paragraph -->',
			status: 'publish',
			kind: [ articleKind ],
			date: '2026-08-20T10:00:00',
		} );
		const hero = await uploadImage(
			page,
			nonce,
			a06Post.id,
			`a06-hero-${ RUN }`,
			'Photo by <a href="https://example.com/credit">Example Credit</a>'
		);
		for ( let i = 0; i < 6; i++ ) {
			await uploadImage(
				page,
				nonce,
				a06Post.id,
				`a06-extra-${ RUN }-${ i }`
			);
		}
		const featured = await page.request.post(
			`${ BASE }/?rest_route=/wp/v2/posts/${ a06Post.id }`,
			{
				headers: { 'X-WP-Nonce': nonce },
				data: { featured_media: hero },
			}
		);
		expect( featured.ok() ).toBeTruthy();

		await createPost( page, nonce, {
			title: `A10 article ${ RUN }`,
			excerpt: `Manual deck ${ RUN }`,
			content:
				'<!-- wp:paragraph --><p>Long body for the deck article.</p><!-- /wp:paragraph -->',
			status: 'publish',
			kind: [ articleKind ],
			date: '2026-08-19T10:00:00',
		} );
		await createPost( page, nonce, {
			title: `Plain article ${ RUN }`,
			content: '',
			status: 'publish',
			kind: [ articleKind ],
			date: '2026-08-18T10:00:00',
		} );
		await createPost( page, nonce, {
			title: `Note card ${ RUN }`,
			content:
				'<!-- wp:paragraph --><p>Note body.</p><!-- /wp:paragraph -->',
			status: 'publish',
			kind: [ noteKind ],
			date: '2026-08-17T10:00:00',
		} );

		articlePage = await createPage(
			page,
			nonce,
			`Article cards ${ RUN }`,
			streamQueryPageContent( articleKind )
		);
		notePage = await createPage(
			page,
			nonce,
			`Note cards ${ RUN }`,
			streamQueryPageContent( noteKind )
		);
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

	test( 'clicking the A06 picture and A10 deck opens each single', async ( {
		page,
	} ) => {
		await page.goto( articlePage.link );
		const a06 = page.locator( '.pk-card--stream.k-article', {
			hasText: `A06 article ${ RUN }`,
		} );
		const a06Href = await a06
			.locator( '.pk-title a' )
			.getAttribute( 'href' );
		// The title link's ::after overlay covers the picture, so Playwright's
		// hit-target check would refuse a normal click. force: true sends the
		// real mouse click to the point; the overlay receives it.
		await a06.locator( '.pk-media img' ).click( { force: true } );
		await expect( page ).toHaveURL( a06Href );

		await page.goto( articlePage.link );
		const a10 = page.locator( '.pk-card--stream.k-article', {
			hasText: `A10 article ${ RUN }`,
		} );
		const a10Href = await a10
			.locator( '.pk-title a' )
			.getAttribute( 'href' );
		await a10.locator( '.pk-excerpt' ).click( { force: true } );
		await expect( page ).toHaveURL( a10Href );
	} );

	test( 'each card has one title tab stop outside the caption and the caption link remains reachable', async ( {
		page,
	} ) => {
		await page.goto( articlePage.link );
		await expect(
			page.locator( '.pk-card--stream.k-article' )
		).toHaveCount( 3 );

		const outsideCounts = await page
			.locator( '.pk-card--stream.k-article' )
			.evaluateAll( ( cards ) =>
				cards.map(
					( card ) =>
						Array.from( card.querySelectorAll( 'a[href]' ) ).filter(
							( link ) => ! link.closest( '.pk-media__caption' )
						).length
				)
			);
		expect( outsideCounts ).toEqual( [ 1, 1, 1 ] );

		const caption = page.locator(
			'.pk-media__caption a[href="https://example.com/credit"]'
		);
		await expect( caption ).toBeVisible();
		await page.locator( '.pk-title a' ).first().focus();
		await page.keyboard.press( 'Tab' );
		await expect( caption ).toBeFocused();
	} );

	test( 'caption links sit above the title overlay and keep their own focus outline', async ( {
		page,
	} ) => {
		await page.goto( articlePage.link );
		const caption = page.locator(
			'.pk-media__caption a[href="https://example.com/credit"]'
		);
		const hit = await caption.evaluate( ( link ) => {
			const box = link.getBoundingClientRect();
			const found = document.elementFromPoint(
				box.left + box.width / 2,
				box.top + box.height / 2
			);
			return found === link;
		} );
		expect( hit ).toBeTruthy();

		await caption.focus();
		const outline = await caption.evaluate( ( link ) => {
			const computed =
				link.ownerDocument.defaultView.getComputedStyle( link );
			return {
				style: computed.outlineStyle,
				width: computed.outlineWidth,
			};
		} );
		expect( outline.style ).toBe( 'solid' );
		expect( outline.width ).toBe( '2px' );
	} );

	test( 'the focused title draws a visible outline on the article card', async ( {
		page,
	} ) => {
		await page.goto( articlePage.link );
		const card = page.locator( '.pk-card--stream.k-article' ).first();
		await card.locator( '.pk-title a' ).focus();
		const outline = await card.evaluate(
			( el ) =>
				el.ownerDocument.defaultView.getComputedStyle( el ).outlineStyle
		);
		expect( outline ).toBe( 'solid' );
	} );

	test( 'forced colors gives each article card a two pixel outline', async ( {
		page,
	} ) => {
		await page.emulateMedia( { forcedColors: 'active' } );
		await page.goto( articlePage.link );
		const widths = await page
			.locator( '.pk-card--stream.k-article' )
			.evaluateAll( ( cards ) =>
				cards.map(
					( card ) =>
						card.ownerDocument.defaultView.getComputedStyle( card )
							.outlineWidth
				)
			);
		expect( widths ).toEqual( [ '2px', '2px', '2px' ] );
	} );

	test( 'article cards do not scroll horizontally at 320 CSS pixels', async ( {
		page,
	} ) => {
		await page.setViewportSize( { width: 320, height: 800 } );
		await page.goto( articlePage.link );
		const overflow = await page.evaluate(
			() =>
				document.documentElement.scrollWidth -
				document.documentElement.clientWidth
		);
		expect( overflow ).toBeLessThanOrEqual( 0 );
	} );

	test( 'kind label title date picture and deck run top to bottom', async ( {
		page,
	} ) => {
		await page.goto( articlePage.link );
		const card = page.locator( '.pk-card--stream.k-article', {
			hasText: `A06 article ${ RUN }`,
		} );
		const tops = await card.evaluate( ( el ) =>
			[
				'.pk-kindlabel',
				'.pk-title',
				'.dt-published',
				'.pk-media',
				'.pk-excerpt',
			]
				.map( ( selector ) => el.querySelector( selector ) )
				.filter( Boolean )
				.map( ( node ) =>
					Math.round( node.getBoundingClientRect().top )
				)
		);
		expect( tops ).toEqual( [ ...tops ].sort( ( a, b ) => a - b ) );
	} );

	test( 'article Stream card page has no axe violations', async ( {
		page,
	} ) => {
		await page.goto( articlePage.link );
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

	test( 'article stream stylesheet loads on article cards and not note cards', async ( {
		page,
	} ) => {
		// A registered sheet that carries a `path` is inlined by core when it is
		// small, so it is either a <link> or a <style>, both named after the handle.
		const sheetLoaded = () =>
			page.evaluate(
				() =>
					null !==
					document.querySelector(
						'#pkiw-article-stream-card-css, #pkiw-article-stream-card-inline-css'
					)
			);

		await page.goto( articlePage.link );
		expect( await sheetLoaded() ).toBe( true );

		await page.goto( notePage.link );
		expect( await sheetLoaded() ).toBe( false );
	} );
} );
