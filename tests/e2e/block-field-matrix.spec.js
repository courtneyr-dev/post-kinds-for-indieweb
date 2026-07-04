/**
 * Editor round-trip coverage for every card block in the field matrix.
 *
 * For each block: insert programmatically with fully-populated attributes,
 * save, reload, and assert every attribute survived the round trip. Dynamic
 * (render.php) blocks additionally assert the front-end display renders the
 * populated values, since a round-trip pass alone doesn't prove render.php
 * actually reads the saved attributes.
 *
 * Fixture is the same field-matrix.json used by tests/js/field-matrix-static
 * and the PHPUnit dynamic-render matrix — single source of truth for block
 * attribute shape (see tests/phpunit/fixtures/field-matrix.json).
 */

const { test, expect } = require( '@playwright/test' );
const matrix = require( '../phpunit/fixtures/field-matrix.json' );

const BASE = process.env.WP_BASE_URL || 'http://localhost:8888';

// Each test does two full editor loads plus a save round trip against a
// single shared wp-env instance — slower than the default 30s budget,
// especially with several workers hitting the same site concurrently.
test.slow();

// sampleFor duplicated here intentionally (specs run without bundling, and
// this file is CommonJS while tests/js/sample-values.js is ESM); MUST match
// tests/js/sample-values.js rule order EXACTLY (type before name patterns —
// Task 1 review finding). Do not reorder.
function sampleFor( attr, def ) {
	const type = Array.isArray( def.type )
		? def.type[ 0 ]
		: def.type || 'string';
	if ( attr === 'layout' ) {
		return def.default ?? 'horizontal';
	}
	if ( type === 'boolean' ) {
		return true;
	}
	if ( type === 'number' || type === 'integer' ) {
		return 4;
	}
	if ( /(url|photo|cover|image)$/i.test( attr ) ) {
		return 'https://example.com/sample-' + attr.toLowerCase();
	}
	if ( /(At|Date)$/.test( attr ) || attr === 'publishDate' ) {
		return '2026-07-04';
	}
	return `Sample ${ attr } value`;
}

// Attributes that legitimately cannot round-trip via the sampler's flat
// values. Keyed by block name; value maps attribute -> written reason. This
// is the only sanctioned escape hatch for the SAVE/RELOAD assertion — do not
// weaken assertions elsewhere. Both entries are schema `enum` attributes
// (see each block's block.json): the sampler's "Sample X value" string isn't
// a member of the enum, so WP's attribute-validation coerces it back to the
// schema default on save — the same class of exception already documented
// in tests/phpunit/integration/BlockFieldRenderTest.php.
const ROUND_TRIP_EXCEPTIONS = {
	'post-kinds-indieweb/media-lookup': {
		selectedItem:
			'Typed "object"; the locked sampler rule order has no object rule, so sampleFor() returns a flat string for an attribute the block schema types as object. WP block attribute validation coerces an invalid object-typed value back to its schema default ({}) on save, which is correct schema behavior, not a round-trip bug. Covered instead below with a real object sample.',
	},
	'post-kinds-indieweb/checkin-card': {
		locationPrivacy:
			'block.json enum (public/approximate/private); "Sample locationPrivacy value" is not a member, so core coerces it back to the schema default (approximate) on save. Matches the BlockFieldRenderTest.php exception for the same attribute.',
	},
	'post-kinds-indieweb/play-card': {
		status: 'block.json enum (playing/completed/abandoned/backlog/wishlist); "Sample status value" is not a member, so core coerces it back to the schema default (playing) on save. Matches the BlockFieldRenderTest.php exception for the same attribute.',
	},
};

// Attributes whose sample never appears verbatim in the front-end render,
// even though they round-trip through save/reload correctly. These are
// display-gating behaviors in render.php (conditional rendering, esc_url
// percent-encoding, query-only args), not attribute-loss bugs — copied
// from tests/phpunit/integration/BlockFieldRenderTest.php::assertion_exceptions()
// so both suites agree on the same documented gaps.
const RENDER_EXCEPTIONS = {
	'*': {
		layout: 'enum controls wrapper class, asserted separately',
	},
	'post-kinds-indieweb/checkin-card': {
		venueType:
			'enum mapped to icon + translated label; unknown values fall back to the Place label, raw slug never echoed',
		locationPrivacy:
			'block.json enum (public/approximate/private); core drops the invalid sample pre-render and the gate value itself is never echoed',
		address:
			'privacy-aware by design: street address renders only when locationPrivacy=public, and enum validation forces the sample back to approximate',
		postalCode:
			'privacy-aware by design: renders only when locationPrivacy=public, same gate as address',
	},
	'post-kinds-indieweb/checkins-feed': {
		count: 'posts_per_page query arg, never echoed',
		venueId: 'venue term query filter, never echoed',
		columns:
			'echoed only as a columns-N wrapper class when layout=grid; fixture layout sample is list',
	},
	'post-kinds-indieweb/listen-card': {
		musicbrainzId:
			'embedded in a canonical musicbrainz.org URL via esc_url(), which percent-encodes the space-containing sample',
	},
	'post-kinds-indieweb/play-card': {
		status: 'block.json enum (playing/completed/...); core drops the invalid sample pre-render and falls back to the default',
	},
	'post-kinds-indieweb/rsvp-card': {
		eventStart:
			'rendered as a <time> only after strtotime() parses it; the non-date sample fails the parse gate by design',
		eventEnd:
			'rendered as a dt-end <data> only after strtotime() parses it, same gate as eventStart',
	},
	'post-kinds-indieweb/venue-detail': {
		venueId:
			'venue term lookup; term 4 does not exist in the test DB so the block renders nothing (all its content comes from term meta, not attributes)',
		checkinCount:
			'posts_per_page limit for the checkins query, never echoed',
	},
	'post-kinds-indieweb/watch-card': {
		showTitle:
			'renders only when mediaType=episode; fixture mediaType sample is not an episode',
		episodeTitle:
			'renders only when mediaType=episode as part of the SxE episode string, same gate as showTitle',
		tmdbId: 'embedded in a canonical themoviedb.org URL via esc_url(), which percent-encodes the space-containing sample',
		imdbId: 'embedded in a canonical imdb.com URL via esc_url(), which percent-encodes the space-containing sample',
	},
};

test.beforeEach( async ( { page } ) => {
	// Login to WordPress admin (same pattern as the other e2e specs — no
	// shared storageState/global-setup exists in this suite yet).
	await page.goto( `${ BASE }/wp-login.php` );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await page.waitForURL( '**/wp-admin/**' );
} );

for ( const [ name, def ] of Object.entries( matrix ) ) {
	const roundTripExceptions = ROUND_TRIP_EXCEPTIONS[ name ] || {};
	const attrsToCheck = Object.keys( def.attributes ).filter(
		( attr ) => ! ( attr in roundTripExceptions )
	);
	const renderExceptions = {
		...RENDER_EXCEPTIONS[ '*' ],
		...( RENDER_EXCEPTIONS[ name ] || {} ),
	};

	test( `${ name }: attributes round-trip through save/reload`, async ( {
		page,
	} ) => {
		await page.goto( `${ BASE }/wp-admin/post-new.php` );
		// Dismiss welcome guide via preference (never click-race the modal).
		await page.evaluate( () =>
			window.wp.data
				.dispatch( 'core/preferences' )
				.set( 'core', 'welcomeGuide', false )
		);
		await page
			.locator( 'iframe[name="editor-canvas"]' )
			.contentFrame()
			.locator( '.is-root-container' )
			.waitFor( { timeout: 30000 } );

		const attrs = Object.fromEntries(
			attrsToCheck.map( ( attr ) => [
				attr,
				sampleFor( attr, def.attributes[ attr ] ),
			] )
		);

		await page.evaluate(
			( { blockName, blockAttrs } ) => {
				const block = window.wp.blocks.createBlock(
					blockName,
					blockAttrs
				);
				window.wp.data
					.dispatch( 'core/block-editor' )
					.insertBlocks( block );
			},
			{ blockName: name, blockAttrs: attrs }
		);

		await page.evaluate( () =>
			window.wp.data.dispatch( 'core/editor' ).savePost()
		);
		await page.waitForFunction(
			() => ! window.wp.data.select( 'core/editor' ).isSavingPost()
		);

		const postId = await page.evaluate( () =>
			window.wp.data.select( 'core/editor' ).getCurrentPostId()
		);
		await page.goto(
			`${ BASE }/wp-admin/post.php?post=${ postId }&action=edit`
		);
		await page
			.locator( 'iframe[name="editor-canvas"]' )
			.contentFrame()
			.locator( '.is-root-container' )
			.waitFor( { timeout: 30000 } );

		const saved = await page.evaluate( ( blockName ) => {
			const blocks = window.wp.data
				.select( 'core/block-editor' )
				.getBlocks();
			const match = blocks.find( ( b ) => b.name === blockName );
			return match ? match.attributes : null;
		}, name );

		expect( saved, `${ name } block missing after reload` ).not.toBeNull();
		for ( const attr of attrsToCheck ) {
			expect(
				saved[ attr ],
				`${ name }.${ attr } lost in round-trip`
			).toEqual( attrs[ attr ] );
		}

		if ( def.render === 'dynamic' ) {
			const front = await page.request.get( `${ BASE }/?p=${ postId }` );
			expect(
				front.ok(),
				`${ name } front-end must be reachable`
			).toBeTruthy();
			const html = await front.text();
			for ( const attr of attrsToCheck ) {
				if ( attr in renderExceptions ) {
					continue;
				}
				const attrDef = def.attributes[ attr ];
				if ( attrDef.type === 'boolean' ) {
					continue;
				}
				expect(
					html,
					`${ name }.${ attr } not present in front-end render`
				).toContain( String( attrs[ attr ] ) );
			}
		}
	} );
}

// selectedItem is excepted from the matrix loop above (object attribute, flat
// string sample would be coerced away by schema validation); cover its real
// round-trip path here with a genuine object value.
test( 'post-kinds-indieweb/media-lookup: selectedItem object round-trips', async ( {
	page,
} ) => {
	await page.goto( `${ BASE }/wp-admin/post-new.php` );
	await page.evaluate( () =>
		window.wp.data
			.dispatch( 'core/preferences' )
			.set( 'core', 'welcomeGuide', false )
	);
	await page
		.locator( 'iframe[name="editor-canvas"]' )
		.contentFrame()
		.locator( '.is-root-container' )
		.waitFor( { timeout: 30000 } );

	const selectedItem = {
		title: 'Sample item title',
		author: 'Sample item author',
		cover: 'https://example.com/sample-item-cover',
		description: 'Sample item description',
		year: 1999,
		url: 'https://example.com/sample-item-url',
		id: 'sample-item-id',
	};
	const attrs = {
		mediaType: 'book',
		searchQuery: 'Sample searchQuery value',
		selectedItem,
		displayStyle: 'card',
		showImage: true,
		showDescription: true,
		linkToSource: true,
	};

	await page.evaluate( ( blockAttrs ) => {
		const block = window.wp.blocks.createBlock(
			'post-kinds-indieweb/media-lookup',
			blockAttrs
		);
		window.wp.data.dispatch( 'core/block-editor' ).insertBlocks( block );
	}, attrs );

	await page.evaluate( () =>
		window.wp.data.dispatch( 'core/editor' ).savePost()
	);
	await page.waitForFunction(
		() => ! window.wp.data.select( 'core/editor' ).isSavingPost()
	);

	const postId = await page.evaluate( () =>
		window.wp.data.select( 'core/editor' ).getCurrentPostId()
	);
	await page.goto(
		`${ BASE }/wp-admin/post.php?post=${ postId }&action=edit`
	);
	await page
		.locator( 'iframe[name="editor-canvas"]' )
		.contentFrame()
		.locator( '.is-root-container' )
		.waitFor( { timeout: 30000 } );

	const saved = await page.evaluate( () => {
		const blocks = window.wp.data.select( 'core/block-editor' ).getBlocks();
		const match = blocks.find(
			( b ) => b.name === 'post-kinds-indieweb/media-lookup'
		);
		return match ? match.attributes : null;
	} );

	expect( saved, 'media-lookup block missing after reload' ).not.toBeNull();
	expect( saved.selectedItem, 'selectedItem lost in round-trip' ).toEqual(
		selectedItem
	);
} );
