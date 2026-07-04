# Read Card × Kindle Embed × Block Bindings + Field-Matrix Coverage — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every meta field of every kind-card block is provably populated and displayed (editor, front end, Micropub wire), and read posts gain auto-completed book data (ISBN and the rest) that powers both the Read card and the core Amazon Kindle embed variation via Block Bindings.

**Architecture:** A generated field matrix (from every `block.json`) becomes the single source of truth that drives four coverage layers: PHP render tests, JS serialize tests, editor e2e, and Micropub wire tests. On top of that foundation, book fields flow: Read card attrs → `_postkind_read_*` post meta (save-time sync) → the existing `post-kinds/kind-meta` bindings source (extended with book keys + a computed Kindle URL key) → core blocks and a Kindle preview. A `Book_Completion` service fills missing fields from ISBN/title/Amazon-URL via the existing Open Library → Google Books → Hardcover API classes.

**Tech Stack:** WP 7.0 Block Bindings (`block_bindings_supported_attributes` filter, since 6.9), PHPUnit (unit + `WP_UnitTestCase` integration), Jest + `@wordpress/blocks`, Playwright, existing PKIW API clients.

## Global Constraints

- Min WP 7.0, min PHP 8.2 (from CLAUDE.md).
- WPCS: tabs, Yoda conditions; PHPStan level 6 must pass (`composer analyze`).
- i18n: every string wrapped with text domain `post-kinds-for-indieweb`.
- Security trinity: sanitize input → validate → escape output; REST routes need `permission_callback` with `current_user_can( 'edit_posts' )`.
- Blocks: `apiVersion: 3`; registration reads `src/blocks/` (NOT `build/`) — new/changed `block.json` + `render.php` must live in `src/blocks/<name>/`; `npm run build` refreshes editor JS.
- Bindings source name is `post-kinds/kind-meta`; meta prefix is `_postkind_` (`Meta_Fields::PREFIX`); public REST aliases use `pk_*`.
- Existing hook/filter naming style: `pkiw_*` filters (see `pkiw_block_bindings_keys`).
- **Checkout warning:** `~/Projects/post-kinds-for-indieweb` is a shared checkout currently on branch `fix/wp-env-activation-race`. Execute this plan in a **git worktree of `origin/main`** placed under `$HOME` (colima requires `$HOME` mounts). Local wp-env ports: use `.wp-env.override.json` {8890,8891} convention or reuse the running env at 8895 (`~/.loopd-e2e/post-kinds-for-indieweb`, login admin/password). Playwright: always set `WP_BASE_URL` (default 8888 belongs to Stream Deck).
- `wp_update_post` with serialized block markup requires `wp_slash()` (attrs contain `-`-escapes).
- Landed context this plan builds on (all merged to main 2026-07-03/04): PR #61 Micropub kind-term fix, PR #62 `Taxonomy::get_first_block_kind()` / `sync_kind_from_first_block()` (includes/class-taxonomy.php:469/501), PR #63/#64 phantom-post fix + like/repost/bookmark/reply cards (`src/blocks/{bookmark,reply,repost}-card` exist).
- Verified environment facts: the "core Kindle block" is the `core/embed` variation `amazon-kindle` (client-side registered; preview URLs are `https://read.amazon.com/kp/embed?asin=<ASIN>`); WP 7.0's default bindings allowlist does NOT include `core/embed`, but the `block_bindings_supported_attributes` filter (wp-includes/block-bindings.php:141) makes it opt-in-able; `core/embed` front-end markup is static saved HTML, so a server bridge must rewrite the iframe URL (Task 12).
- Meta_Fields already defines the full read suffix set: `read_title, read_author, read_isbn, read_publisher, read_publish_date, read_pages, read_progress, read_cover, read_url, read_rating, read_review, read_status, read_started_at, read_finished_at`. Only `read_asin` is new.

---

## Part A — Field-matrix foundation

### Task 1: Field-matrix generator + drift guard

**Files:**
- Create: `bin/generate-field-matrix.php`
- Create: `tests/fixtures/field-matrix.json` (generated, committed)
- Test: `tests/phpunit/unit/FieldMatrixTest.php`

**Interfaces:**
- Produces: `tests/fixtures/field-matrix.json` — shape `{ "<blockName>": { "render": "dynamic"|"static", "attributes": { "<attr>": { "type": "...", "sample": <value> } } } }`. Consumed by Tasks 2, 3, 4, 5.
- Produces: sample-value rules (MUST be mirrored exactly in the JS sampler of Task 3), **evaluated in this order — type rules BEFORE name patterns**, so boolean attrs like `showDate`/`showImage` never receive string samples (Task 1 review finding, 2026-07-04): (1) equals `layout` → keep block.json default; (2) type `boolean` → `true`; (3) type `number`/`integer` → `4`; (4) attr name matches `/(url|photo|cover|image)$/i` → `https://example.com/sample-<attr>`; (5) matches `/(At|Date)$/` or equals `publishDate` → `2026-07-04`; (6) otherwise → `Sample <attr> value`. Update the fixture whenever the rules change (`php bin/generate-field-matrix.php`).

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/phpunit/unit/FieldMatrixTest.php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FieldMatrixTest extends TestCase {

	public function test_fixture_matches_block_json_inventory(): void {
		$generate = require dirname( __DIR__, 3 ) . '/bin/generate-field-matrix.php';
		$expected = $generate( dirname( __DIR__, 3 ) . '/src/blocks' );

		$fixture_path = dirname( __DIR__ ) . '/fixtures/field-matrix.json';
		$this->assertFileExists( $fixture_path, 'Run: php bin/generate-field-matrix.php' );

		$actual = json_decode( (string) file_get_contents( $fixture_path ), true );

		$this->assertSame(
			$expected,
			$actual,
			'field-matrix.json is stale — a block.json changed. Regenerate: php bin/generate-field-matrix.php'
		);
	}

	public function test_matrix_covers_every_card_block(): void {
		$fixture = json_decode(
			(string) file_get_contents( dirname( __DIR__ ) . '/fixtures/field-matrix.json' ),
			true
		);
		foreach ( glob( dirname( __DIR__, 3 ) . '/src/blocks/*/block.json' ) as $file ) {
			$name = json_decode( (string) file_get_contents( $file ), true )['name'];
			$this->assertArrayHasKey( $name, $fixture );
		}
	}
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `composer phpunit -- --filter FieldMatrixTest`
Expected: FAIL — `bin/generate-field-matrix.php` missing.

- [ ] **Step 3: Write the generator**

```php
<?php
// bin/generate-field-matrix.php
// Returns a callable so tests can invoke it in-memory; running the file
// directly (php bin/generate-field-matrix.php) writes the fixture.
declare(strict_types=1);

$pkiw_generate_field_matrix = static function ( string $blocks_dir ): array {
	$matrix = [];

	foreach ( glob( $blocks_dir . '/*/block.json' ) as $file ) {
		$json = json_decode( (string) file_get_contents( $file ), true );
		if ( ! is_array( $json ) || empty( $json['name'] ) ) {
			continue;
		}

		$attrs = [];
		foreach ( ( $json['attributes'] ?? [] ) as $attr => $def ) {
			$type = is_array( $def['type'] ?? null ) ? ( $def['type'][0] ?? 'string' ) : ( $def['type'] ?? 'string' );

			if ( 'layout' === $attr ) {
				$sample = $def['default'] ?? 'horizontal';
			} elseif ( preg_match( '/(url|photo|cover|image)$/i', $attr ) ) {
				$sample = 'https://example.com/sample-' . strtolower( $attr );
			} elseif ( preg_match( '/(At|Date)$/', $attr ) || 'publishDate' === $attr ) {
				$sample = '2026-07-04';
			} elseif ( 'number' === $type || 'integer' === $type ) {
				$sample = 4;
			} elseif ( 'boolean' === $type ) {
				$sample = true;
			} else {
				$sample = 'Sample ' . $attr . ' value';
			}

			$attrs[ $attr ] = [
				'type'   => $type,
				'sample' => $sample,
			];
		}

		$matrix[ $json['name'] ] = [
			'render'     => isset( $json['render'] ) ? 'dynamic' : 'static',
			'attributes' => $attrs,
		];
	}

	ksort( $matrix );
	return $matrix;
};

if ( isset( $argv[0] ) && realpath( $argv[0] ) === __FILE__ ) {
	$root   = dirname( __DIR__ );
	$matrix = $pkiw_generate_field_matrix( $root . '/src/blocks' );
	file_put_contents(
		$root . '/tests/fixtures/field-matrix.json',
		json_encode( $matrix, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"
	);
	echo 'Wrote matrix for ' . count( $matrix ) . " blocks\n";
}

return $pkiw_generate_field_matrix;
```

- [ ] **Step 4: Generate the fixture, run tests to verify pass**

Run: `php bin/generate-field-matrix.php && composer phpunit -- --filter FieldMatrixTest`
Expected: `Wrote matrix for 21 blocks` (19 cards + dashboard/feed/venue/lookup/star-rating; exact count = dirs in src/blocks) then PASS.

- [ ] **Step 5: Commit**

```bash
git add bin/generate-field-matrix.php tests/fixtures/field-matrix.json tests/phpunit/unit/FieldMatrixTest.php
git commit -m "test: field-matrix generator + drift guard (single source of truth for block attrs)"
```

### Task 2: Render coverage for every dynamic block

**Files:**
- Test: `tests/phpunit/integration/BlockFieldRenderTest.php`
- Modify (only if failures reveal display bugs): the failing block's `src/blocks/<name>/render.php`

**Interfaces:**
- Consumes: `tests/fixtures/field-matrix.json` (Task 1).
- Produces: `pkiw_render_assertion_exceptions()` — a documented in-test map of attrs that legitimately do not echo their raw sample (e.g. booleans, `layout`, `photoAlt` when photo empty). Later tasks must extend this map rather than skip blocks.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/phpunit/integration/BlockFieldRenderTest.php
declare(strict_types=1);

/**
 * For every dynamic block: render with every attribute populated and
 * assert each sample value reaches the front-end markup; render empty
 * and assert nothing leaks.
 *
 * @group integration
 */
final class BlockFieldRenderTest extends WP_UnitTestCase {

	private static array $matrix;

	public static function wpSetUpBeforeClass(): void {
		self::$matrix = json_decode(
			(string) file_get_contents( dirname( __DIR__ ) . '/fixtures/field-matrix.json' ),
			true
		);
	}

	/**
	 * Attrs whose sample never appears verbatim in output, with the reason.
	 * Format: 'blockName' => [ 'attr' => 'reason' ].
	 */
	private function assertion_exceptions(): array {
		return [
			'*' => [
				'layout' => 'enum controls wrapper class, asserted separately',
			],
			// Populated during implementation as real exceptions surface,
			// each with a one-line justification. An empty-reason entry is
			// a review-blocking smell.
		];
	}

	public function dynamic_blocks(): array {
		$cases = [];
		foreach ( json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/field-matrix.json' ), true ) as $name => $def ) {
			if ( 'dynamic' === $def['render'] ) {
				$cases[ $name ] = [ $name, $def['attributes'] ];
			}
		}
		return $cases;
	}

	/** @dataProvider dynamic_blocks */
	public function test_every_attribute_displays( string $block_name, array $attributes ): void {
		$post_id = self::factory()->post->create();
		$this->go_to( get_permalink( $post_id ) );

		$attrs   = array_map( static fn( $a ) => $a['sample'], $attributes );
		$comment = sprintf(
			'<!-- wp:%s %s /-->',
			str_replace( 'post-kinds-indieweb/', 'post-kinds-indieweb/', $block_name ),
			wp_json_encode( $attrs )
		);

		$html       = do_blocks( $comment );
		$exceptions = array_merge(
			$this->assertion_exceptions()['*'],
			$this->assertion_exceptions()[ $block_name ] ?? []
		);

		foreach ( $attributes as $attr => $def ) {
			if ( isset( $exceptions[ $attr ] ) || in_array( $def['type'], [ 'boolean' ], true ) ) {
				continue;
			}
			$this->assertStringContainsString(
				(string) $def['sample'],
				$html,
				"$block_name: attribute '$attr' sample missing from rendered output"
			);
		}

		// layout is asserted as a wrapper class.
		if ( isset( $attributes['layout'] ) ) {
			$this->assertStringContainsString( 'layout-' . $attributes['layout']['sample'], $html );
		}
	}

	/** @dataProvider dynamic_blocks */
	public function test_empty_attributes_leak_nothing( string $block_name ): void {
		$html = do_blocks( "<!-- wp:$block_name /-->" );
		$this->assertStringNotContainsString( 'Sample', $html );
		$this->assertStringNotContainsString( 'undefined', $html );
		$this->assertStringNotContainsString( 'Array', $html );
	}
}
```

- [ ] **Step 2: Run to verify it fails (and catalog real failures)**

Run: `composer phpunit -- --filter BlockFieldRenderTest`
Expected: FAIL. Two failure classes: (a) test-harness gaps (block not registered in test bootstrap — fix bootstrap by calling the plugin's `register_blocks()` on `init`, see `tests/phpunit/bootstrap.php`), and (b) **genuine display bugs** — an attribute the render.php never outputs. Class (b) is the point of this task.

- [ ] **Step 3: Fix each genuine display gap in its render.php, or document it as an exception with a reason**

For every failing attr: either the field should display (add the missing output to `src/blocks/<name>/render.php`, escaped per the trinity — `esc_html`, `esc_url`, `esc_attr`), or it genuinely should not (add to `assertion_exceptions()` with the reason string). No third option.

- [ ] **Step 4: Run until green**

Run: `composer phpunit -- --filter BlockFieldRenderTest`
Expected: PASS, with every exception entry carrying a written reason.

- [ ] **Step 5: Commit**

```bash
git add tests/phpunit/integration/BlockFieldRenderTest.php src/blocks tests/phpunit/bootstrap.php
git commit -m "test: matrix-driven render coverage for all dynamic card blocks + display-gap fixes"
```

### Task 3: Serialize coverage for static blocks

**Files:**
- Create: `tests/js/field-matrix-static.test.js`
- Create: `tests/js/sample-values.js`
- Modify: `package.json` (ensure `test:js` runs jest with `@wordpress/jest-preset-default`; it already exists per CLAUDE.md `npm run test:js`)

**Interfaces:**
- Consumes: `tests/fixtures/field-matrix.json`; block registrations from `src/blocks/index.js`.
- Produces: `sampleFor( attrName, def )` in `tests/js/sample-values.js` — MUST implement the identical rules listed in Task 1's Interfaces block.

- [ ] **Step 1: Write the sampler (mirror of the PHP rules)**

```js
// tests/js/sample-values.js
// Rule order matches the PHP generator EXACTLY (type rules before name
// patterns — Task 1 review finding): layout → boolean → number → url-ish
// name → date-ish name → default string.
export function sampleFor( attr, def ) {
	const type = Array.isArray( def.type ) ? def.type[ 0 ] : def.type || 'string';
	if ( attr === 'layout' ) return def.default ?? 'horizontal';
	if ( type === 'boolean' ) return true;
	if ( type === 'number' || type === 'integer' ) return 4;
	if ( /(url|photo|cover|image)$/i.test( attr ) )
		return 'https://example.com/sample-' + attr.toLowerCase();
	if ( /(At|Date)$/.test( attr ) || attr === 'publishDate' ) return '2026-07-04';
	return `Sample ${ attr } value`;
}
```

- [ ] **Step 2: Write the failing test**

```js
// tests/js/field-matrix-static.test.js
import { createBlock, serialize } from '@wordpress/blocks';
import { registerCoreBlocks } from '@wordpress/block-library';
import matrix from '../fixtures/field-matrix.json';
import { sampleFor } from './sample-values';
import '../../src/blocks'; // registers all PKIW blocks

registerCoreBlocks();

const staticBlocks = Object.entries( matrix ).filter(
	( [ , def ] ) => def.render === 'static'
);

describe.each( staticBlocks )( '%s static save output', ( name, def ) => {
	const attrs = Object.fromEntries(
		Object.entries( def.attributes ).map( ( [ a, d ] ) => [ a, sampleFor( a, d ) ] )
	);

	test( 'every attribute value appears in serialized markup', () => {
		const html = serialize( createBlock( name, attrs ) );
		for ( const [ attr, d ] of Object.entries( def.attributes ) ) {
			if ( d.type === 'boolean' || attr === 'layout' ) continue;
			expect( html ).toContain( String( sampleFor( attr, d ) ) );
		}
	} );

	test( 'empty block serializes without leakage', () => {
		const html = serialize( createBlock( name, {} ) );
		expect( html ).not.toContain( 'undefined' );
		expect( html ).not.toContain( 'Sample' );
	} );
} );
```

- [ ] **Step 3: Run to verify it fails, then fix each gap**

Run: `npm run test:js -- field-matrix-static`
Expected: initial FAILs — same two classes as Task 2. Fix `save.js` display gaps or add a justified exception (same exception-with-reason discipline, kept in the test file).

- [ ] **Step 4: Run until green, then commit**

```bash
npm run test:js -- field-matrix-static
git add tests/js/ src/blocks package.json
git commit -m "test: matrix-driven serialize coverage for static card blocks"
```

### Task 4: Editor round-trip e2e + resurrect visual regression

**Files:**
- Create: `tests/e2e/block-field-matrix.spec.js`
- Modify: `tests/e2e/visual-regression.spec.js` (remove `.skip`, drive from matrix)
- Create: committed Playwright baselines under `tests/e2e/__screenshots__/`

**Interfaces:**
- Consumes: `tests/fixtures/field-matrix.json`; env at `WP_BASE_URL` with `WP_APP_PASSWORD` (same contract as micropub-kinds.spec.js).

- [ ] **Step 1: Write the round-trip spec**

```js
// tests/e2e/block-field-matrix.spec.js
// For every card block: insert programmatically with fully-populated
// attributes, save, reload, and assert every attribute survived the
// round trip; for dynamic blocks also assert front-end display.
const { test, expect } = require( '@playwright/test' );
const matrix = require( '../fixtures/field-matrix.json' );

const BASE = process.env.WP_BASE_URL || 'http://localhost:8888';

// sampleFor duplicated here intentionally (specs run without bundling);
// MUST match tests/js/sample-values.js rules (type before name patterns).
function sampleFor( attr, def ) {
	const type = Array.isArray( def.type ) ? def.type[ 0 ] : def.type || 'string';
	if ( attr === 'layout' ) return def.default ?? 'horizontal';
	if ( type === 'boolean' ) return true;
	if ( type === 'number' || type === 'integer' ) return 4;
	if ( /(url|photo|cover|image)$/i.test( attr ) )
		return 'https://example.com/sample-' + attr.toLowerCase();
	if ( /(At|Date)$/.test( attr ) || attr === 'publishDate' ) return '2026-07-04';
	return `Sample ${ attr } value`;
}

for ( const [ name, def ] of Object.entries( matrix ) ) {
	if ( ! name.startsWith( 'post-kinds-indieweb/' ) ) continue;

	test( `${ name }: attributes round-trip through save/reload`, async ( { page } ) => {
		await page.goto( `${ BASE }/wp-admin/post-new.php` );
		// Dismiss welcome guide via preference (never click-race the modal).
		await page.evaluate( () =>
			window.wp.data.dispatch( 'core/preferences' ).set( 'core', 'welcomeGuide', false )
		);

		const attrs = Object.fromEntries(
			Object.entries( def.attributes ).map( ( [ a, d ] ) => [ a, sampleFor( a, d ) ] )
		);

		await page.evaluate( ( { blockName, blockAttrs } ) => {
			const block = window.wp.blocks.createBlock( blockName, blockAttrs );
			window.wp.data.dispatch( 'core/block-editor' ).insertBlocks( block );
		}, { blockName: name, blockAttrs: attrs } );

		await page.evaluate( () => window.wp.data.dispatch( 'core/editor' ).savePost() );
		await page.waitForFunction(
			() => ! window.wp.data.select( 'core/editor' ).isSavingPost()
		);

		const postId = await page.evaluate( () =>
			window.wp.data.select( 'core/editor' ).getCurrentPostId()
		);
		await page.goto( `${ BASE }/wp-admin/post.php?post=${ postId }&action=edit` );

		const saved = await page.evaluate( ( blockName ) => {
			const blocks = window.wp.data.select( 'core/block-editor' ).getBlocks();
			const match = blocks.find( ( b ) => b.name === blockName );
			return match ? match.attributes : null;
		}, name );

		expect( saved, `${ name } block missing after reload` ).not.toBeNull();
		for ( const [ attr, value ] of Object.entries( attrs ) ) {
			expect( saved[ attr ], `${ name }.${ attr } lost in round-trip` ).toEqual( value );
		}
	} );
}
```

- [ ] **Step 2: Run, fix real round-trip bugs, iterate to green**

Run: `WP_BASE_URL=http://localhost:8895 WP_APP_PASSWORD=<minted> npx playwright test tests/e2e/block-field-matrix.spec.js --project=chromium`
Expected: failures expose attrs the editor drops (inspector control missing/miswired in `edit.js`). Fix each in `src/blocks/<name>/edit.js`; document justified exceptions in the spec, with reasons.

- [ ] **Step 3: Un-skip visual regression and commit baselines**

In `tests/e2e/visual-regression.spec.js`: change `test.describe.skip(` → `test.describe(`; replace the hand-written cases with a matrix loop that inserts each fully-populated block (same `page.evaluate` insert as Step 1) and calls `await expect( page ).toHaveScreenshot( \`${ slug }-populated.png\`, { maxDiffPixelRatio: 0.02 } )`. Then:

Run: `WP_BASE_URL=http://localhost:8895 npx playwright test tests/e2e/visual-regression.spec.js --update-snapshots --project=chromium`
Expected: baselines written; second run passes without `--update-snapshots`.

- [ ] **Step 4: Commit**

```bash
git add tests/e2e/block-field-matrix.spec.js tests/e2e/visual-regression.spec.js tests/e2e/__screenshots__
git commit -m "test: editor round-trip matrix e2e + live visual-regression baselines for every card block"
```

---

## Part B — Micropub / Outpost wire coverage

### Task 5: Builder property-matrix tests + documented gap ledger

**Files:**
- Modify: `tests/phpunit/unit/MicropubContentBuilderTest.php`
- Create: `docs/micropub-field-gaps.md`

**Interfaces:**
- Consumes: `Micropub_Content_Builder::apply()` / the per-kind card builders (includes/class-micropub-content-builder.php), incl. the like/repost/bookmark/reply builders from PRs #63/#64.
- Produces: `docs/micropub-field-gaps.md` — the definitive "Outpost should start sending these" list (follow-on work for the Outpost repo, explicitly out of scope here).

- [ ] **Step 1: Write the per-kind wire matrix test**

```php
// Added to tests/phpunit/unit/MicropubContentBuilderTest.php

/**
 * Wire matrix: every Micropub property the builder maps, asserted onto
 * its card attribute — and every card attribute it can never fill,
 * declared as a known gap so silence is impossible.
 *
 * @return array<string, array{0: array<string,mixed>, 1: string, 2: array<string,string>, 3: string[]}>
 *         [ properties, expected block, attr => expected value, known-unfillable attrs ]
 */
public function wire_matrix(): array {
	return [
		'eat'   => [
			[
				'eat-of'        => [ 'Sample dish' ],
				'content'       => [ 'Sample body' ],
				'rating'        => [ '4' ],
				'mp-place-name' => [ 'Sample venue' ],
				'location'      => [ 'geo:40.0379,-76.3055' ],
			],
			'post-kinds-indieweb/eat-card',
			[
				'name'         => 'Sample dish',
				'rating'       => 4,
				'locationName' => 'Sample venue',
				'geoLatitude'  => 40.0379,
				'geoLongitude' => -76.3055,
			],
			// Attributes no Micropub property maps to today (the gap ledger):
			[ 'cuisine', 'restaurantUrl', 'locationAddress', 'locationLocality', 'locationRegion', 'locationCountry', 'photoAlt', 'ateAt' ],
		],
		// One entry per kind the builder handles: drink, listen, watch, read,
		// play, rsvp, mood, follow, weather, checkin, photo, like, repost,
		// bookmark, reply — built the same way: send every property the
		// builder's <kind>_card() reads (enumerate them from the source, the
		// way eat's five were), assert each mapped attr, list the rest as gaps.
	];
}

/** @dataProvider wire_matrix */
public function test_wire_matrix( array $properties, string $block, array $expected_attrs, array $known_gaps ): void {
	$content = Micropub_Content_Builder::build_for_test( $properties ); // or the existing test seam used by the current 35 tests
	$blocks  = parse_blocks( $content );
	$card    = null;
	foreach ( $blocks as $b ) {
		if ( $b['blockName'] === $block ) {
			$card = $b;
			break;
		}
	}
	$this->assertNotNull( $card, "$block not emitted" );

	foreach ( $expected_attrs as $attr => $value ) {
		$this->assertSame( $value, $card['attrs'][ $attr ] ?? null, "wire → $attr" );
	}

	// The ledger must be TRUE: a gap attr that suddenly gets a value means
	// a mapping was added — update the ledger and the docs, don't ignore it.
	foreach ( $known_gaps as $gap ) {
		$this->assertArrayNotHasKey( $gap, $card['attrs'], "$gap is mapped now — remove it from the gap ledger and docs/micropub-field-gaps.md" );
	}

	// Completeness: mapped + gaps + universal (layout) = the block's full attr set.
	$matrix   = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/field-matrix.json' ), true );
	$all      = array_keys( $matrix[ $block ]['attributes'] );
	$covered  = array_merge( array_keys( $expected_attrs ), $known_gaps, [ 'layout' ] );
	$this->assertSame( [], array_diff( $all, $covered ), "$block has attrs neither asserted nor declared as gaps" );
}
```

Note: `build_for_test` stands for whatever seam the existing 35 tests already use to invoke the builder without a live Micropub request — reuse that exact seam; do not invent a second one.

- [ ] **Step 2: Fill in all 16 kind entries, run, reconcile**

Run: `composer phpunit -- --filter test_wire_matrix`
Expected: iterate until every kind entry passes with an honest ledger. The completeness assertion forces every attribute of every card into either "asserted" or "declared gap".

- [ ] **Step 3: Write `docs/micropub-field-gaps.md` from the ledgers**

Structure: one table per kind — columns: card attribute | Micropub property today | proposed property for Outpost (e.g. `cuisine` → proposed `mp-cuisine`; `locationAddress/Locality/Region/Country` → parse mf2 `location` h-adr instead of geo-only; `restaurantUrl` → `location` h-card url; `ateAt` → `published`). Close with the explicit follow-on note: "Sender-side changes live in the Outpost repo — out of scope for this plan."

- [ ] **Step 4: Commit**

```bash
git add tests/phpunit/unit/MicropubContentBuilderTest.php docs/micropub-field-gaps.md
git commit -m "test: per-kind Micropub wire matrix with enforced gap ledger + Outpost follow-on doc"
```

### Task 6: End-to-end wire field assertions

**Files:**
- Modify: `tests/e2e/micropub-kinds.spec.js`

**Interfaces:**
- Consumes: existing `createAndFetch()` helper in that spec (returns `{ id, content }` with `content.raw`).

- [ ] **Step 1: Upgrade one representative test per payload family from presence-assertions to field-assertions**

```js
// Added inside the existing describe block in tests/e2e/micropub-kinds.spec.js
test( 'eat-of with full properties lands every mapped field in the card attrs', async () => {
	const { content } = await createAndFetch( {
		'eat-of': 'Sample dish',
		content: 'Sample body',
		rating: '4',
		'mp-place-name': 'Sample venue',
		location: 'geo:40.0379,-76.3055',
	} );
	const attrs = JSON.parse(
		content.match( /<!-- wp:post-kinds-indieweb\/eat-card ({.*?}) \/?-->/ )[ 1 ]
	);
	expect( attrs.name ).toBe( 'Sample dish' );
	expect( attrs.rating ).toBe( 4 );
	expect( attrs.locationName ).toBe( 'Sample venue' );
	expect( attrs.geoLatitude ).toBeCloseTo( 40.0379 );
	expect( attrs.geoLongitude ).toBeCloseTo( -76.3055 );
} );
```

Repeat the same shape for: checkin (place + geo + photo), read (`read-of` + `rating`), like/repost/bookmark/reply (cite url + content) — four more tests, same pattern, values from Task 5's matrix.

- [ ] **Step 2: Run, verify green, commit**

Run: `WP_BASE_URL=http://localhost:8895 WP_APP_PASSWORD=<minted> npx playwright test tests/e2e/micropub-kinds.spec.js --project=chromium`

```bash
git add tests/e2e/micropub-kinds.spec.js
git commit -m "test: e2e micropub assertions check card fields, not just card presence"
```

---

## Part C — Book meta, bindings keys, completion engine

### Task 7: Book keys in the bindings source + pk_* aliases

**Files:**
- Modify: `includes/class-block-bindings-source.php` (KEY_MAP ~line 51, `register_meta()` ~line 161)
- Modify: `includes/class-meta-fields.php` (add `read_asin` suffix beside the existing `read_*` set)
- Test: `tests/phpunit/unit/BlockBindingsSourceTest.php`

**Interfaces:**
- Consumes: `Meta_Fields::PREFIX` = `_postkind_`; existing suffixes `read_isbn, read_publisher, read_publish_date, read_pages`; KEY_MAP entry shape `key => [ kind => suffix, '_default' => suffix ]`.
- Produces: bindable keys `isbn`, `publisher`, `page_count`, `publish_date`, `asin` resolving (for kind `read`) to `_postkind_read_isbn`, `_postkind_read_publisher`, `_postkind_read_publish_date`, `_postkind_read_pages`, `_postkind_read_asin`; REST-visible meta `pk_isbn`, `pk_publisher`, `pk_page_count`, `pk_publish_date`, `pk_asin`. Consumed by Tasks 8–13.

- [ ] **Step 1: Write the failing test**

```php
// Added to tests/phpunit/unit/BlockBindingsSourceTest.php
public function book_keys(): array {
	return [
		'isbn'         => [ 'isbn', 'read_isbn', '9781649374042' ],
		'publisher'    => [ 'publisher', 'read_publisher', 'Entangled' ],
		'page_count'   => [ 'page_count', 'read_pages', '517' ],
		'publish_date' => [ 'publish_date', 'read_publish_date', '2023-05-02' ],
		'asin'         => [ 'asin', 'read_asin', '1649374046' ],
	];
}

/** @dataProvider book_keys */
public function test_book_key_resolves_for_read_kind( string $key, string $suffix, string $value ): void {
	$post_id = $this->create_read_post(); // same factory helper the existing KEY_MAP tests use
	update_post_meta( $post_id, '_postkind_' . $suffix, $value );

	$this->assertSame(
		$value,
		$this->source->get_value( [ 'key' => $key ], $this->block_instance_for( $post_id ), 'content' )
	);
}
```

- [ ] **Step 2: Run to verify it fails** — `composer phpunit -- --filter test_book_key_resolves` → FAIL (`KEY_MAP` misses keys).

- [ ] **Step 3: Implement**

In `includes/class-block-bindings-source.php`, extend `KEY_MAP`:

```php
		'isbn'         => [ '_default' => 'read_isbn' ],
		'publisher'    => [ '_default' => 'read_publisher' ],
		'page_count'   => [ '_default' => 'read_pages' ],
		'publish_date' => [ '_default' => 'read_publish_date' ],
		'asin'         => [ '_default' => 'read_asin' ],
```

Extend `register_meta()` `$meta_keys`:

```php
			'pk_isbn'         => 'string',
			'pk_publisher'    => 'string',
			'pk_page_count'   => 'string',
			'pk_publish_date' => 'string',
			'pk_asin'         => 'string',
```

Add `read_asin` to `Meta_Fields`' read suffix registration alongside `read_isbn` (same registration shape as its siblings).

- [ ] **Step 4: Run to verify pass, then commit**

```bash
composer phpunit -- --filter BlockBindingsSourceTest
git add includes/class-block-bindings-source.php includes/class-meta-fields.php tests/phpunit/unit/BlockBindingsSourceTest.php
git commit -m "feat: book fields (isbn/publisher/pages/date/asin) as bindable kind-meta keys + pk_* aliases"
```

### Task 8: Read-card attrs → post meta sync on save

**Files:**
- Create: `includes/class-card-meta-sync.php`
- Modify: `includes/class-plugin.php` (instantiate beside the other includes in `register_hooks()`)
- Test: `tests/phpunit/integration/CardMetaSyncTest.php`

**Interfaces:**
- Consumes: `Taxonomy::get_first_block_kind( string $content ): ?string` (includes/class-taxonomy.php:469) as the pattern precedent — this class does the same walk but returns the first card block; `Meta_Fields::PREFIX`.
- Produces: `Card_Meta_Sync::ATTR_META_MAP` (`blockName => [ attr => meta_suffix ]`) and `Card_Meta_Sync::sync( int $post_id, \WP_Post $post ): void` hooked at `save_post` priority 25 (after #62's kind sync at its documented priority — confirm and hook after it). Consumed by Tasks 10 and 12 (meta is where completion writes and the Kindle bridge reads).

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/phpunit/integration/CardMetaSyncTest.php
declare(strict_types=1);

final class CardMetaSyncTest extends WP_UnitTestCase {

	public function test_read_card_attrs_mirror_into_postkind_meta(): void {
		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:post-kinds-indieweb/read-card {"bookTitle":"Fourth Wing","authorName":"Rebecca Yarros","isbn":"9781649374042","publisher":"Entangled","pageCount":517} /-->',
		] );

		// save_post fired during create; assert the mirror.
		$this->assertSame( 'Fourth Wing', get_post_meta( $post_id, '_postkind_read_title', true ) );
		$this->assertSame( 'Rebecca Yarros', get_post_meta( $post_id, '_postkind_read_author', true ) );
		$this->assertSame( '9781649374042', get_post_meta( $post_id, '_postkind_read_isbn', true ) );
		$this->assertSame( 'Entangled', get_post_meta( $post_id, '_postkind_read_publisher', true ) );
		$this->assertSame( '517', get_post_meta( $post_id, '_postkind_read_pages', true ) );
	}

	public function test_manual_meta_not_clobbered_by_empty_attr(): void {
		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:post-kinds-indieweb/read-card {"bookTitle":"Fourth Wing"} /-->',
		] );
		update_post_meta( $post_id, '_postkind_read_isbn', '9781649374042' );

		wp_update_post( [ 'ID' => $post_id, 'post_title' => 'touch' ] );

		$this->assertSame( '9781649374042', get_post_meta( $post_id, '_postkind_read_isbn', true ), 'empty attr must not erase existing meta' );
	}
}
```

- [ ] **Step 2: Run to verify failure** — `composer phpunit -- --filter CardMetaSyncTest` → FAIL (class missing).

- [ ] **Step 3: Implement**

```php
<?php
// includes/class-card-meta-sync.php
declare(strict_types=1);

namespace PostKindsForIndieWeb;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mirrors the first kind-card block's attributes into _postkind_* post
 * meta on save, so Block Bindings (and templates) can consume what the
 * card knows. Card attrs win when non-empty; existing meta survives
 * empty attrs (completion and manual edits are never erased).
 *
 * @since 1.2.0
 */
class Card_Meta_Sync {

	public const ATTR_META_MAP = [
		'post-kinds-indieweb/read-card' => [
			'bookTitle'   => 'read_title',
			'authorName'  => 'read_author',
			'isbn'        => 'read_isbn',
			'publisher'   => 'read_publisher',
			'publishDate' => 'read_publish_date',
			'pageCount'   => 'read_pages',
			'currentPage' => 'read_progress',
			'coverImage'  => 'read_cover',
			'bookUrl'     => 'read_url',
			'readStatus'  => 'read_status',
			'rating'      => 'read_rating',
			'startedAt'   => 'read_started_at',
			'finishedAt'  => 'read_finished_at',
			'review'      => 'read_review',
		],
		// Other card blocks join this map in follow-on work; the class is
		// deliberately map-driven so each is one entry, no new code.
	];

	public function __construct() {
		add_action( 'save_post', [ $this, 'sync' ], 25, 2 );
	}

	public function sync( int $post_id, \WP_Post $post ): void {
		if ( 'post' !== $post->post_type || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		foreach ( parse_blocks( $post->post_content ) as $block ) {
			$map = self::ATTR_META_MAP[ $block['blockName'] ?? '' ] ?? null;
			if ( null === $map ) {
				continue;
			}
			foreach ( $map as $attr => $suffix ) {
				$value = $block['attrs'][ $attr ] ?? null;
				if ( null === $value || '' === $value ) {
					continue; // Never erase existing meta with an empty attr.
				}
				update_post_meta( $post_id, Meta_Fields::PREFIX . $suffix, sanitize_text_field( (string) $value ) );
			}
			break; // First card block wins, mirroring the kind-sync semantics.
		}
	}
}
```

Instantiate in `includes/class-plugin.php` `register_hooks()`: `new Card_Meta_Sync();` beside the existing service constructions.

- [ ] **Step 4: Run to verify pass, commit**

```bash
composer phpunit -- --filter CardMetaSyncTest && composer analyze
git add includes/class-card-meta-sync.php includes/class-plugin.php tests/phpunit/integration/CardMetaSyncTest.php
git commit -m "feat: mirror first card block attrs into _postkind_ meta on save (bindings source of truth)"
```

### Task 9: ISBN utilities + Book_Completion service

**Files:**
- Create: `includes/class-isbn.php`
- Create: `includes/class-book-completion.php`
- Test: `tests/phpunit/unit/IsbnTest.php`
- Test: `tests/phpunit/unit/BookCompletionTest.php`

**Interfaces:**
- Consumes: `Open_Library::get_by_isbn( string ): ?array` and `search_by_title( string ): array` (includes/apis/class-openlibrary.php — **first implementation step: read its normalizer output keys around lines 740–770 (`title`, `authors`, `cover`, …) and set the adapter map below to the exact keys**); Google Books `search_by_title()`; Hardcover `search()` (skip when `! is_configured()`).
- Produces: `Isbn::validate( string ): bool`, `Isbn::to10( string ): ?string`, `Isbn::to13( string ): ?string`, `Isbn::asin_from_url( string ): ?string`, `Isbn::kindle_embed_url( string $asin ): string`; `Book_Completion::complete( array $book ): array` — input/output keys: `title, author, isbn, publisher, publish_date, pages, cover, url, asin`. Consumed by Tasks 10–13.

- [ ] **Step 1: Write the failing ISBN tests (checksums are exact — these pin correctness)**

```php
<?php
// tests/phpunit/unit/IsbnTest.php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PostKindsForIndieWeb\Isbn;

final class IsbnTest extends TestCase {

	public function test_validate(): void {
		$this->assertTrue( Isbn::validate( '9781649374042' ) );
		$this->assertTrue( Isbn::validate( '1-64937-404-6' ) );
		$this->assertFalse( Isbn::validate( '9781649374043' ) ); // bad check digit
		$this->assertFalse( Isbn::validate( 'B0BGYV1G97' ) );    // Kindle ASIN, not ISBN
	}

	public function test_to10_from_13(): void {
		$this->assertSame( '1649374046', Isbn::to10( '9781649374042' ) );
		$this->assertNull( Isbn::to10( '9791234567896' ) ); // 979- has no ISBN-10 form
	}

	public function test_to13_from_10(): void {
		$this->assertSame( '9781649374042', Isbn::to13( '1649374046' ) );
	}

	public function test_asin_from_amazon_urls(): void {
		$this->assertSame( 'B0BGYV1G97', Isbn::asin_from_url( 'https://www.amazon.com/Fourth-Wing-Empyrean-Rebecca-Yarros-ebook/dp/B0BGYV1G97/ref=x' ) );
		$this->assertSame( '1649374046', Isbn::asin_from_url( 'https://www.amazon.com/gp/product/1649374046' ) );
		$this->assertSame( 'B0BGYV1G97', Isbn::asin_from_url( 'https://read.amazon.com/kp/embed?asin=B0BGYV1G97' ) );
		$this->assertNull( Isbn::asin_from_url( 'https://example.com/not-amazon' ) );
	}

	public function test_kindle_embed_url(): void {
		$this->assertSame(
			'https://read.amazon.com/kp/embed?asin=1649374046&preview=inline',
			Isbn::kindle_embed_url( '1649374046' )
		);
	}
}
```

- [ ] **Step 2: Run to verify failure**, then implement:

```php
<?php
// includes/class-isbn.php
declare(strict_types=1);

namespace PostKindsForIndieWeb;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ISBN-10/13 validation and conversion plus Amazon ASIN helpers.
 *
 * Print-book ASINs equal the ISBN-10, which is what makes Kindle
 * preview URLs derivable from a completed ISBN. Kindle-edition ASINs
 * (B0…) are NOT derivable — they arrive only via an Amazon URL.
 *
 * @since 1.2.0
 */
final class Isbn {

	public static function validate( string $isbn ): bool {
		$isbn = self::clean( $isbn );
		if ( 10 === strlen( $isbn ) ) {
			return self::check10( $isbn );
		}
		if ( 13 === strlen( $isbn ) && ctype_digit( $isbn ) ) {
			return self::check13( $isbn );
		}
		return false;
	}

	public static function to10( string $isbn13 ): ?string {
		$isbn13 = self::clean( $isbn13 );
		if ( 13 !== strlen( $isbn13 ) || ! self::check13( $isbn13 ) || 0 !== strpos( $isbn13, '978' ) ) {
			return null;
		}
		$core = substr( $isbn13, 3, 9 );
		$sum  = 0;
		foreach ( str_split( $core ) as $i => $digit ) {
			$sum += ( 10 - $i ) * (int) $digit;
		}
		$check = ( 11 - ( $sum % 11 ) ) % 11;
		return $core . ( 10 === $check ? 'X' : (string) $check );
	}

	public static function to13( string $isbn10 ): ?string {
		$isbn10 = self::clean( $isbn10 );
		if ( 10 !== strlen( $isbn10 ) || ! self::check10( $isbn10 ) ) {
			return null;
		}
		$core  = '978' . substr( $isbn10, 0, 9 );
		$sum   = 0;
		foreach ( str_split( $core ) as $i => $digit ) {
			$sum += ( 0 === $i % 2 ? 1 : 3 ) * (int) $digit;
		}
		return $core . (string) ( ( 10 - ( $sum % 10 ) ) % 10 );
	}

	public static function asin_from_url( string $url ): ?string {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || ! preg_match( '/(^|\.)amazon\.(com|ca|de|fr|es|it|nl|se|pl|in|cn|sg|ae|sa|com\.au|com\.br|com\.mx|com\.tr|co\.uk|co\.jp)$/', strtolower( $host ) ) ) { // end-anchored allowlist — Task 9 review: the naive pattern accepted amazon.evil.com
			return null;
		}
		if ( preg_match( '#/(?:dp|gp/product)/([A-Z0-9]{10})#', $url, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '#[?&]asin=([A-Z0-9]{10})#', $url, $m ) ) {
			return $m[1];
		}
		return null;
	}

	public static function kindle_embed_url( string $asin ): string {
		return 'https://read.amazon.com/kp/embed?asin=' . rawurlencode( $asin ) . '&preview=inline';
	}

	private static function clean( string $isbn ): string {
		return strtoupper( str_replace( [ '-', ' ' ], '', $isbn ) );
	}

	private static function check10( string $isbn ): bool {
		if ( ! preg_match( '/^\d{9}[\dX]$/', $isbn ) ) {
			return false;
		}
		$sum = 0;
		foreach ( str_split( $isbn ) as $i => $char ) {
			$sum += ( 10 - $i ) * ( 'X' === $char ? 10 : (int) $char );
		}
		return 0 === $sum % 11;
	}

	private static function check13( string $isbn ): bool {
		$sum = 0;
		foreach ( str_split( $isbn ) as $i => $digit ) {
			$sum += ( 0 === $i % 2 ? 1 : 3 ) * (int) $digit;
		}
		return 0 === $sum % 10;
	}
}
```

- [ ] **Step 3: Write the failing completion tests (APIs injected, per the test-invariants discipline — never live HTTP in unit tests)**

```php
<?php
// tests/phpunit/unit/BookCompletionTest.php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PostKindsForIndieWeb\Book_Completion;

final class BookCompletionTest extends TestCase {

	private function completion_with_openlibrary( ?array $ol_book ): Book_Completion {
		$open_library = $this->createStub( \PostKindsForIndieWeb\Open_Library::class );
		$open_library->method( 'get_by_isbn' )->willReturn( $ol_book );
		$google = $this->createStub( \PostKindsForIndieWeb\Google_Books::class );
		$google->method( 'search_by_title' )->willReturn( [] );
		$hardcover = $this->createStub( \PostKindsForIndieWeb\Hardcover::class );
		$hardcover->method( 'is_configured' )->willReturn( false );
		return new Book_Completion( $open_library, $google, $hardcover );
	}

	public function test_isbn_fills_everything_missing(): void {
		$svc = $this->completion_with_openlibrary( [
			'title'        => 'Fourth Wing',
			'authors'      => [ 'Rebecca Yarros' ],
			'publisher'    => 'Entangled',
			'publish_date' => '2023-05-02',
			'pages'        => 517,
			'cover'        => 'https://covers.openlibrary.org/b/id/1-M.jpg',
		] );

		$out = $svc->complete( [ 'isbn' => '9781649374042' ] );

		$this->assertSame( 'Fourth Wing', $out['title'] );
		$this->assertSame( 'Rebecca Yarros', $out['author'] );
		$this->assertSame( 'Entangled', $out['publisher'] );
		$this->assertSame( '517', $out['pages'] );
		$this->assertSame( '1649374046', $out['asin'], 'ISBN-10 derived as print ASIN' );
	}

	public function test_user_values_never_overwritten(): void {
		$svc = $this->completion_with_openlibrary( [ 'title' => 'API Title', 'authors' => [ 'API Author' ] ] );
		$out = $svc->complete( [ 'isbn' => '9781649374042', 'title' => 'My Title' ] );
		$this->assertSame( 'My Title', $out['title'] );
	}

	public function test_amazon_url_provides_asin_without_isbn(): void {
		$svc = $this->completion_with_openlibrary( null );
		$out = $svc->complete( [ 'url' => 'https://www.amazon.com/dp/B0BGYV1G97' ] );
		$this->assertSame( 'B0BGYV1G97', $out['asin'] );
	}

	public function test_offline_apis_lose_no_input_data(): void {
		$svc = $this->completion_with_openlibrary( null );
		$in  = [ 'isbn' => '9781649374042', 'title' => 'Fourth Wing' ];
		$this->assertSame( 'Fourth Wing', $svc->complete( $in )['title'], 'API failure must never drop input' );
	}
}
```

- [ ] **Step 4: Implement Book_Completion**

```php
<?php
// includes/class-book-completion.php
declare(strict_types=1);

namespace PostKindsForIndieWeb;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fills missing book fields from whichever identifier is present:
 * ISBN → Open Library → Google Books → Hardcover; Amazon URL → ASIN;
 * ISBN-13 → derived ISBN-10 as print ASIN. Never overwrites a value
 * the caller already has; never drops input on API failure.
 *
 * Canonical field keys: title, author, isbn, publisher, publish_date,
 * pages, cover, url, asin.
 *
 * @since 1.2.0
 */
class Book_Completion {

	public function __construct(
		private Open_Library $open_library,
		private Google_Books $google_books,
		private Hardcover $hardcover
	) {}

	/**
	 * @param array<string, string> $book Partial book data (canonical keys).
	 * @return array<string, string> Completed book data.
	 */
	public function complete( array $book ): array {
		$book = array_filter( $book, static fn( $v ) => null !== $v && '' !== $v );

		// 1. Amazon URL → ASIN (and nothing else — Amazon has no free metadata API).
		if ( empty( $book['asin'] ) && ! empty( $book['url'] ) ) {
			$asin = Isbn::asin_from_url( (string) $book['url'] );
			if ( null !== $asin ) {
				$book['asin'] = $asin;
			}
		}

		// 2. ISBN lookup cascade for bibliographic fields.
		if ( ! empty( $book['isbn'] ) && Isbn::validate( (string) $book['isbn'] ) ) {
			$found = $this->open_library->get_by_isbn( (string) $book['isbn'] );
			$book  = $this->merge( $book, $this->from_open_library( $found ) );
		}

		// 3. Title search fallback when ISBN still unknown.
		if ( empty( $book['isbn'] ) && ! empty( $book['title'] ) ) {
			$results = $this->google_books->search_by_title( (string) $book['title'] );
			$book    = $this->merge( $book, $this->from_google_books( $results[0] ?? null ) );
		}

		// 4. Hardcover enrichment, only when configured.
		if ( $this->hardcover->is_configured() && ! empty( $book['title'] ) && ( empty( $book['cover'] ) || empty( $book['pages'] ) ) ) {
			$results = $this->hardcover->search( (string) $book['title'] );
			$book    = $this->merge( $book, $this->from_hardcover( $results[0] ?? null ) );
		}

		// 5. Print-ASIN derivation from ISBN.
		if ( empty( $book['asin'] ) && ! empty( $book['isbn'] ) ) {
			$isbn10 = 10 === strlen( str_replace( '-', '', (string) $book['isbn'] ) )
				? str_replace( '-', '', (string) $book['isbn'] )
				: Isbn::to10( (string) $book['isbn'] );
			if ( null !== $isbn10 ) {
				$book['asin'] = $isbn10;
			}
		}

		return $book;
	}

	/** Caller data always wins; API data only fills blanks. */
	private function merge( array $book, array $found ): array {
		foreach ( $found as $key => $value ) {
			if ( empty( $book[ $key ] ) && null !== $value && '' !== $value ) {
				$book[ $key ] = (string) $value;
			}
		}
		return $book;
	}

	// Per-API adapters → canonical keys. FIRST IMPLEMENTATION STEP of this
	// task: verify each key against the API class's normalizer (Open Library
	// normalizer ~lines 740–770 of includes/apis/class-openlibrary.php) and
	// correct these maps to the exact emitted keys.
	private function from_open_library( ?array $r ): array {
		if ( null === $r ) {
			return [];
		}
		return [
			'title'        => $r['title'] ?? null,
			'author'       => is_array( $r['authors'] ?? null ) ? implode( ', ', $r['authors'] ) : ( $r['author'] ?? null ),
			'publisher'    => $r['publisher'] ?? null,
			'publish_date' => $r['publish_date'] ?? null,
			'pages'        => isset( $r['pages'] ) ? (string) $r['pages'] : null,
			'cover'        => $r['cover'] ?? null,
		];
	}

	private function from_google_books( ?array $r ): array {
		if ( null === $r ) {
			return [];
		}
		return [
			'title'        => $r['title'] ?? null,
			'author'       => is_array( $r['authors'] ?? null ) ? implode( ', ', $r['authors'] ) : null,
			'publisher'    => $r['publisher'] ?? null,
			'publish_date' => $r['published_date'] ?? null,
			'pages'        => isset( $r['page_count'] ) ? (string) $r['page_count'] : null,
			'cover'        => $r['cover'] ?? $r['thumbnail'] ?? null,
			'isbn'         => $r['isbn_13'] ?? $r['isbn'] ?? null,
		];
	}

	private function from_hardcover( ?array $r ): array {
		if ( null === $r ) {
			return [];
		}
		return [
			'cover' => $r['cover'] ?? null,
			'pages' => isset( $r['pages'] ) ? (string) $r['pages'] : null,
			'isbn'  => $r['isbn_13'] ?? null,
		];
	}
}
```

- [ ] **Step 5: Run all four suites to green, commit**

```bash
composer phpunit -- --filter 'IsbnTest|BookCompletionTest' && composer analyze
git add includes/class-isbn.php includes/class-book-completion.php tests/phpunit/unit/IsbnTest.php tests/phpunit/unit/BookCompletionTest.php
git commit -m "feat: ISBN utilities + injectable Book_Completion cascade (Open Library → Google Books → Hardcover)"
```

### Task 10: Completion triggers — save-time, REST, editor button, Micropub

**Files:**
- Create: `includes/class-book-completion-controller.php` (REST + save hook wiring)
- Modify: `includes/class-plugin.php` (instantiate)
- Modify: `src/blocks/read-card/edit.js` (inspector button)
- Modify: `includes/class-micropub-content-builder.php` (read_card path calls completion)
- Test: `tests/phpunit/integration/BookCompletionControllerTest.php`

**Interfaces:**
- Consumes: `Book_Completion::complete()`, `Card_Meta_Sync::ATTR_META_MAP` read-card entry, `Isbn` (Task 9).
- Produces: `POST /pkiw/v1/book-complete` accepting/returning the canonical book keys; save-time fill of blank `_postkind_read_*` meta; Micropub `read-of` posts arrive completed.

- [ ] **Step 1: Write the failing REST + save tests**

```php
<?php
// tests/phpunit/integration/BookCompletionControllerTest.php
declare(strict_types=1);

final class BookCompletionControllerTest extends WP_UnitTestCase {

	public function test_rest_route_requires_edit_posts(): void {
		$request  = new WP_REST_Request( 'POST', '/pkiw/v1/book-complete' );
		$request->set_body_params( [ 'isbn' => '9781649374042' ] );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_rest_route_completes_for_editor(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		// Short-circuit outbound APIs deterministically via the injectable seam:
		add_filter( 'pkiw_book_completion_service', static function () {
			$stub = new class {
				public function complete( array $book ): array {
					return array_merge( [ 'title' => 'Fourth Wing', 'asin' => '1649374046' ], $book );
				}
			};
			return $stub;
		} );

		$request = new WP_REST_Request( 'POST', '/pkiw/v1/book-complete' );
		$request->set_body_params( [ 'isbn' => '9781649374042' ] );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Fourth Wing', $response->get_data()['title'] );
		$this->assertSame( '1649374046', $response->get_data()['asin'] );
	}

	public function test_save_fills_blank_meta_only(): void {
		add_filter( 'pkiw_book_completion_service', static function () {
			return new class {
				public function complete( array $book ): array {
					return array_merge( $book, [ 'publisher' => 'Entangled', 'asin' => '1649374046' ] );
				}
			};
		} );

		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:post-kinds-indieweb/read-card {"bookTitle":"Fourth Wing","isbn":"9781649374042"} /-->',
		] );

		$this->assertSame( 'Entangled', get_post_meta( $post_id, '_postkind_read_publisher', true ) );
		$this->assertSame( '1649374046', get_post_meta( $post_id, '_postkind_read_asin', true ) );
	}
}
```

- [ ] **Step 2: Implement the controller**

```php
<?php
// includes/class-book-completion-controller.php
declare(strict_types=1);

namespace PostKindsForIndieWeb;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires Book_Completion into the plugin: REST endpoint for the editor
 * button, save_post fill of blank read meta, and the Micropub read path.
 *
 * @since 1.2.0
 */
class Book_Completion_Controller {

	private const META_BY_KEY = [
		'title'        => 'read_title',
		'author'       => 'read_author',
		'isbn'         => 'read_isbn',
		'publisher'    => 'read_publisher',
		'publish_date' => 'read_publish_date',
		'pages'        => 'read_pages',
		'cover'        => 'read_cover',
		'url'          => 'read_url',
		'asin'         => 'read_asin',
	];

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_action( 'save_post', [ $this, 'complete_on_save' ], 30, 2 ); // after Card_Meta_Sync@25.
	}

	private function service(): object {
		$default = new Book_Completion( new Open_Library(), new Google_Books(), new Hardcover() );

		/**
		 * Filters the completion service (test seam / replacement point).
		 *
		 * @since 1.2.0
		 *
		 * @param object $service Object with complete( array ): array.
		 */
		return apply_filters( 'pkiw_book_completion_service', $default ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	}

	public function register_routes(): void {
		register_rest_route(
			'pkiw/v1',
			'/book-complete',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_complete' ],
				'permission_callback' => static fn() => current_user_can( 'edit_posts' ),
				'args'                => array_fill_keys(
					array_keys( self::META_BY_KEY ),
					[ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ]
				),
			]
		);
	}

	public function rest_complete( \WP_REST_Request $request ): \WP_REST_Response {
		$book = array_filter( $request->get_params(), 'is_string' );
		return rest_ensure_response( $this->service()->complete( $book ) );
	}

	public function complete_on_save( int $post_id, \WP_Post $post ): void {
		if ( 'post' !== $post->post_type || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! has_block( 'post-kinds-indieweb/read-card', $post ) ) {
			return;
		}

		$book = [];
		foreach ( self::META_BY_KEY as $key => $suffix ) {
			$value = get_post_meta( $post_id, Meta_Fields::PREFIX . $suffix, true );
			if ( is_string( $value ) && '' !== $value ) {
				$book[ $key ] = $value;
			}
		}
		if ( empty( $book['isbn'] ) && empty( $book['title'] ) && empty( $book['url'] ) ) {
			return; // Nothing to complete from.
		}

		$completed = $this->service()->complete( $book );

		foreach ( self::META_BY_KEY as $key => $suffix ) {
			if ( empty( $book[ $key ] ) && ! empty( $completed[ $key ] ) ) {
				update_post_meta( $post_id, Meta_Fields::PREFIX . $suffix, sanitize_text_field( (string) $completed[ $key ] ) );
			}
		}
	}
}
```

Instantiate in `class-plugin.php`. In `class-micropub-content-builder.php`, inside the read path, run the same `complete()` over the properties before building the card, gated by `apply_filters( 'pkiw_micropub_book_completion', true )` so a slow API can be opted out server-wide.

- [ ] **Step 3: Editor button in `src/blocks/read-card/edit.js`**

Add to the existing InspectorControls panel (follow the file's current component style):

```js
import { Button } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

// Inside the settings PanelBody:
const [ completing, setCompleting ] = useState( false );

<Button
	variant="secondary"
	isBusy={ completing }
	disabled={ completing || ( ! attributes.isbn && ! attributes.bookTitle && ! attributes.bookUrl ) }
	onClick={ async () => {
		setCompleting( true );
		try {
			const book = await apiFetch( {
				path: '/pkiw/v1/book-complete',
				method: 'POST',
				data: {
					isbn: attributes.isbn,
					title: attributes.bookTitle,
					author: attributes.authorName,
					url: attributes.bookUrl,
				},
			} );
			setAttributes( {
				bookTitle: attributes.bookTitle || book.title || '',
				authorName: attributes.authorName || book.author || '',
				isbn: attributes.isbn || book.isbn || '',
				publisher: attributes.publisher || book.publisher || '',
				publishDate: attributes.publishDate || book.publish_date || '',
				pageCount: attributes.pageCount || ( book.pages ? Number( book.pages ) : undefined ),
				coverImage: attributes.coverImage || book.cover || '',
			} );
		} finally {
			setCompleting( false );
		}
	} }
>
	{ __( 'Complete book details', 'post-kinds-for-indieweb' ) }
</Button>
```

- [ ] **Step 4: Run everything, build, commit**

```bash
composer phpunit -- --filter BookCompletionControllerTest && composer analyze && npm run build
git add includes/class-book-completion-controller.php includes/class-plugin.php includes/class-micropub-content-builder.php src/blocks/read-card/edit.js tests/phpunit/integration/BookCompletionControllerTest.php
git commit -m "feat: book completion on save, via REST for the editor button, and in the Micropub read path"
```

---

## Part D — Kindle embed integration

### Task 11: Computed `kindle_embed_url` binding key

**Files:**
- Modify: `includes/class-block-bindings-source.php` (`get_value()` special case beside the existing `'kind'` one, ~line 229)
- Test: `tests/phpunit/unit/BlockBindingsSourceTest.php`

**Interfaces:**
- Consumes: `_postkind_read_asin` / `_postkind_read_isbn` meta (Tasks 7–10), `Isbn::kindle_embed_url()`, `Isbn::to10()`.
- Produces: binding key `kindle_embed_url` → `https://read.amazon.com/kp/embed?asin=…&preview=inline` or `null` when underivable. Consumed by Tasks 12–13.

- [ ] **Step 1: Failing test**

```php
public function test_kindle_embed_url_prefers_asin_then_isbn10(): void {
	$post_id = $this->create_read_post();

	update_post_meta( $post_id, '_postkind_read_isbn', '9781649374042' );
	$this->assertSame(
		'https://read.amazon.com/kp/embed?asin=1649374046&preview=inline',
		$this->source->get_value( [ 'key' => 'kindle_embed_url' ], $this->block_instance_for( $post_id ), 'url' ),
		'ISBN-13 → derived ISBN-10 print ASIN'
	);

	update_post_meta( $post_id, '_postkind_read_asin', 'B0BGYV1G97' );
	$this->assertSame(
		'https://read.amazon.com/kp/embed?asin=B0BGYV1G97&preview=inline',
		$this->source->get_value( [ 'key' => 'kindle_embed_url' ], $this->block_instance_for( $post_id ), 'url' ),
		'explicit ASIN wins over derivation'
	);
}
```

- [ ] **Step 2: Implement in `get_value()`** (before the KEY_MAP lookup, mirroring the `'kind'` special case)

```php
		if ( 'kindle_embed_url' === $key ) {
			$asin = get_post_meta( (int) $post_id, Meta_Fields::PREFIX . 'read_asin', true );
			if ( ! is_string( $asin ) || '' === $asin ) {
				$isbn = get_post_meta( (int) $post_id, Meta_Fields::PREFIX . 'read_isbn', true );
				$asin = is_string( $isbn ) && '' !== $isbn ? (string) Isbn::to10( $isbn ) : '';
			}
			return '' !== $asin && '0' !== $asin ? Isbn::kindle_embed_url( $asin ) : null;
		}
```

Also append `'kindle_embed_url'` to the `$bindable_keys` derivation (it is computed, so add it explicitly after `array_keys( self::KEY_MAP )`).

- [ ] **Step 3: Run to green, commit**

```bash
composer phpunit -- --filter test_kindle_embed_url && composer analyze
git add includes/class-block-bindings-source.php tests/phpunit/unit/BlockBindingsSourceTest.php
git commit -m "feat: computed kindle_embed_url binding key (ASIN, else ISBN-10 derivation)"
```

### Task 12: `core/embed` opt-in + server render bridge

**Files:**
- Create: `includes/class-kindle-embed-bridge.php`
- Modify: `includes/class-plugin.php` (instantiate)
- Test: `tests/phpunit/integration/KindleEmbedBridgeTest.php`

**Interfaces:**
- Consumes: `kindle_embed_url` binding (Task 11); WP filter `block_bindings_supported_attributes` (wp-includes/block-bindings.php:141, since 6.9).
- Produces: front-end Kindle iframe whose `src` always reflects current post meta; marker class `pkiw-kindle-preview` on the embed block.

**Why a bridge:** `core/embed` saves static wrapper HTML; even with the bindings allowlist opened via the filter, a bound `url` attr does not regenerate the saved iframe. The bridge rewrites the rendered markup server-side, so previews follow meta (e.g. after completion fills the ISBN).

- [ ] **Step 1: Failing test**

```php
<?php
// tests/phpunit/integration/KindleEmbedBridgeTest.php
declare(strict_types=1);

final class KindleEmbedBridgeTest extends WP_UnitTestCase {

	public function test_marked_embed_gets_iframe_src_from_meta(): void {
		$post_id = self::factory()->post->create( [
			'post_content' =>
				'<!-- wp:embed {"url":"https://read.amazon.com/kp/embed?asin=PLACEHOLDER0","type":"video","providerNameSlug":"amazon-kindle","className":"pkiw-kindle-preview"} -->' .
				'<figure class="wp-block-embed is-provider-amazon-kindle pkiw-kindle-preview"><div class="wp-block-embed__wrapper">' . "\n" .
				'https://read.amazon.com/kp/embed?asin=PLACEHOLDER0' . "\n" .
				'</div></figure><!-- /wp:embed -->',
		] );
		update_post_meta( $post_id, '_postkind_read_asin', '1649374046' );

		$this->go_to( get_permalink( $post_id ) );
		$html = apply_filters( 'the_content', get_post( $post_id )->post_content );

		$this->assertStringContainsString( 'src="https://read.amazon.com/kp/embed?asin=1649374046&#038;preview=inline"', $html );
		$this->assertStringContainsString( '<iframe', $html );
		$this->assertStringNotContainsString( 'PLACEHOLDER0', $html );
	}

	public function test_unmarked_embeds_untouched(): void {
		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:embed {"url":"https://www.youtube.com/watch?v=x","providerNameSlug":"youtube"} --><figure class="wp-block-embed"><div class="wp-block-embed__wrapper">x</div></figure><!-- /wp:embed -->',
		] );
		$html = apply_filters( 'the_content', get_post( $post_id )->post_content );
		$this->assertStringNotContainsString( 'read.amazon.com', $html );
	}
}
```

- [ ] **Step 2: Implement**

```php
<?php
// includes/class-kindle-embed-bridge.php
declare(strict_types=1);

namespace PostKindsForIndieWeb;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Makes the core amazon-kindle embed variation follow post meta.
 *
 * 1. Opens the Block Bindings allowlist so core/embed's url attribute
 *    can bind to post-kinds/kind-meta (editor-side affordance).
 * 2. render_block bridge: any core/embed carrying the
 *    pkiw-kindle-preview class has its wrapper replaced by an iframe
 *    whose src is the computed kindle_embed_url — saved markup never
 *    goes stale when completion later fills the ISBN/ASIN.
 *
 * @since 1.2.0
 */
class Kindle_Embed_Bridge {

	public const MARKER = 'pkiw-kindle-preview';

	public function __construct() {
		add_filter( 'block_bindings_supported_attributes', [ $this, 'allow_embed_url' ], 10, 2 );
		add_filter( 'render_block_core/embed', [ $this, 'render' ], 10, 3 );
	}

	/**
	 * @param string[] $attrs      Supported attribute names.
	 * @param string   $block_type Block type being filtered.
	 * @return string[]
	 */
	public function allow_embed_url( $attrs, $block_type ) {
		if ( 'core/embed' === $block_type && ! in_array( 'url', (array) $attrs, true ) ) {
			$attrs[] = 'url';
		}
		return $attrs;
	}

	/**
	 * @param string    $content Rendered embed HTML.
	 * @param array     $block   Parsed block.
	 * @param \WP_Block $instance Block instance with context.
	 * @return string
	 */
	public function render( $content, $block, $instance ) {
		$class = $block['attrs']['className'] ?? '';
		if ( false === strpos( $class, self::MARKER ) ) {
			return $content;
		}

		$post_id = $instance->context['postId'] ?? get_the_ID();
		if ( ! $post_id ) {
			return $content;
		}

		$asin = get_post_meta( (int) $post_id, Meta_Fields::PREFIX . 'read_asin', true );
		if ( ! is_string( $asin ) || '' === $asin ) {
			$isbn = get_post_meta( (int) $post_id, Meta_Fields::PREFIX . 'read_isbn', true );
			$asin = is_string( $isbn ) && '' !== $isbn ? (string) Isbn::to10( $isbn ) : '';
		}
		if ( '' === $asin ) {
			return $content; // Nothing derivable — leave the saved embed alone.
		}

		$iframe = sprintf(
			'<iframe src="%s" title="%s" width="336" height="550" loading="lazy" sandbox="allow-scripts allow-same-origin allow-popups"></iframe>',
			esc_url( Isbn::kindle_embed_url( $asin ) ),
			esc_attr__( 'Kindle book preview', 'post-kinds-for-indieweb' )
		);

		$replaced = preg_replace(
			'#<div class="wp-block-embed__wrapper">.*?</div>#s',
			'<div class="wp-block-embed__wrapper">' . $iframe . '</div>',
			$content,
			1
		);

		return $replaced ?? $content;
	}
}
```

Instantiate in `class-plugin.php`.

- [ ] **Step 3: Run to green, commit**

```bash
composer phpunit -- --filter KindleEmbedBridgeTest && composer analyze
git add includes/class-kindle-embed-bridge.php includes/class-plugin.php tests/phpunit/integration/KindleEmbedBridgeTest.php
git commit -m "feat: kindle embed bridge — bindings opt-in + render-time iframe from read meta"
```

### Task 13: "Read + Kindle preview" pattern, inspector toggle, full-flow e2e

**Files:**
- Create: `patterns/read-with-kindle-preview.php`
- Modify: `src/blocks/read-card/edit.js` (toggle that inserts the embed)
- Test: `tests/e2e/read-kindle-flow.spec.js`

**Interfaces:**
- Consumes: everything from Tasks 7–12.

- [ ] **Step 1: The pattern** (registered by the existing `register_block_patterns()` loop in `class-plugin.php` — confirm it globs `patterns/`; if it registers an explicit list, add this file to it)

```php
<?php
/**
 * Read post with Kindle preview pattern.
 *
 * @package PostKindsForIndieWeb
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'title'      => __( 'Read post with Kindle preview', 'post-kinds-for-indieweb' ),
	'categories' => [ 'post-kinds-for-indieweb' ],
	'blockTypes' => [ 'post-kinds-indieweb/read-card' ],
	'content'    =>
		'<!-- wp:post-kinds-indieweb/read-card /-->' .
		'<!-- wp:embed {"url":"","type":"video","providerNameSlug":"amazon-kindle","className":"pkiw-kindle-preview"} -->' .
		'<figure class="wp-block-embed is-provider-amazon-kindle pkiw-kindle-preview"><div class="wp-block-embed__wrapper"></div></figure>' .
		'<!-- /wp:embed -->' .
		'<!-- wp:paragraph --><p></p><!-- /wp:paragraph -->',
];
```

(Match the return/registration shape of the existing files in `patterns/` — e.g. `patterns/read-progress.php` — exactly; if they use header-comment registration instead of returned arrays, follow that instead.)

- [ ] **Step 2: Inspector toggle in read-card edit.js**

```js
import { ToggleControl } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { createBlock } from '@wordpress/blocks';

// Inside edit(), with clientId available:
const { insertBlocks, removeBlock } = useDispatch( 'core/block-editor' );
const kindleEmbedClientId = useSelect(
	( select ) => {
		const { getBlocks } = select( 'core/block-editor' );
		const sibling = getBlocks().find(
			( b ) =>
				b.name === 'core/embed' &&
				( b.attributes.className || '' ).includes( 'pkiw-kindle-preview' )
		);
		return sibling ? sibling.clientId : null;
	},
	[]
);

<ToggleControl
	label={ __( 'Show Kindle preview', 'post-kinds-for-indieweb' ) }
	help={ __( 'Adds a Kindle instant-preview that follows this book’s ISBN/ASIN.', 'post-kinds-for-indieweb' ) }
	checked={ !! kindleEmbedClientId }
	onChange={ ( on ) => {
		if ( on ) {
			const index = wp.data
				.select( 'core/block-editor' )
				.getBlockIndex( clientId );
			insertBlocks(
				createBlock( 'core/embed', {
					providerNameSlug: 'amazon-kindle',
					className: 'pkiw-kindle-preview',
					url: '',
				} ),
				index + 1
			);
		} else if ( kindleEmbedClientId ) {
			removeBlock( kindleEmbedClientId );
		}
	} }
/>
```

- [ ] **Step 3: Full-flow e2e**

```js
// tests/e2e/read-kindle-flow.spec.js
// ISBN in the Read card → completion fills meta on save → the marked
// Kindle embed renders an iframe with the derived print-ASIN URL.
const { test, expect } = require( '@playwright/test' );
const BASE = process.env.WP_BASE_URL || 'http://localhost:8888';

test( 'read post: isbn → completed meta → kindle iframe on the front end', async ( { page } ) => {
	await page.goto( `${ BASE }/wp-admin/post-new.php` );
	await page.evaluate( () =>
		window.wp.data.dispatch( 'core/preferences' ).set( 'core', 'welcomeGuide', false )
	);

	await page.evaluate( () => {
		const read = window.wp.blocks.createBlock( 'post-kinds-indieweb/read-card', {
			bookTitle: 'Fourth Wing',
			isbn: '9781649374042',
		} );
		const embed = window.wp.blocks.createBlock( 'core/embed', {
			providerNameSlug: 'amazon-kindle',
			className: 'pkiw-kindle-preview',
			url: '',
		} );
		window.wp.data.dispatch( 'core/block-editor' ).insertBlocks( [ read, embed ] );
	} );

	await page.evaluate( () => window.wp.data.dispatch( 'core/editor' ).editPost( { status: 'publish' } ) );
	await page.evaluate( () => window.wp.data.dispatch( 'core/editor' ).savePost() );
	await page.waitForFunction( () => ! window.wp.data.select( 'core/editor' ).isSavingPost() );

	const link = await page.evaluate( () => window.wp.data.select( 'core/editor' ).getPermalink() );
	await page.goto( link );

	const iframe = page.locator( '.pkiw-kindle-preview iframe' );
	await expect( iframe ).toHaveAttribute(
		'src',
		'https://read.amazon.com/kp/embed?asin=1649374046&preview=inline'
	);
} );
```

Note: the completion call in this e2e hits Open Library live from wp-env. If CI networking forbids it, seed the meta instead via a `pkiw_book_completion_service` stub mu-plugin fixture (same pattern as `tests/env/pkiw-test-auth.php`) — decide at implementation time and document in the spec header.

- [ ] **Step 4: Run, build, commit; create a staging review post**

```bash
npm run build
WP_BASE_URL=http://localhost:8895 npx playwright test tests/e2e/read-kindle-flow.spec.js --project=chromium
git add patterns/read-with-kindle-preview.php src/blocks/read-card/edit.js tests/e2e/read-kindle-flow.spec.js
git commit -m "feat: Read + Kindle preview pattern, inspector toggle, and full-flow e2e"
```

Verification for Courtney: after the release lands on staging, create one review post via the pattern with a real ISBN and confirm the preview renders (same review-post workflow as the 2026-07-03 batch; delete with the batch).

### Task 14: Release wrap-up

**Files:**
- Modify: `CHANGELOG.md`, `readme.txt` (changelog + version), `post-kinds-for-indieweb.php` (version), `package.json`

- [ ] **Step 1:** Run the full gate: `composer test && npm run test:js && npm run build && WP_BASE_URL=http://localhost:8895 WP_APP_PASSWORD=<minted> npx playwright test` — all green.
- [ ] **Step 2:** Version bump to 1.2.0 (new features: bindings book keys, completion, Kindle bridge — minor). Changelog entries per the v1.1.0 format in both files.
- [ ] **Step 3:** PR to main; merge on green CI; tag + GitHub release v1.2.0 (GitHub-only distribution, same as v1.1.0).

```bash
git add CHANGELOG.md readme.txt post-kinds-for-indieweb.php package.json package-lock.json
git commit -m "release: v1.2.0 — field-matrix coverage, book completion, Kindle preview bindings"
```

---

## Explicitly out of scope (flagged, not done)

- **Outpost sender changes** (new `mp-cuisine`-style properties, h-adr locations): produced as a concrete list in `docs/micropub-field-gaps.md` (Task 5), implemented in the Outpost repo separately.
- **Card_Meta_Sync maps for the other 18 blocks**: the class is map-driven; each block is one map entry + one test, best done alongside that block's next feature work.
- **Amazon PA-API**: deliberately excluded (paid, and the ISBN-10 trick covers print books).

## Self-review notes

- Spec coverage: (1) Kindle × Read card × bindings → Tasks 7, 11, 12, 13; (2) ISBN + book info completion → Tasks 9, 10; (3) every-field-displays for eat and all other blocks → Tasks 1–4; (4) Outpost wire coverage → Tasks 5–6.
- Known verify-at-implementation points (each named in its task, none blocking): the API normalizer key maps (Task 9 Step 4 comment), the builder test seam name (Task 5), the pattern registration shape (Task 13 Step 1), and PR #62's exact save_post priority (Task 8).
- Type consistency: canonical book keys (`title, author, isbn, publisher, publish_date, pages, cover, url, asin`) are identical across Book_Completion, the controller's META_BY_KEY, the REST args, and the read-card button payload; `Isbn::` signatures match all call sites; `_postkind_read_*` suffixes match the verified Meta_Fields set.
