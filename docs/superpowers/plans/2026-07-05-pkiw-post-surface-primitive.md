# PKIW Post-Surface Primitive Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a reusable, filterable "post surface" classification to Post Kinds for IndieWeb so any install can mark certain kinds as ephemeral (stream) vs main, with a per-post promote override settable from the editor, Micropub, and WP-CLI.

**Architecture:** A pure classifier (`get_post_surface()`) reads a `pk_stream_kinds` filter plus a `pk_promote` meta and returns `stream|main`; a `save_post` hook caches the result in a protected `_pk_surface` meta so sites can query it cheaply. PKIW never filters a site's queries — it only produces the signal. A Micropub property and a WP-CLI backfill are additional writers of the same `pk_promote`/`_pk_surface` pair.

**Tech Stack:** PHP 8.2, WordPress 7.0, PHPUnit ^9.6 (WP_UnitTestCase integration + unit), `@wordpress/scripts` block editor JS, WP-CLI.

## Global Constraints

- Min WP 7.0, Min PHP 8.2 (verbatim from CLAUDE.md).
- Text domain `post-kinds-for-indieweb`; all user strings wrapped in `__()`.
- Security Trinity: sanitize input → validate data → escape output; `current_user_can()` on writes.
- Naming (verbatim from spec): filter `pk_stream_kinds`; public override meta `pk_promote`; derived protected meta `_pk_surface`; override filter `pk_post_surface`; Micropub property `pk-promote`.
- `pk_stream_kinds` default is the empty array — zero behavior change for existing installs.
- Surface values are exactly the strings `'stream'` and `'main'`.
- Kind taxonomy slug is `kind`.
- Emoji-Log commits; every commit ends with the `Co-Authored-By: Claude <noreply@anthropic.com>` trailer.
- CI green (PHPCS, PHPStan level 5, PHPUnit matrix, JS lint, build) is the source of truth.

## File Structure

- Create `includes/class-post-surface.php` — the `Post_Surface` class: classifier, meta registration, save hook. One responsibility: compute and cache a post's surface.
- Modify `includes/class-plugin.php` — instantiate `Post_Surface` in the same block that wires other components.
- Modify `includes/class-cli-commands.php` — add the `surfaces backfill` subcommand.
- Modify `includes/class-micropub-content-builder.php` — map the `pk-promote` property to `pk_promote` meta. (Exact hook confirmed in Task 5.)
- Create `src/editor/promote-panel/index.js` — the editor sidebar toggle.
- Modify `src/editor/index.js` — import the promote panel.
- Test: `tests/phpunit/unit/PostSurfaceTest.php`, `tests/phpunit/integration/PostSurfaceMetaTest.php`, `tests/phpunit/integration/MicropubPromoteTest.php`.

---

### Task 1: Surface classifier + `pk_stream_kinds` filter

**Files:**
- Create: `includes/class-post-surface.php`
- Test: `tests/phpunit/integration/PostSurfaceMetaTest.php` (uses real terms/meta; classifier needs WP term functions)

**Interfaces:**
- Produces: `\PostKindsForIndieWeb\Post_Surface::get( int|\WP_Post $post ): string` returning `'stream'` or `'main'`. Reads `apply_filters( 'pk_stream_kinds', [] )` (array of kind slugs), the post's `kind` terms, the `pk_promote` meta (truthy → forced `'main'`), then `apply_filters( 'pk_post_surface', string $surface, \WP_Post $post )`.

- [ ] **Step 1: Write the failing test**

```php
// tests/phpunit/integration/PostSurfaceMetaTest.php
final class PostSurfaceMetaTest extends WP_UnitTestCase {
	private function post_with_kind( string $slug ): int {
		$id = self::factory()->post->create();
		wp_set_object_terms( $id, $slug, 'kind' );
		return $id;
	}
	public function test_non_stream_kind_is_main(): void {
		add_filter( 'pk_stream_kinds', fn() => [ 'checkin' ] );
		$id = $this->post_with_kind( 'article' );
		$this->assertSame( 'main', \PostKindsForIndieWeb\Post_Surface::get( $id ) );
	}
	public function test_stream_kind_is_stream(): void {
		add_filter( 'pk_stream_kinds', fn() => [ 'checkin' ] );
		$id = $this->post_with_kind( 'checkin' );
		$this->assertSame( 'stream', \PostKindsForIndieWeb\Post_Surface::get( $id ) );
	}
	public function test_promote_forces_main(): void {
		add_filter( 'pk_stream_kinds', fn() => [ 'checkin' ] );
		$id = $this->post_with_kind( 'checkin' );
		update_post_meta( $id, 'pk_promote', 1 );
		$this->assertSame( 'main', \PostKindsForIndieWeb\Post_Surface::get( $id ) );
	}
	public function test_empty_default_filter_is_main(): void {
		$id = $this->post_with_kind( 'checkin' );
		$this->assertSame( 'main', \PostKindsForIndieWeb\Post_Surface::get( $id ) );
	}
	public function test_pk_post_surface_filter_overrides(): void {
		add_filter( 'pk_stream_kinds', fn() => [ 'checkin' ] );
		add_filter( 'pk_post_surface', fn() => 'main' );
		$id = $this->post_with_kind( 'checkin' );
		$this->assertSame( 'main', \PostKindsForIndieWeb\Post_Surface::get( $id ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter PostSurfaceMetaTest`
Expected: FAIL — `Class "PostKindsForIndieWeb\Post_Surface" not found`.

- [ ] **Step 3: Write the classifier**

```php
// includes/class-post-surface.php
<?php
declare(strict_types=1);
namespace PostKindsForIndieWeb;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Post_Surface {
	public const STREAM = 'stream';
	public const MAIN   = 'main';

	/**
	 * @param int|\WP_Post $post Post ID or object.
	 */
	public static function get( $post ): string {
		$post = get_post( $post );
		if ( ! $post instanceof \WP_Post ) {
			return self::MAIN;
		}

		$stream_kinds = (array) apply_filters( 'pk_stream_kinds', [] );
		$surface      = self::MAIN;

		if ( ! empty( $stream_kinds ) && ! self::is_promoted( $post->ID ) ) {
			$kinds = wp_get_object_terms( $post->ID, 'kind', [ 'fields' => 'slugs' ] );
			if ( ! is_wp_error( $kinds ) && array_intersect( $kinds, $stream_kinds ) ) {
				$surface = self::STREAM;
			}
		}

		/**
		 * Filters the computed surface for a post.
		 *
		 * @param string   $surface 'stream' or 'main'.
		 * @param \WP_Post $post    The post.
		 */
		return (string) apply_filters( 'pk_post_surface', $surface, $post );
	}

	private static function is_promoted( int $post_id ): bool {
		return (bool) get_post_meta( $post_id, 'pk_promote', true );
	}
}
```

- [ ] **Step 4: Load the class and run tests**

Add to the autoloaded includes (the plugin autoloader maps `Post_Surface` → `includes/class-post-surface.php` by convention, so no manual require is needed — verify by running the test).
Run: `composer test -- --filter PostSurfaceMetaTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add includes/class-post-surface.php tests/phpunit/integration/PostSurfaceMetaTest.php
git commit -m "📦 NEW: post-surface classifier with pk_stream_kinds + pk_post_surface filters

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

### Task 2: Register `pk_promote` + cache `_pk_surface` on save

**Files:**
- Modify: `includes/class-post-surface.php` (add `register()`, meta registration, `on_save()`)
- Modify: `includes/class-plugin.php` (instantiate `Post_Surface`)
- Test: `tests/phpunit/integration/PostSurfaceMetaTest.php` (extend)

**Interfaces:**
- Consumes: `Post_Surface::get()` from Task 1.
- Produces: post meta `pk_promote` (bool, REST-exposed) and `_pk_surface` (string, protected) kept in sync on `save_post`. `Post_Surface::register(): void` wires `init` (meta) and `save_post` (cache).

- [ ] **Step 1: Write the failing test**

```php
// append to PostSurfaceMetaTest.php
	public function test_save_caches_surface_meta(): void {
		add_filter( 'pk_stream_kinds', fn() => [ 'checkin' ] );
		$id = self::factory()->post->create();
		wp_set_object_terms( $id, 'checkin', 'kind' );
		wp_update_post( [ 'ID' => $id ] ); // fire save_post
		$this->assertSame( 'stream', get_post_meta( $id, '_pk_surface', true ) );
	}
	public function test_promote_meta_is_rest_registered(): void {
		$this->assertTrue( registered_meta_key_exists( 'post', 'pk_promote' ) );
		$obj = get_registered_meta_keys( 'post' )['pk_promote'];
		$this->assertTrue( $obj['show_in_rest'] );
	}
```

- [ ] **Step 2: Run to verify it fails**

Run: `composer test -- --filter PostSurfaceMetaTest`
Expected: FAIL — `_pk_surface` empty / `pk_promote` not registered.

- [ ] **Step 3: Add registration + save hook**

```php
// in class Post_Surface, add:
	public function register(): void {
		add_action( 'init', [ $this, 'register_meta' ] );
		add_action( 'save_post', [ $this, 'on_save' ], 20, 1 );
	}

	public function register_meta(): void {
		register_post_meta( 'post', 'pk_promote', [
			'type'          => 'boolean',
			'single'        => true,
			'default'       => false,
			'show_in_rest'  => true,
			'auth_callback' => fn() => current_user_can( 'edit_posts' ),
		] );
		register_post_meta( 'post', '_pk_surface', [
			'type'          => 'string',
			'single'        => true,
			'show_in_rest'  => false,
			'auth_callback' => '__return_false', // read/derived only
		] );
	}

	public function on_save( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( 'post' !== get_post_type( $post_id ) ) {
			return;
		}
		update_post_meta( $post_id, '_pk_surface', self::get( $post_id ) );
	}
```

Note: `get()`/`is_promoted()` become instance-callable-safe by staying `static`; `register()`/`on_save()` are instance methods.

- [ ] **Step 4: Instantiate in the plugin**

In `includes/class-plugin.php`, in the component-wiring block (near the other `if ( class_exists(...) )` guards), add:

```php
if ( class_exists( __NAMESPACE__ . '\\Post_Surface' ) ) {
	( new Post_Surface() )->register();
}
```

- [ ] **Step 5: Run tests**

Run: `composer test -- --filter PostSurfaceMetaTest`
Expected: PASS (7 tests).

- [ ] **Step 6: Commit**

```bash
git add includes/class-post-surface.php includes/class-plugin.php tests/phpunit/integration/PostSurfaceMetaTest.php
git commit -m "📦 NEW: register pk_promote meta and cache _pk_surface on save

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

### Task 3: WP-CLI `wp pk surfaces backfill`

**Files:**
- Modify: `includes/class-cli-commands.php`
- Test: `tests/phpunit/integration/PostSurfaceMetaTest.php` (test the callback directly)

**Interfaces:**
- Consumes: `Post_Surface::get()`.
- Produces: a CLI method `surfaces( $args, $assoc_args )` under the existing `pk` command that recomputes `_pk_surface` for all posts and reports a count.

- [ ] **Step 1: Write the failing test** (drive the shared backfill helper, not the CLI I/O)

```php
	public function test_backfill_recomputes_all(): void {
		add_filter( 'pk_stream_kinds', fn() => [ 'checkin' ] );
		$a = self::factory()->post->create(); wp_set_object_terms( $a, 'checkin', 'kind' );
		$b = self::factory()->post->create(); wp_set_object_terms( $b, 'article', 'kind' );
		delete_post_meta( $a, '_pk_surface' ); delete_post_meta( $b, '_pk_surface' );
		$count = \PostKindsForIndieWeb\Post_Surface::backfill();
		$this->assertSame( 2, $count );
		$this->assertSame( 'stream', get_post_meta( $a, '_pk_surface', true ) );
		$this->assertSame( 'main', get_post_meta( $b, '_pk_surface', true ) );
	}
```

- [ ] **Step 2: Run to verify it fails**

Run: `composer test -- --filter test_backfill_recomputes_all`
Expected: FAIL — `Post_Surface::backfill()` undefined.

- [ ] **Step 3: Add `backfill()` to `Post_Surface` and the CLI subcommand**

```php
// in Post_Surface:
	public static function backfill(): int {
		$ids = get_posts( [ 'post_type' => 'post', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] );
		foreach ( $ids as $id ) {
			update_post_meta( $id, '_pk_surface', self::get( $id ) );
		}
		return count( $ids );
	}
```

```php
// in class-cli-commands.php, add a method to the registered `pk` command class:
	/**
	 * Recompute the cached _pk_surface for every post.
	 *
	 * ## EXAMPLES
	 *     wp pk surfaces backfill
	 *
	 * @subcommand surfaces
	 */
	public function surfaces( $args, $assoc_args ) {
		$sub = $args[0] ?? '';
		if ( 'backfill' !== $sub ) {
			\WP_CLI::error( 'Usage: wp pk surfaces backfill' );
		}
		$n = \PostKindsForIndieWeb\Post_Surface::backfill();
		\WP_CLI::success( sprintf( 'Recomputed _pk_surface for %d posts.', $n ) );
	}
```

- [ ] **Step 4: Run tests**

Run: `composer test -- --filter test_backfill_recomputes_all`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/class-post-surface.php includes/class-cli-commands.php tests/phpunit/integration/PostSurfaceMetaTest.php
git commit -m "📦 NEW: wp pk surfaces backfill recomputes cached surfaces

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

### Task 4: Editor sidebar promote toggle

**Files:**
- Create: `src/editor/promote-panel/index.js`
- Modify: `src/editor/index.js` (import the panel)
- Test: `tests/e2e/promote-toggle.spec.js`

**Interfaces:**
- Consumes: the `pk_promote` REST meta from Task 2.
- Produces: a `PluginDocumentSettingPanel` with a `ToggleControl` bound to `meta.pk_promote`, registered only for the `post` type.

- [ ] **Step 1: Write the failing e2e test**

```js
// tests/e2e/promote-toggle.spec.js
const { test, expect } = require( '@playwright/test' );
test( 'promote toggle persists pk_promote meta', async ( { page } ) => {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' ); await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' ); await page.waitForURL( '**/wp-admin/**' );
	await page.goto( '/wp-admin/post-new.php' );
	await page.evaluate( () => window.wp.data.dispatch( 'core/preferences' ).set( 'core', 'welcomeGuide', false ) );
	// open the PKIW panel and flip the toggle
	await page.getByRole( 'button', { name: /Post Kinds/i } ).click();
	await page.getByLabel( /Promote to main archive/i ).check();
	await expect.poll( () =>
		page.evaluate( () => window.wp.data.select( 'core/editor' ).getEditedPostAttribute( 'meta' ).pk_promote )
	).toBe( true );
} );
```

- [ ] **Step 2: Run to verify it fails**

Run: `npm run test:e2e -- --project=chromium promote-toggle`
Expected: FAIL — toggle not found.

- [ ] **Step 3: Implement the panel**

```js
// src/editor/promote-panel/index.js
import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { ToggleControl } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

const PromotePanel = () => {
	const { postType, promote } = useSelect( ( select ) => ( {
		postType: select( 'core/editor' ).getCurrentPostType(),
		promote: select( 'core/editor' ).getEditedPostAttribute( 'meta' )?.pk_promote,
	} ), [] );
	const { editPost } = useDispatch( 'core/editor' );
	if ( postType !== 'post' ) return null;
	return (
		<PluginDocumentSettingPanel name="pk-promote" title={ __( 'Post surface', 'post-kinds-for-indieweb' ) }>
			<ToggleControl
				label={ __( 'Promote to main archive', 'post-kinds-for-indieweb' ) }
				help={ __( 'Show this post on the main archive even if its kind is normally stream-only.', 'post-kinds-for-indieweb' ) }
				checked={ !! promote }
				onChange={ ( value ) => editPost( { meta: { pk_promote: value } } ) }
			/>
		</PluginDocumentSettingPanel>
	);
};
registerPlugin( 'pk-promote-panel', { render: PromotePanel } );
```

```js
// src/editor/index.js — add near other imports:
import './promote-panel';
```

- [ ] **Step 4: Build and run the test**

Run: `npm run build && npm run test:e2e -- --project=chromium promote-toggle`
Expected: PASS.

- [ ] **Step 5: Commit** (include rebuilt assets — `build/` is tracked)

```bash
git add src/editor/promote-panel/index.js src/editor/index.js build/ tests/e2e/promote-toggle.spec.js
git commit -m "📦 NEW: editor toggle to promote a post to the main archive

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

### Task 5: Micropub `pk-promote` → `pk_promote`

**Files:**
- Modify: `includes/class-micropub-content-builder.php` (or the confirmed handler)
- Test: `tests/phpunit/integration/MicropubPromoteTest.php`

**Interfaces:**
- Consumes: the `pk_promote` meta from Task 2.
- Produces: on Micropub create, a truthy `pk-promote` property sets `pk_promote` meta on the new post.

- [ ] **Step 0 (discovery): confirm the handler.** Run:

```bash
grep -rn "micropub" includes/*.php | grep -iE "add_action|add_filter|save|create|properties"
grep -rn "kentarok\|micropub_post_content\|before_micropub\|after_micropub\|indieblocks" includes/class-micropub-content-builder.php
```

Identify the hook that fires with the incoming Micropub properties and the new post ID (PKIW's own builder if it owns creation; otherwise the `micropub_post_saved`/`after_micropub_create` action the active Micropub plugin exposes). Use that hook in Step 3. If PKIW does not process creation at all, this task moves to the plan for whichever plugin does — record that finding and stop.

- [ ] **Step 1: Write the failing test** (simulate the mapping unit — the function that reads properties and sets meta)

```php
// tests/phpunit/integration/MicropubPromoteTest.php
final class MicropubPromoteTest extends WP_UnitTestCase {
	public function test_pk_promote_property_sets_meta(): void {
		$id = self::factory()->post->create();
		\PostKindsForIndieWeb\Micropub_Content_Builder::apply_promote( $id, [ 'pk-promote' => [ '1' ] ] );
		$this->assertSame( '1', get_post_meta( $id, 'pk_promote', true ) );
	}
	public function test_absent_property_leaves_meta_unset(): void {
		$id = self::factory()->post->create();
		\PostKindsForIndieWeb\Micropub_Content_Builder::apply_promote( $id, [] );
		$this->assertSame( '', get_post_meta( $id, 'pk_promote', true ) );
	}
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `composer test -- --filter MicropubPromoteTest`
Expected: FAIL — `apply_promote` undefined.

- [ ] **Step 3: Implement the mapping and hook it** (method name/hook per Step 0 finding)

```php
// in class-micropub-content-builder.php:
	public static function apply_promote( int $post_id, array $properties ): void {
		if ( empty( $properties['pk-promote'] ) ) { return; }
		$val = is_array( $properties['pk-promote'] ) ? reset( $properties['pk-promote'] ) : $properties['pk-promote'];
		if ( in_array( (string) $val, [ '1', 'true', 'yes', 'on' ], true ) ) {
			update_post_meta( $post_id, 'pk_promote', 1 );
		}
	}
```

Wire it to the create hook found in Step 0, e.g.:
```php
add_action( '<confirmed_micropub_create_hook>', [ self::class, 'apply_promote' ], 10, 2 );
```

- [ ] **Step 4: Run tests**

Run: `composer test -- --filter MicropubPromoteTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/class-micropub-content-builder.php tests/phpunit/integration/MicropubPromoteTest.php
git commit -m "📦 NEW: map Micropub pk-promote property to pk_promote meta

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

### Task 6: Document the primitive + recommended default set

**Files:**
- Modify: `readme.txt` (a features/FAQ entry — NOT the `== Changelog ==` section)
- Modify: `CHANGELOG.md` (Unreleased/Added)

- [ ] **Step 1: Add a readme FAQ entry**

Add under the FAQ (above `== Changelog ==`):

```
= How do I route certain kinds into a separate "stream" instead of the main archive? =

Add the kind slugs to the `pk_stream_kinds` filter. Those kinds get a cached
`_pk_surface` value of `stream` (others get `main`); your theme decides what to
do with it. Promote an individual post back to `main` with the "Promote to main
archive" toggle, the Micropub `pk-promote` property, or by setting the
`pk_promote` meta. Recommended starting set: checkin, eat, drink, listen, jam.

    add_filter( 'pk_stream_kinds', fn() => [ 'checkin', 'eat', 'drink', 'listen', 'jam' ] );
```

- [ ] **Step 2: Add CHANGELOG entry**

Under `## [Unreleased]` → `### Added`:

```
- Post-surface classification: a `pk_stream_kinds` filter marks kinds as ephemeral (`stream`) vs `main`, cached in `_pk_surface`; a `pk_promote` override is settable via the editor toggle, the Micropub `pk-promote` property, or `wp pk surfaces backfill`. PKIW emits the signal only; themes decide how to use it.
```

- [ ] **Step 3: Commit**

```bash
git add readme.txt CHANGELOG.md
git commit -m "📖 DOC: document pk_stream_kinds surface primitive and pk_promote override

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

### Task 7: Full-suite green + PR

- [ ] **Step 1:** `composer lint` → rc 0.
- [ ] **Step 2:** `composer analyze` → `[OK] No errors`.
- [ ] **Step 3:** `composer test` → full suite OK.
- [ ] **Step 4:** `npm run lint:js && npm run build` → clean, `build/` staged if changed.
- [ ] **Step 5:** Push branch `feature/post-surfaces`; open PR titled `📦 NEW: post-surface routing primitive (pk_stream_kinds / pk_promote)`; plain-prose body; `gh pr merge --squash --auto`. Do not tag or release — installing on staging happens via the plan's verification step, and any wp.org/release is a separate, explicitly-gated action.

## Staging verification (after merge, before any release)

- Build the plugin zip from the merged commit; `wp @staging plugin install <zip> --force` (same method used for the 1.2.0 push).
- `wp @staging eval` a check: create a `checkin` post with `add_filter('pk_stream_kinds', …)` active via a throwaway mu-snippet → assert `_pk_surface = stream`; set `pk_promote` → `main`.
- Confirm no query behavior changed anywhere (this layer filters nothing): `/feed/` and the Blog page identical to before.

## Self-Review Notes

- Spec coverage: `pk_stream_kinds` (T1), `pk_promote`+`_pk_surface`+save (T2), CLI backfill (T3), editor toggle (T4), Micropub mapping (T5), docs/default set (T6). Site wiring and Outpost composer are the two follow-on plans, out of scope here by design.
- Naming matches the spec's Global Constraints verbatim.
- `Post_Surface::get()` static and used consistently across T1–T3, T5.
