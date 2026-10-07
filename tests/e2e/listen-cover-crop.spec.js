/**
 * E2E: a listen card's library cover keeps its 16:10 crop (issue 226).
 *
 * Uploads a square image over REST as the admin, creates a listen post
 * whose card cover is that upload, then measures the cover on the single
 * post as an anonymous visitor. wp_get_attachment_image() prints the
 * library image's width and height, and on a theme with no img height
 * reset (wp-env runs the default theme) the height attribute becomes a
 * fixed CSS height unless `.pk-embed--photo > img` sets `height: auto`.
 */

const { test, expect } = require( '@playwright/test' );

const BASE = process.env.WP_BASE_URL || 'http://localhost:8888';
const RUN = Date.now().toString( 36 );
const SIDE = 1200;

let postId = 0;
let mediaId = 0;
let permalink = '';

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

test.describe( 'listen card library cover', () => {
	test.describe.configure( { mode: 'serial' } );

	test.beforeAll( async ( { browser } ) => {
		const { context, page, nonce } = await adminRequest( browser );

		const png = await page.evaluate( ( side ) => {
			const canvas = document.createElement( 'canvas' );
			canvas.width = side;
			canvas.height = side;
			canvas.getContext( '2d' ).fillRect( 0, 0, side, side );
			return canvas.toDataURL( 'image/png' ).split( ',' )[ 1 ];
		}, SIDE );

		const media = await page.request.post(
			`${ BASE }/?rest_route=/wp/v2/media`,
			{
				headers: { 'X-WP-Nonce': nonce },
				multipart: {
					file: {
						name: `square-cover-${ RUN }.png`,
						mimeType: 'image/png',
						buffer: Buffer.from( png, 'base64' ),
					},
				},
			}
		);
		expect( media.ok() ).toBeTruthy();
		const upload = await media.json();
		mediaId = upload.id;

		const kinds = await page.request.get(
			`${ BASE }/?rest_route=/wp/v2/kind&slug=listen`,
			{ headers: { 'X-WP-Nonce': nonce } }
		);
		const listen = ( await kinds.json() )[ 0 ].id;

		const card = {
			trackTitle: `Square ${ RUN }`,
			artistName: 'Fictional Band',
			coverImage: upload.source_url,
			coverImageAlt: 'A square sleeve',
		};
		const post = await page.request.post(
			`${ BASE }/?rest_route=/wp/v2/posts`,
			{
				headers: { 'X-WP-Nonce': nonce },
				data: {
					title: `Square cover ${ RUN }`,
					content: `<!-- wp:post-kinds-indieweb/listen-card ${ JSON.stringify(
						card
					) } /-->`,
					status: 'publish',
					kind: [ listen ],
				},
			}
		);
		expect( post.ok() ).toBeTruthy();
		const created = await post.json();
		postId = created.id;
		permalink = created.link;
		await context.close();
	} );

	test.afterAll( async ( { browser } ) => {
		const { context, page, nonce } = await adminRequest( browser );
		if ( postId ) {
			await page.request.delete(
				`${ BASE }/?rest_route=/wp/v2/posts/${ postId }&force=true`,
				{ headers: { 'X-WP-Nonce': nonce } }
			);
		}
		if ( mediaId ) {
			await page.request.delete(
				`${ BASE }/?rest_route=/wp/v2/media/${ mediaId }&force=true`,
				{ headers: { 'X-WP-Nonce': nonce } }
			);
		}
		await context.close();
	} );

	test( 'a square upload cover renders in a 16:10 box', async ( {
		page,
	} ) => {
		await page.goto( permalink );

		const cover = page.locator( '.pk-embed--photo > img' );
		await expect( cover ).toHaveCount( 1 );
		await expect( cover ).toHaveAttribute( 'height', String( SIDE ) );
		await expect( cover ).toHaveAttribute( 'srcset', /\d+w/ );

		const box = await cover.boundingBox();
		expect( box.width ).toBeGreaterThan( 0 );
		expect( box.width / box.height ).toBeCloseTo( 1.6, 1 );
	} );
} );
