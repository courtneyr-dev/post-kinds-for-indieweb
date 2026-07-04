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
 * Also asserts the `kind` taxonomy term on every created post: the
 * taxonomy registers `note` as its default_term, so before the bridge
 * assigned terms every Micropub post landed as kind=note regardless of
 * its actual kind (wrong badge, wrong kind archive). Builder-only kinds
 * without terms (follow/weather) intentionally keep the note default.
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
	 * @return {Promise<{id: number, content: string, rendered: string, kinds: string[]}>} Created post id, raw and rendered content, and kind term slugs.
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

		const terms = await api.get(
			`${ BASE }/?rest_route=/wp/v2/kind&post=${ id }`
		);
		expect( terms.ok(), 'kind terms must be queryable' ).toBeTruthy();
		const kinds = ( await terms.json() ).map( ( term ) => term.slug );

		return {
			id,
			content: body.content.raw,
			rendered: body.content.rendered,
			kinds,
		};
	}

	test( 'contentless eat-of creates a post with an eat card', async () => {
		const { content, kinds } = await createAndFetch( {
			'eat-of': 'Flat white',
		} );
		expect( content ).toContain( '<!-- wp:post-kinds-indieweb/eat-card' );
		expect( kinds ).toEqual( [ 'eat' ] );
	} );

	test( 'contentless drink-of creates a post with a drink card', async () => {
		const { content, kinds } = await createAndFetch( {
			'drink-of': 'Oat latte',
		} );
		expect( content ).toContain( '<!-- wp:post-kinds-indieweb/drink-card' );
		expect( kinds ).toEqual( [ 'drink' ] );
	} );

	test( 'contentless follow-of creates a post with a u-follow-of link', async () => {
		const { content, kinds } = await createAndFetch( {
			'follow-of': 'https://example.com/author',
		} );
		expect( content ).toContain( 'u-follow-of' );
		expect( content ).toContain( 'https://example.com/author' );
		// No `follow` term exists — builder-only kinds keep the note default.
		expect( kinds ).toEqual( [ 'note' ] );
	} );

	test( 'contentless weather creates a post with a p-weather reading', async () => {
		const { content, kinds } = await createAndFetch( {
			weather: 'Sunny, 28C, light breeze',
		} );
		expect( content ).toContain( 'p-weather' );
		expect( content ).toContain( 'Sunny, 28C, light breeze' );
		// No `weather` term exists — builder-only kinds keep the note default.
		expect( kinds ).toEqual( [ 'note' ] );
	} );

	test( 'contentless like-of creates a post with a like card', async () => {
		const { content, rendered, kinds } = await createAndFetch( {
			'like-of': 'https://example.com/liked-post',
		} );
		expect( content ).toContain( '<!-- wp:post-kinds-indieweb/like-card' );
		// like-card is dynamic (render.php) — the rendered output proves the
		// block is registered server-side, not just saved as a comment.
		expect( rendered ).toContain( 'u-like-of' );
		expect( rendered ).toContain( 'https://example.com/liked-post' );
		expect( kinds ).toEqual( [ 'like' ] );
	} );

	test( 'contentless repost-of creates a post with a repost card', async () => {
		const { content, rendered, kinds } = await createAndFetch( {
			'repost-of': 'https://example.com/reposted-post',
		} );
		expect( content ).toContain(
			'<!-- wp:post-kinds-indieweb/repost-card'
		);
		// repost-card is dynamic (render.php) — the rendered output proves
		// the block is registered server-side, not just saved as a comment.
		expect( rendered ).toContain( 'u-repost-of' );
		expect( rendered ).toContain( 'https://example.com/reposted-post' );
		expect( kinds ).toEqual( [ 'repost' ] );
	} );

	test( 'contentless bookmark-of creates a post with a bookmark card', async () => {
		const { content, rendered, kinds } = await createAndFetch( {
			'bookmark-of': 'https://example.com/bookmarked-post',
		} );
		expect( content ).toContain(
			'<!-- wp:post-kinds-indieweb/bookmark-card'
		);
		expect( rendered ).toContain( 'u-bookmark-of' );
		expect( rendered ).toContain( 'https://example.com/bookmarked-post' );
		expect( kinds ).toEqual( [ 'bookmark' ] );
	} );

	test( 'in-reply-to reply keeps the body and renders a reply card', async () => {
		const { content, rendered, kinds } = await createAndFetch( {
			'in-reply-to': 'https://example.com/original-post',
			content: 'Strongly agree with this.',
		} );
		expect( content ).toContain( '<!-- wp:post-kinds-indieweb/reply-card' );
		// The body stays in e-content, outside the reply-context card.
		expect( content ).toContain( 'Strongly agree with this.' );
		expect( rendered ).toContain( 'u-in-reply-to' );
		expect( rendered ).toContain( 'https://example.com/original-post' );
		expect( kinds ).toEqual( [ 'reply' ] );
	} );

	test( 'rsvp with in-reply-to stays an rsvp card, not a reply', async () => {
		const { content } = await createAndFetch( {
			'in-reply-to': 'https://example.com/event',
			rsvp: 'yes',
		} );
		expect( content ).toContain( '<!-- wp:post-kinds-indieweb/rsvp-card' );
		expect( content ).not.toContain( 'u-in-reply-to' );
	} );

	test( 'eat-of with content still creates and keeps the body', async () => {
		const { content, kinds } = await createAndFetch( {
			'eat-of': 'Croissant',
			content: 'Buttery perfection',
		} );
		expect( content ).toContain( '<!-- wp:post-kinds-indieweb/eat-card' );
		expect( content ).toContain( 'Buttery perfection' );
		expect( kinds ).toEqual( [ 'eat' ] );
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
		const { content, kinds } = await createAndFetch( {
			location: 'geo:52.37,4.89',
			'mp-place-name': 'Cafe Test',
			photo: `${ photoHost }/wp-content/uploads/pkiw-test-photo.jpg`,
		} );
		expect( content ).toContain(
			'<!-- wp:post-kinds-indieweb/checkin-card'
		);
		expect( content ).toContain( '<!-- wp:image' );
		expect( kinds ).toEqual( [ 'checkin' ] );
	} );

	// Kind term assignment across the remaining kinds — every kind here
	// has a card builder; the term must be assigned alongside the card
	// content regardless of which properties the client sends.
	const kindMatrix = [
		{
			kind: 'listen',
			form: {
				'listen-of': 'https://example.test/track/123',
				name: 'Test Track',
			},
		},
		{
			kind: 'watch',
			form: {
				'watch-of': 'https://example.test/film/456',
				name: 'Test Film',
			},
		},
		{
			kind: 'read',
			form: {
				'read-of': 'https://example.test/book/789',
				name: 'Test Book',
			},
		},
		{
			kind: 'play',
			form: {
				'play-of': 'https://example.test/game/101',
				name: 'Test Game',
			},
		},
		{
			kind: 'rsvp',
			form: { rsvp: 'yes', 'in-reply-to': 'https://example.test/event' },
		},
		{ kind: 'mood', form: { mood: 'joyful' } },
		{
			kind: 'like',
			form: {
				'like-of': 'https://example.test/great-post',
				content: 'So good',
			},
		},
		{
			kind: 'reply',
			form: {
				'in-reply-to': 'https://example.test/a-post',
				content: 'Agreed!',
			},
		},
		{
			kind: 'repost',
			form: {
				'repost-of': 'https://example.test/a-post',
				content: 'Reposting this',
			},
		},
		{
			kind: 'bookmark',
			form: {
				'bookmark-of': 'https://example.test/a-post',
				content: 'Saving for later',
			},
		},
	];

	for ( const { kind, form } of kindMatrix ) {
		test( `${ kind } post gets the kind=${ kind } term`, async () => {
			const { kinds } = await createAndFetch( form );
			expect( kinds ).toEqual( [ kind ] );
		} );
	}

	// Field-level assertion tests: verify that card block attributes are
	// correctly wired from Micropub properties through the builder.
	// The regex extracts attrs JSON from the card block comment; rely on
	// existing createAndFetch to handle post creation, then dig into attrs.

	test( 'eat-of with place and rating lands name, location, and rating in attrs', async () => {
		const { content } = await createAndFetch( {
			'eat-of': 'Sample dish',
			content: 'Sample body',
			rating: '4',
			'mp-place-name': 'Sample venue',
		} );
		const match = content.match(
			/<!-- wp:post-kinds-indieweb\/eat-card ({.*?}) \/?-->/
		);
		expect( match, 'eat-card block must exist' ).toBeTruthy();
		const attrs = JSON.parse( match[ 1 ] );
		expect( attrs.name ).toBe( 'Sample dish' );
		expect( attrs.rating ).toBe( 4 );
		expect( attrs.locationName ).toBe( 'Sample venue' );
	} );

	test( 'checkin with place and photo lands venue and includes image block', async () => {
		const photoHost =
			process.env.PKIW_TEST_PHOTO_HOST ||
			'http://host.docker.internal:8890';
		const { content } = await createAndFetch( {
			location: 'geo:52.37,4.89',
			'mp-place-name': 'Test Cafe',
			photo: `${ photoHost }/wp-content/uploads/pkiw-test-photo.jpg`,
		} );
		const match = content.match(
			/<!-- wp:post-kinds-indieweb\/checkin-card ({.*?}) \/?-->/
		);
		expect( match, 'checkin-card block must exist' ).toBeTruthy();
		const attrs = JSON.parse( match[ 1 ] );
		expect( attrs.venueName ).toBe( 'Test Cafe' );
		// Verify image block was added per issue #38
		expect( content ).toContain( '<!-- wp:image' );
	} );

	test( 'read-of with rating and book metadata lands all fields', async () => {
		const { content } = await createAndFetch( {
			'read-of': 'https://example.test/book/123',
			name: 'Test Book Title',
			author: 'Test Author',
			rating: '5',
		} );
		const match = content.match(
			/<!-- wp:post-kinds-indieweb\/read-card ({.*?}) \/?-->/
		);
		expect( match, 'read-card block must exist' ).toBeTruthy();
		const attrs = JSON.parse( match[ 1 ] );
		expect( attrs.bookTitle ).toBe( 'Test Book Title' );
		expect( attrs.authorName ).toBe( 'Test Author' );
		expect( attrs.rating ).toBe( 5 );
	} );

	test( 'like-of with content lands url and description in attrs', async () => {
		const { content } = await createAndFetch( {
			'like-of': 'https://example.test/post/liked',
			content: 'I really like this',
		} );
		const match = content.match(
			/<!-- wp:post-kinds-indieweb\/like-card ({.*?}) \/?-->/
		);
		expect( match, 'like-card block must exist' ).toBeTruthy();
		const attrs = JSON.parse( match[ 1 ] );
		expect( attrs.url ).toBe( 'https://example.test/post/liked' );
		expect( attrs.description ).toBe( 'I really like this' );
	} );

	test( 'reply with in-reply-to url lands url in attrs', async () => {
		const { content } = await createAndFetch( {
			'in-reply-to': 'https://example.test/post/original',
			content: 'Great point about this',
		} );
		const match = content.match(
			/<!-- wp:post-kinds-indieweb\/reply-card ({.*?}) \/?-->/
		);
		expect( match, 'reply-card block must exist' ).toBeTruthy();
		const attrs = JSON.parse( match[ 1 ] );
		expect( attrs.url ).toBe( 'https://example.test/post/original' );
		// Reply body is stored in e-content, not in card attrs
		expect( content ).toContain( 'Great point about this' );
	} );
} );
