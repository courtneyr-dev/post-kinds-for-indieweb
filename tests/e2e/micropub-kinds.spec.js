/**
 * Integration tests for Micropub post creation across kinds.
 *
 * Regression suite for the "phantom post" bug: contentless kind posts
 * (eat/drink/follow/weather — the shapes Outpost sends) died inside the
 * Micropub plugin with empty_content because wp_insert_post() rejects
 * posts whose content, title, and excerpt are all empty — while the
 * endpoint still answered 2xx with no Location header. The fix supplies
 * card-block content on the `micropub_post_content` filter BEFORE insert.
 *
 * Also covers issue #38: a checkin carrying a `photo` property must
 * include a core/image block, not just the checkin card.
 *
 * Requires: wp-env with micropub + indieauth active (see .wp-env.json),
 * the tests/env/pkiw-test-auth.php fixture (grants Micropub scope to
 * application-password requests), and WP_APP_PASSWORD in the env.
 * Uses the ?rest_route= form so a broken pretty-permalink state can
 * never silently route requests to the front page.
 *
 * @package
 */

const { test, expect } = require( '@playwright/test' );

const BASE = process.env.WP_BASE_URL || 'http://localhost:8888';
const ENDPOINT = `${ BASE }/?rest_route=/micropub/1.0/endpoint`;

test.describe( 'Micropub kind creation', () => {
	/** @type {import('@playwright/test').APIRequestContext} */
	let api;
	const createdPostIds = [];

	test.beforeAll( async ( { playwright } ) => {
		api = await playwright.request.newContext( {
			// Empty cookie jar: config-level storageState (if any) must not
			// ride along — WP REST rejects cookie auth without a nonce even
			// when Basic auth is valid.
			storageState: { cookies: [], origins: [] },
			extraHTTPHeaders: {
				Authorization: `Basic ${ Buffer.from(
					`admin:${ process.env.WP_APP_PASSWORD || 'password' }`
				).toString( 'base64' ) }`,
			},
		} );
	} );

	test.afterAll( async () => {
		for ( const id of createdPostIds ) {
			await api.delete(
				`${ BASE }/?rest_route=/wp/v2/posts/${ id }&force=true`
			);
		}
		await api.dispose();
	} );

	/**
	 * POST a form-encoded h-entry and assert a real post was created.
	 *
	 * @param {Record<string, string>} form Form fields (h=entry added).
	 * @return {Promise<{id: number, content: string}>} Created post id + raw content.
	 */
	async function createAndFetch( form ) {
		const res = await api.post( ENDPOINT, {
			form: { h: 'entry', ...form },
		} );
		expect( res.status(), 'Micropub create must answer 201' ).toBe( 201 );
		expect(
			res.headers().location,
			'created post must have a Location'
		).toBeTruthy();

		// Resolve the created post id WITHOUT querying "the newest post"
		// (tests run in parallel workers, so that races between tests):
		// 1. Micropub's response body carries the insert args incl. ID;
		// 2. plain-permalink Locations carry ?p=<id>;
		// 3. pretty-permalink Locations (CI) resolve via a REST slug lookup
		//    — title-less posts get numeric-ish slugs like /9-2/, so the
		//    URL path alone cannot be trusted for an id.
		const location = res.headers().location;
		let id = null;
		try {
			const created = await res.json();
			if ( created && Number.isInteger( created.ID ) && created.ID > 0 ) {
				id = created.ID;
			}
		} catch {
			// Non-JSON body; fall through to URL-based resolution.
		}
		if ( ! id ) {
			const match = location.match( /[?&]p=(\d+)/ );
			if ( match ) {
				id = parseInt( match[ 1 ], 10 );
			}
		}
		if ( ! id ) {
			const slug = new URL( location ).pathname.replace( /\//g, '' );
			const bySlug = await api.get(
				`${ BASE }/?rest_route=/wp/v2/posts&slug=${ slug }&context=edit`
			);
			const matches = await bySlug.json();
			if ( Array.isArray( matches ) && matches[ 0 ] ) {
				id = matches[ 0 ].id;
			}
		}
		expect( id, `resolvable post id from ${ location }` ).toBeTruthy();
		createdPostIds.push( id );

		const post = await api.get(
			`${ BASE }/?rest_route=/wp/v2/posts/${ id }&context=edit`
		);
		expect( post.ok(), 'created post must be queryable' ).toBeTruthy();
		const body = await post.json();
		return { id, content: body.content.raw };
	}

	test( 'contentless eat-of creates a post with an eat card', async () => {
		const { content } = await createAndFetch( { 'eat-of': 'Flat white' } );
		expect( content ).toContain( '<!-- wp:post-kinds-indieweb/eat-card' );
	} );

	test( 'contentless drink-of creates a post with a drink card', async () => {
		const { content } = await createAndFetch( { 'drink-of': 'Oat latte' } );
		expect( content ).toContain( '<!-- wp:post-kinds-indieweb/drink-card' );
	} );

	test( 'contentless follow-of creates a post with a u-follow-of link', async () => {
		const { content } = await createAndFetch( {
			'follow-of': 'https://example.com/author',
		} );
		expect( content ).toContain( 'u-follow-of' );
		expect( content ).toContain( 'https://example.com/author' );
	} );

	test( 'contentless weather creates a post with a p-weather reading', async () => {
		const { content } = await createAndFetch( {
			weather: 'Sunny, 28C, light breeze',
		} );
		expect( content ).toContain( 'p-weather' );
		expect( content ).toContain( 'Sunny, 28C, light breeze' );
	} );

	test( 'eat-of with content still creates and keeps the body', async () => {
		const { content } = await createAndFetch( {
			'eat-of': 'Croissant',
			content: 'Buttery perfection',
		} );
		expect( content ).toContain( '<!-- wp:post-kinds-indieweb/eat-card' );
		expect( content ).toContain( 'Buttery perfection' );
	} );

	test( 'checkin with a photo includes a core/image block (#38)', async () => {
		// Micropub sideloads the photo URL from INSIDE the container, where
		// the host's localhost:8890 doesn't exist. Use the docker host
		// alias (macOS) or the runner's docker bridge IP (CI sets
		// PKIW_TEST_PHOTO_HOST); tests/uploads is mapped into
		// wp-content/uploads.
		const photoHost =
			process.env.PKIW_TEST_PHOTO_HOST ||
			'http://host.docker.internal:8890';
		const { content } = await createAndFetch( {
			location: 'geo:52.37,4.89',
			'mp-place-name': 'Cafe Test',
			photo: `${ photoHost }/wp-content/uploads/pkiw-test-photo.jpg`,
		} );
		expect( content ).toContain(
			'<!-- wp:post-kinds-indieweb/checkin-card'
		);
		expect( content ).toContain( '<!-- wp:image' );
	} );
} );
