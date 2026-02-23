# Full Test Coverage Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add 83 new test files across PHPUnit, Jest, and Playwright to achieve full test coverage for the Post Kinds for IndieWeb plugin.

**Architecture:** Layer-by-layer, bottom-up. Start with core PHP unit tests, then API clients with record-and-replay fixtures, sync services, admin classes, Jest block tests, Playwright E2E, and finally integration tests. Each layer builds on the patterns established in the previous one.

**Tech Stack:** PHPUnit 9.6 with WP_UnitTestCase, Jest with @testing-library/react, Playwright with wp-env, JSON fixtures for API mocking via `pre_http_request` filter.

---

## Conventions

- **Namespace:** `PostKindsForIndieWeb\Tests\Unit` for unit tests, `PostKindsForIndieWeb\Tests\Integration` for integration tests
- **Base class:** `WP_UnitTestCase` for all PHPUnit tests
- **PHP test commands:** `composer test:unit` or `vendor/bin/phpunit --testsuite unit`
- **JS test commands:** `npm run test:unit` or `npx wp-scripts test-unit-js --config jest.config.js`
- **E2E test commands:** `npx playwright test`
- **Commit prefix:** `test:` for all test-only commits

---

## Layer 1: PHPUnit Unit Tests for Core Classes

### Task 1.1: TaxonomyTest

**Files:**
- Create: `tests/phpunit/unit/TaxonomyTest.php`

**Step 1: Write the test file**

```php
<?php
namespace PostKindsForIndieWeb\Tests\Unit;

use WP_UnitTestCase;
use PostKindsForIndieWeb\Taxonomy;

class TaxonomyTest extends WP_UnitTestCase {

	private Taxonomy $taxonomy;

	public function set_up(): void {
		parent::set_up();
		$this->taxonomy = new Taxonomy();
	}

	public function test_taxonomy_constant() {
		$this->assertSame( 'kind', Taxonomy::TAXONOMY );
	}

	public function test_taxonomy_is_registered() {
		$this->assertTrue( taxonomy_exists( Taxonomy::TAXONOMY ) );
	}

	public function test_taxonomy_is_public() {
		$tax = get_taxonomy( Taxonomy::TAXONOMY );
		$this->assertTrue( $tax->public );
	}

	public function test_taxonomy_shows_in_rest() {
		$tax = get_taxonomy( Taxonomy::TAXONOMY );
		$this->assertTrue( $tax->show_in_rest );
		$this->assertSame( 'kind', $tax->rest_base );
	}

	public function test_taxonomy_is_not_hierarchical() {
		$tax = get_taxonomy( Taxonomy::TAXONOMY );
		$this->assertFalse( $tax->hierarchical );
	}

	public function test_taxonomy_attached_to_post() {
		$tax = get_taxonomy( Taxonomy::TAXONOMY );
		$this->assertContains( 'post', $tax->object_type );
	}

	public function test_taxonomy_default_term_is_note() {
		$tax = get_taxonomy( Taxonomy::TAXONOMY );
		$this->assertSame( 'note', $tax->default_term['slug'] );
	}

	public function test_get_default_kinds_returns_24() {
		$kinds = $this->taxonomy->get_default_kinds();
		$this->assertCount( 24, $kinds );
	}

	/**
	 * @dataProvider kind_slugs_provider
	 */
	public function test_default_kind_exists( string $slug ) {
		$kinds = $this->taxonomy->get_default_kinds();
		$this->assertArrayHasKey( $slug, $kinds );
	}

	public function kind_slugs_provider(): array {
		return [
			'note'        => [ 'note' ],
			'article'     => [ 'article' ],
			'reply'       => [ 'reply' ],
			'like'        => [ 'like' ],
			'repost'      => [ 'repost' ],
			'bookmark'    => [ 'bookmark' ],
			'rsvp'        => [ 'rsvp' ],
			'checkin'     => [ 'checkin' ],
			'listen'      => [ 'listen' ],
			'watch'       => [ 'watch' ],
			'read'        => [ 'read' ],
			'event'       => [ 'event' ],
			'photo'       => [ 'photo' ],
			'video'       => [ 'video' ],
			'review'      => [ 'review' ],
			'favorite'    => [ 'favorite' ],
			'jam'         => [ 'jam' ],
			'wish'        => [ 'wish' ],
			'mood'        => [ 'mood' ],
			'acquisition' => [ 'acquisition' ],
			'drink'       => [ 'drink' ],
			'eat'         => [ 'eat' ],
			'recipe'      => [ 'recipe' ],
			'play'        => [ 'play' ],
		];
	}

	/**
	 * @dataProvider kind_slugs_provider
	 */
	public function test_default_kind_has_name_and_description( string $slug ) {
		$kinds = $this->taxonomy->get_default_kinds();
		$this->assertNotEmpty( $kinds[ $slug ]['name'] );
		$this->assertNotEmpty( $kinds[ $slug ]['description'] );
	}

	public function test_set_and_get_post_kind() {
		$post_id = self::factory()->post->create();
		wp_insert_term( 'listen', Taxonomy::TAXONOMY );

		$result = $this->taxonomy->set_post_kind( $post_id, 'listen' );
		$this->assertTrue( $result );

		$term = $this->taxonomy->get_post_kind( $post_id );
		$this->assertNotNull( $term );
		$this->assertSame( 'listen', $term->slug );
	}

	public function test_get_post_kind_returns_null_when_unset() {
		$post_id = self::factory()->post->create();
		$term    = $this->taxonomy->get_post_kind( $post_id );
		$this->assertNull( $term );
	}

	public function test_is_valid_kind_with_existing_term() {
		wp_insert_term( 'note', Taxonomy::TAXONOMY );
		$this->assertTrue( $this->taxonomy->is_valid_kind( 'note' ) );
	}

	public function test_is_valid_kind_with_nonexistent_term() {
		$this->assertFalse( $this->taxonomy->is_valid_kind( 'nonexistent_kind_xyz' ) );
	}

	public function test_get_kinds_returns_terms() {
		wp_insert_term( 'note', Taxonomy::TAXONOMY );
		wp_insert_term( 'like', Taxonomy::TAXONOMY );

		$kinds = $this->taxonomy->get_kinds();
		$this->assertIsArray( $kinds );
		$this->assertNotEmpty( $kinds );
	}

	public function test_get_post_types_includes_post() {
		$this->assertContains( 'post', $this->taxonomy->get_post_types() );
	}

	public function test_taxonomy_capabilities() {
		$tax = get_taxonomy( Taxonomy::TAXONOMY );
		$this->assertSame( 'manage_categories', $tax->cap->manage_terms );
		$this->assertSame( 'edit_posts', $tax->cap->assign_terms );
	}

	public function test_filter_term_link_passes_through_other_taxonomies() {
		$term     = (object) [ 'slug' => 'test' ];
		$term->slug = 'test';
		$wp_term  = new \WP_Term( $term );
		$result   = $this->taxonomy->filter_term_link( 'http://example.com/test', $wp_term, 'category' );
		$this->assertSame( 'http://example.com/test', $result );
	}
}
```

**Step 2: Run the test**

Run: `vendor/bin/phpunit --filter TaxonomyTest`
Expected: All tests PASS

**Step 3: Commit**

```bash
git add tests/phpunit/unit/TaxonomyTest.php
git commit -m "test: add TaxonomyTest covering constants, 24 kinds, CRUD, capabilities"
```

### Task 1.2: MicroformatsTest

**Files:**
- Create: `tests/phpunit/unit/MicroformatsTest.php`

**Step 1: Write the test file**

```php
<?php
namespace PostKindsForIndieWeb\Tests\Unit;

use WP_UnitTestCase;
use PostKindsForIndieWeb\Microformats;
use PostKindsForIndieWeb\Taxonomy;
use PostKindsForIndieWeb\Meta_Fields;

class MicroformatsTest extends WP_UnitTestCase {

	private Microformats $microformats;

	public function set_up(): void {
		parent::set_up();
		$this->microformats = new Microformats();
	}

	/**
	 * @dataProvider kind_root_class_provider
	 */
	public function test_kind_has_correct_root_class( string $kind, string $expected_root ) {
		$format = $this->microformats->get_kind_format( $kind );
		$this->assertNotNull( $format, "Format should exist for kind: $kind" );
		$this->assertContains( $expected_root, $format['root'] );
	}

	public function kind_root_class_provider(): array {
		return [
			'note'     => [ 'note', 'h-entry' ],
			'article'  => [ 'article', 'h-entry' ],
			'reply'    => [ 'reply', 'h-entry' ],
			'like'     => [ 'like', 'h-entry' ],
			'repost'   => [ 'repost', 'h-entry' ],
			'bookmark' => [ 'bookmark', 'h-entry' ],
			'rsvp'     => [ 'rsvp', 'h-entry' ],
			'checkin'  => [ 'checkin', 'h-entry' ],
			'listen'   => [ 'listen', 'h-entry' ],
			'watch'    => [ 'watch', 'h-entry' ],
			'read'     => [ 'read', 'h-entry' ],
			'event'    => [ 'event', 'h-event' ],
			'photo'    => [ 'photo', 'h-entry' ],
			'video'    => [ 'video', 'h-entry' ],
			'review'   => [ 'review', 'h-review' ],
			'recipe'   => [ 'recipe', 'h-recipe' ],
		];
	}

	public function test_like_has_u_like_of_property() {
		$format = $this->microformats->get_kind_format( 'like' );
		$this->assertSame( 'u-like-of', $format['properties']['like-of'] );
	}

	public function test_bookmark_has_u_bookmark_of_property() {
		$format = $this->microformats->get_kind_format( 'bookmark' );
		$this->assertSame( 'u-bookmark-of', $format['properties']['bookmark-of'] );
	}

	public function test_rsvp_has_p_rsvp_property() {
		$format = $this->microformats->get_kind_format( 'rsvp' );
		$this->assertSame( 'p-rsvp', $format['properties']['rsvp'] );
	}

	public function test_checkin_has_h_card_property() {
		$format = $this->microformats->get_kind_format( 'checkin' );
		$this->assertSame( 'u-checkin h-card', $format['properties']['checkin'] );
	}

	public function test_listen_has_u_listen_of_property() {
		$format = $this->microformats->get_kind_format( 'listen' );
		$this->assertSame( 'u-listen-of', $format['properties']['listen-of'] );
	}

	public function test_watch_has_u_watch_of_property() {
		$format = $this->microformats->get_kind_format( 'watch' );
		$this->assertSame( 'u-watch-of', $format['properties']['watch-of'] );
	}

	public function test_read_has_u_read_of_property() {
		$format = $this->microformats->get_kind_format( 'read' );
		$this->assertSame( 'u-read-of', $format['properties']['read-of'] );
	}

	public function test_event_has_dt_start_property() {
		$format = $this->microformats->get_kind_format( 'event' );
		$this->assertSame( 'dt-start', $format['properties']['start'] );
		$this->assertSame( 'dt-end', $format['properties']['end'] );
	}

	public function test_review_has_rating_properties() {
		$format = $this->microformats->get_kind_format( 'review' );
		$this->assertSame( 'p-rating', $format['properties']['rating'] );
		$this->assertSame( 'p-best', $format['properties']['best'] );
	}

	public function test_get_kind_format_returns_null_for_unknown() {
		$this->assertNull( $this->microformats->get_kind_format( 'not_a_kind' ) );
	}

	public function test_get_all_formats_returns_array() {
		$formats = $this->microformats->get_all_formats();
		$this->assertIsArray( $formats );
		$this->assertNotEmpty( $formats );
	}

	public function test_add_post_classes_defaults_to_h_entry() {
		$post_id = self::factory()->post->create();
		$classes = $this->microformats->add_post_classes( [ 'post' ], [], $post_id );
		$this->assertContains( 'h-entry', $classes );
	}

	public function test_add_post_classes_with_kind() {
		$post_id = self::factory()->post->create();
		wp_insert_term( 'like', Taxonomy::TAXONOMY );
		wp_set_post_terms( $post_id, [ 'like' ], Taxonomy::TAXONOMY );

		$classes = $this->microformats->add_post_classes( [ 'post' ], [], $post_id );
		$this->assertContains( 'h-entry', $classes );
		$this->assertContains( 'kind-like', $classes );
	}

	public function test_add_post_classes_with_event_uses_h_event() {
		$post_id = self::factory()->post->create();
		wp_insert_term( 'event', Taxonomy::TAXONOMY );
		wp_set_post_terms( $post_id, [ 'event' ], Taxonomy::TAXONOMY );

		$classes = $this->microformats->add_post_classes( [ 'post' ], [], $post_id );
		$this->assertContains( 'h-event', $classes );
		$this->assertContains( 'kind-event', $classes );
	}

	public function test_add_post_classes_returns_unique() {
		$post_id = self::factory()->post->create();
		$classes = $this->microformats->add_post_classes(
			[ 'post', 'h-entry' ],
			[],
			$post_id
		);
		$counts = array_count_values( $classes );
		$this->assertSame( 1, $counts['h-entry'] );
	}

	public function test_filter_block_output_skips_empty() {
		$block    = [ 'blockName' => 'core/paragraph' ];
		$instance = $this->createMock( \WP_Block::class );
		$instance->context = [];

		$result = $this->microformats->filter_block_output( '', $block, $instance );
		$this->assertSame( '', $result );
	}

	/**
	 * @dataProvider indieblocks_provider
	 */
	public function test_filter_block_output_skips_indieblocks( string $block_name ) {
		$block    = [ 'blockName' => $block_name ];
		$instance = $this->createMock( \WP_Block::class );
		$instance->context = [];
		$content  = '<div>test</div>';

		$result = $this->microformats->filter_block_output( $content, $block, $instance );
		$this->assertSame( $content, $result );
	}

	public function indieblocks_provider(): array {
		return [
			[ 'indieblocks/bookmark' ],
			[ 'indieblocks/like' ],
			[ 'indieblocks/reply' ],
			[ 'indieblocks/repost' ],
			[ 'indieblocks/context' ],
			[ 'indieblocks/facepile' ],
			[ 'indieblocks/location' ],
			[ 'indieblocks/syndication' ],
			[ 'indieblocks/link-preview' ],
		];
	}

	public function test_add_hidden_mf2_data_for_rsvp() {
		$post_id = self::factory()->post->create();
		wp_insert_term( 'rsvp', Taxonomy::TAXONOMY );
		wp_set_post_terms( $post_id, [ 'rsvp' ], Taxonomy::TAXONOMY );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'rsvp_status', 'yes' );

		$this->go_to( get_permalink( $post_id ) );
		global $post;
		$post = get_post( $post_id );
		setup_postdata( $post );

		$result = $this->microformats->add_hidden_mf2_data( '<p>Content</p>' );

		$this->assertStringContainsString( 'p-rsvp', $result );
		$this->assertStringContainsString( 'value="yes"', $result );

		wp_reset_postdata();
	}

	public function test_add_hidden_mf2_data_for_review() {
		$post_id = self::factory()->post->create();
		wp_insert_term( 'review', Taxonomy::TAXONOMY );
		wp_set_post_terms( $post_id, [ 'review' ], Taxonomy::TAXONOMY );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'review_rating', '4' );

		$this->go_to( get_permalink( $post_id ) );
		global $post;
		$post = get_post( $post_id );
		setup_postdata( $post );

		$result = $this->microformats->add_hidden_mf2_data( '<p>Content</p>' );

		$this->assertStringContainsString( 'p-rating', $result );
		$this->assertStringContainsString( 'value="4"', $result );
		$this->assertStringContainsString( 'p-best', $result );

		wp_reset_postdata();
	}

	public function test_xss_prevented_in_hidden_mf2_data() {
		$post_id = self::factory()->post->create();
		wp_insert_term( 'rsvp', Taxonomy::TAXONOMY );
		wp_set_post_terms( $post_id, [ 'rsvp' ], Taxonomy::TAXONOMY );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'rsvp_status', '<script>alert(1)</script>' );

		$this->go_to( get_permalink( $post_id ) );
		global $post;
		$post = get_post( $post_id );
		setup_postdata( $post );

		$result = $this->microformats->add_hidden_mf2_data( '' );
		$this->assertStringNotContainsString( '<script>', $result );

		wp_reset_postdata();
	}
}
```

**Step 2: Run the test**

Run: `vendor/bin/phpunit --filter MicroformatsTest`
Expected: All tests PASS

**Step 3: Commit**

```bash
git add tests/phpunit/unit/MicroformatsTest.php
git commit -m "test: add MicroformatsTest covering mf2 mappings, post classes, hidden data, XSS"
```

### Task 1.3: BlockBindingsTest

**Files:**
- Create: `tests/phpunit/unit/BlockBindingsTest.php`

**Step 1: Write the test file**

```php
<?php
namespace PostKindsForIndieWeb\Tests\Unit;

use WP_UnitTestCase;
use PostKindsForIndieWeb\Block_Bindings;
use PostKindsForIndieWeb\Meta_Fields;

class BlockBindingsTest extends WP_UnitTestCase {

	private Block_Bindings $bindings;

	public function set_up(): void {
		parent::set_up();
		$this->bindings = new Block_Bindings();
	}

	public function test_source_name_constant() {
		$this->assertSame( 'post-kinds-indieweb/kind-meta', Block_Bindings::SOURCE_NAME );
	}

	public function test_bindings_source_registered() {
		$this->bindings->register_block_bindings_source();
		$sources = get_all_registered_block_bindings_sources();
		$this->assertArrayHasKey( Block_Bindings::SOURCE_NAME, $sources );
	}

	public function test_get_bindings_returns_array() {
		$bindings = $this->bindings->get_bindings();
		$this->assertIsArray( $bindings );
		$this->assertNotEmpty( $bindings );
	}

	/**
	 * @dataProvider binding_keys_provider
	 */
	public function test_binding_key_is_valid( string $key ) {
		$this->assertTrue( $this->bindings->is_valid_binding( $key ) );
	}

	public function binding_keys_provider(): array {
		return [
			[ 'cite_name' ],
			[ 'cite_url' ],
			[ 'rsvp_status' ],
			[ 'checkin_name' ],
			[ 'listen_track' ],
			[ 'listen_artist' ],
			[ 'watch_title' ],
			[ 'watch_poster' ],
			[ 'read_title' ],
			[ 'read_author' ],
			[ 'event_start' ],
			[ 'review_rating' ],
		];
	}

	public function test_invalid_binding_key() {
		$this->assertFalse( $this->bindings->is_valid_binding( 'nonexistent_key' ) );
	}

	public function test_get_binding_value_returns_meta() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'listen_track', 'Bohemian Rhapsody' );

		$block    = $this->createMock( \WP_Block::class );
		$block->context = [ 'postId' => $post_id ];

		$value = $this->bindings->get_binding_value(
			[ 'key' => 'listen_track' ],
			$block,
			'content'
		);

		$this->assertSame( 'Bohemian Rhapsody', $value );
	}

	public function test_get_binding_value_returns_null_for_empty() {
		$post_id = self::factory()->post->create();

		$block    = $this->createMock( \WP_Block::class );
		$block->context = [ 'postId' => $post_id ];

		$value = $this->bindings->get_binding_value(
			[ 'key' => 'listen_track' ],
			$block,
			'content'
		);

		$this->assertNull( $value );
	}

	public function test_get_binding_value_returns_null_for_invalid_key() {
		$block    = $this->createMock( \WP_Block::class );
		$block->context = [ 'postId' => 1 ];

		$value = $this->bindings->get_binding_value(
			[ 'key' => 'bad_key' ],
			$block,
			'content'
		);

		$this->assertNull( $value );
	}

	public function test_rsvp_format_returns_label() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'rsvp_status', 'yes' );

		$block    = $this->createMock( \WP_Block::class );
		$block->context = [ 'postId' => $post_id ];

		$value = $this->bindings->get_binding_value(
			[ 'key' => 'rsvp_status' ],
			$block,
			'content'
		);

		$this->assertSame( 'Yes, attending', $value );
	}

	public function test_computed_listen_display() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'listen_track', 'Yesterday' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'listen_artist', 'The Beatles' );

		$block    = $this->createMock( \WP_Block::class );
		$block->context = [ 'postId' => $post_id ];

		$value = $this->bindings->get_binding_value(
			[ 'key' => 'listen_display' ],
			$block,
			'content'
		);

		$this->assertStringContainsString( 'Yesterday', $value );
		$this->assertStringContainsString( 'The Beatles', $value );
	}

	public function test_computed_coordinates() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_latitude', '40.7128' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_longitude', '-74.0060' );

		$block    = $this->createMock( \WP_Block::class );
		$block->context = [ 'postId' => $post_id ];

		$value = $this->bindings->get_binding_value(
			[ 'key' => 'geo_coordinates' ],
			$block,
			'content'
		);

		$this->assertSame( '40.7128, -74.0060', $value );
	}

	public function test_get_bindings_for_editor() {
		$editor_bindings = $this->bindings->get_bindings_for_editor();
		$this->assertIsArray( $editor_bindings );
		$this->assertArrayHasKey( 'cite_name', $editor_bindings );
		$this->assertIsString( $editor_bindings['cite_name'] );
	}
}
```

**Step 2: Run the test**

Run: `vendor/bin/phpunit --filter BlockBindingsTest`
Expected: All tests PASS

**Step 3: Commit**

```bash
git add tests/phpunit/unit/BlockBindingsTest.php
git commit -m "test: add BlockBindingsTest covering source registration, meta values, computed fields"
```

### Task 1.4: WebhookHandlerTest

**Files:**
- Create: `tests/phpunit/unit/WebhookHandlerTest.php`

**Step 1: Write the test file**

```php
<?php
namespace PostKindsForIndieWeb\Tests\Unit;

use WP_UnitTestCase;
use PostKindsForIndieWeb\Webhook_Handler;

class WebhookHandlerTest extends WP_UnitTestCase {

	private Webhook_Handler $handler;

	public function set_up(): void {
		parent::set_up();
		$this->handler = new Webhook_Handler();
	}

	public function test_get_endpoints_returns_five() {
		$endpoints = $this->handler->get_endpoints();
		$this->assertCount( 5, $endpoints );
	}

	/**
	 * @dataProvider endpoint_names_provider
	 */
	public function test_endpoint_exists( string $name ) {
		$endpoints = $this->handler->get_endpoints();
		$this->assertArrayHasKey( $name, $endpoints );
	}

	public function endpoint_names_provider(): array {
		return [
			[ 'plex' ],
			[ 'jellyfin' ],
			[ 'trakt' ],
			[ 'listenbrainz' ],
			[ 'generic' ],
		];
	}

	public function test_plex_uses_token_auth() {
		$endpoints = $this->handler->get_endpoints();
		$this->assertSame( 'token', $endpoints['plex']['auth_type'] );
	}

	public function test_plex_uses_multipart_content_type() {
		$endpoints = $this->handler->get_endpoints();
		$this->assertSame( 'multipart/form-data', $endpoints['plex']['content_type'] );
	}

	public function test_trakt_uses_no_auth() {
		$endpoints = $this->handler->get_endpoints();
		$this->assertSame( 'none', $endpoints['trakt']['auth_type'] );
	}

	public function test_handle_request_unknown_service_returns_error() {
		$request = new \WP_REST_Request( 'POST' );
		$result  = $this->handler->handle_request( $request, 'nonexistent' );

		$this->assertWPError( $result );
		$this->assertSame( 'unknown_service', $result->get_error_code() );
	}

	public function test_handle_request_missing_token_returns_401() {
		$request = new \WP_REST_Request( 'POST' );
		$result  = $this->handler->handle_request( $request, 'plex' );

		$this->assertWPError( $result );
		$this->assertSame( 'missing_token', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_handle_request_invalid_token_returns_403() {
		update_option( 'post_kinds_webhook_token_plex', 'valid_token_123' );

		$request = new \WP_REST_Request( 'POST' );
		$request->set_header( 'X-Webhook-Token', 'wrong_token' );
		$request->set_body( '{}' );
		$result = $this->handler->handle_request( $request, 'plex' );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_token', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_handle_request_valid_token_passes_auth() {
		$token = 'valid_token_123';
		update_option( 'post_kinds_webhook_token_plex', $token );
		update_option( 'post_kinds_webhook_auto_post', false );

		$request = new \WP_REST_Request( 'POST' );
		$request->set_header( 'X-Webhook-Token', $token );
		$request->set_header( 'Content-Type', 'multipart/form-data' );
		$request->set_param( 'payload', wp_json_encode( [
			'event'    => 'media.scrobble',
			'Metadata' => [
				'type'  => 'track',
				'title' => 'Test Track',
				'grandparentTitle' => 'Test Artist',
				'parentTitle'      => 'Test Album',
			],
			'Account' => [ 'title' => 'User' ],
			'Player'  => [ 'title' => 'Player' ],
		] ) );

		$result = $this->handler->handle_request( $request, 'plex' );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$data = $result->get_data();
		$this->assertTrue( $data['success'] );
	}

	public function test_bearer_token_auth() {
		$token = 'bearer_test_token';
		update_option( 'post_kinds_webhook_token_jellyfin', $token );

		$request = new \WP_REST_Request( 'POST' );
		$request->set_header( 'Authorization', 'Bearer ' . $token );
		$request->set_body( wp_json_encode( [ 'NotificationType' => 'ignored_event' ] ) );
		$result = $this->handler->handle_request( $request, 'jellyfin' );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
	}

	public function test_plex_ignores_non_scrobble_events() {
		$token = 'plex_token';
		update_option( 'post_kinds_webhook_token_plex', $token );

		$request = new \WP_REST_Request( 'POST' );
		$request->set_header( 'X-Webhook-Token', $token );
		$request->set_param( 'payload', wp_json_encode( [ 'event' => 'media.play' ] ) );

		$result = $this->handler->handle_request( $request, 'plex' );
		$data   = $result->get_data();
		$this->assertSame( 'ignored', $data['data']['action'] );
	}

	public function test_generate_token() {
		$token = $this->handler->generate_token( 'test_service' );
		$this->assertNotEmpty( $token );
		$this->assertSame( 32, strlen( $token ) );

		$stored = get_option( 'post_kinds_webhook_token_test_service' );
		$this->assertSame( $token, $stored );
	}

	public function test_get_webhook_url() {
		$url = $this->handler->get_webhook_url( 'plex' );
		$this->assertStringContainsString( 'post-kinds-indieweb/v1/webhook/plex', $url );
	}

	public function test_pending_scrobbles_crud() {
		update_option( 'post_kinds_webhook_auto_post', false );
		delete_option( 'post_kinds_pending_scrobbles' );

		$this->assertEmpty( $this->handler->get_pending_scrobbles() );

		// Trigger a scrobble via Trakt (no auth).
		$request = new \WP_REST_Request( 'POST' );
		$request->set_body( wp_json_encode( [
			'action' => 'scrobble',
			'movie'  => [
				'title' => 'Test Movie',
				'year'  => 2024,
				'ids'   => [ 'trakt' => '123', 'imdb' => 'tt123', 'tmdb' => '456' ],
			],
		] ) );

		$this->handler->handle_request( $request, 'trakt' );

		$pending = $this->handler->get_pending_scrobbles();
		$this->assertCount( 1, $pending );

		$this->assertTrue( $this->handler->reject_scrobble( 0 ) );
		$this->assertEmpty( $this->handler->get_pending_scrobbles() );
	}

	public function test_invalid_json_returns_400() {
		$token = 'json_test_token';
		update_option( 'post_kinds_webhook_token_jellyfin', $token );

		$request = new \WP_REST_Request( 'POST' );
		$request->set_header( 'X-Webhook-Token', $token );
		$request->set_body( 'not valid json{{{' );
		$result = $this->handler->handle_request( $request, 'jellyfin' );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_json', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}
}
```

**Step 2: Run the test**

Run: `vendor/bin/phpunit --filter WebhookHandlerTest`
Expected: All tests PASS

**Step 3: Commit**

```bash
git add tests/phpunit/unit/WebhookHandlerTest.php
git commit -m "test: add WebhookHandlerTest covering endpoints, auth, scrobble flow, JSON validation"
```

### Task 1.5: QueryFilterTest

**Files:**
- Create: `tests/phpunit/unit/QueryFilterTest.php`

**Step 1: Write the test file**

```php
<?php
namespace PostKindsForIndieWeb\Tests\Unit;

use WP_UnitTestCase;
use PostKindsForIndieWeb\Query_Filter;

class QueryFilterTest extends WP_UnitTestCase {

	private Query_Filter $filter;

	public function set_up(): void {
		parent::set_up();
		$this->filter = new Query_Filter();
	}

	public function test_is_imported_post_with_imported_meta() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_postkind_imported_from', 'lastfm' );
		$this->assertTrue( Query_Filter::is_imported_post( $post_id ) );
	}

	public function test_is_imported_post_without_meta() {
		$post_id = self::factory()->post->create();
		$this->assertFalse( Query_Filter::is_imported_post( $post_id ) );
	}

	public function test_is_imported_post_with_empty_meta() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_postkind_imported_from', '' );
		$this->assertFalse( Query_Filter::is_imported_post( $post_id ) );
	}
}
```

**Step 2: Run, then commit**

Run: `vendor/bin/phpunit --filter QueryFilterTest`

```bash
git add tests/phpunit/unit/QueryFilterTest.php
git commit -m "test: add QueryFilterTest covering imported post detection"
```

### Task 1.6-1.9: Remaining Core Tests

Create these files following the same pattern:

**`tests/phpunit/unit/VenueTaxonomyTest.php`** - Test class: `PostKindsForIndieWeb\Venue_Taxonomy`
- Test registration args (taxonomy slug, post types)
- Test term CRUD operations
- Test venue metadata

**`tests/phpunit/unit/PostTypeTest.php`** - Test class: `PostKindsForIndieWeb\Post_Type`
- Test reaction CPT registration in CPT mode
- Test `is_hidden_mode()` static method
- Test post type args

**`tests/phpunit/unit/ImportManagerTest.php`** - Test class: `PostKindsForIndieWeb\Import_Manager`
- Test 11 valid sources validation
- Test `start_import()` creates job with pending status
- Test `get_status()` returns job data
- Test `cancel_import()` sets status to cancelled
- Test invalid source rejection

**`tests/phpunit/unit/ScheduledSyncTest.php`** - Test class: `PostKindsForIndieWeb\Scheduled_Sync`
- Test `schedule_cron()` registers WP cron event
- Test `unschedule_cron()` removes event
- Test interval registration
- Test `run_scheduled_sync()` delegates to Import_Manager

For each file: write, run `vendor/bin/phpunit --filter <TestName>`, commit with `test: add <TestName>`.

---

## Layer 2: API Test Infrastructure + Client Tests

### Task 2.0: ApiTestCase Base Class

**Files:**
- Create: `tests/phpunit/ApiTestCase.php`

**Step 1: Write the base class**

```php
<?php
namespace PostKindsForIndieWeb\Tests;

use WP_UnitTestCase;

abstract class ApiTestCase extends WP_UnitTestCase {

	/**
	 * Mocked HTTP responses keyed by URL pattern.
	 *
	 * @var array<string, array>
	 */
	private array $mocked_responses = [];

	/**
	 * Recorded request URLs.
	 *
	 * @var array<string>
	 */
	private array $recorded_requests = [];

	public function set_up(): void {
		parent::set_up();
		$this->mocked_responses = [];
		$this->recorded_requests = [];
		add_filter( 'pre_http_request', [ $this, 'intercept_http_request' ], 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', [ $this, 'intercept_http_request' ], 10 );
		parent::tear_down();
	}

	/**
	 * Load a JSON fixture file.
	 *
	 * @param string $path Relative path within tests/phpunit/fixtures/.
	 * @return array Decoded JSON.
	 */
	protected function load_fixture( string $path ): array {
		$file = dirname( __DIR__ ) . '/fixtures/' . ltrim( $path, '/' );
		$this->assertFileExists( $file, "Fixture not found: $path" );
		$json = file_get_contents( $file );
		$data = json_decode( $json, true );
		$this->assertNotNull( $data, "Invalid JSON in fixture: $path" );
		return $data;
	}

	/**
	 * Mock an HTTP response for a URL pattern.
	 *
	 * @param string $url_pattern Substring to match in request URL.
	 * @param array|string $fixture Fixture path or raw response array.
	 * @param int $status HTTP status code.
	 * @param array $headers Response headers.
	 */
	protected function mock_http_response(
		string $url_pattern,
		$fixture,
		int $status = 200,
		array $headers = []
	): void {
		if ( is_string( $fixture ) ) {
			$body = wp_json_encode( $this->load_fixture( $fixture ) );
		} else {
			$body = wp_json_encode( $fixture );
		}

		$this->mocked_responses[ $url_pattern ] = [
			'response' => [ 'code' => $status, 'message' => 'OK' ],
			'body'     => $body,
			'headers'  => array_merge( [ 'content-type' => 'application/json' ], $headers ),
		];
	}

	/**
	 * Mock an HTTP error for a URL pattern.
	 *
	 * @param string $url_pattern Substring to match.
	 * @param string $error_message Error message.
	 */
	protected function mock_http_error( string $url_pattern, string $error_message = 'Connection failed' ): void {
		$this->mocked_responses[ $url_pattern ] = new \WP_Error( 'http_request_failed', $error_message );
	}

	/**
	 * Intercept WP HTTP requests and return mocked responses.
	 *
	 * @param mixed $preempt
	 * @param array $parsed_args
	 * @param string $url
	 * @return mixed
	 */
	public function intercept_http_request( $preempt, $parsed_args, $url ) {
		$this->recorded_requests[] = $url;

		foreach ( $this->mocked_responses as $pattern => $response ) {
			if ( str_contains( $url, $pattern ) ) {
				return $response;
			}
		}

		// No mock found - fail the test to prevent real HTTP calls.
		$this->fail( "Unmocked HTTP request to: $url" );
	}

	/**
	 * Assert that an HTTP request was made to a URL matching the pattern.
	 *
	 * @param string $url_pattern Substring to match.
	 */
	protected function assert_api_request_made( string $url_pattern ): void {
		foreach ( $this->recorded_requests as $url ) {
			if ( str_contains( $url, $url_pattern ) ) {
				$this->addToAssertionCount( 1 );
				return;
			}
		}
		$this->fail( "Expected HTTP request matching '$url_pattern' was not made." );
	}

	/**
	 * Assert no HTTP request was made to a URL matching the pattern.
	 *
	 * @param string $url_pattern Substring to match.
	 */
	protected function assert_no_api_request( string $url_pattern ): void {
		foreach ( $this->recorded_requests as $url ) {
			if ( str_contains( $url, $url_pattern ) ) {
				$this->fail( "Unexpected HTTP request matching '$url_pattern': $url" );
			}
		}
		$this->addToAssertionCount( 1 );
	}
}
```

**Step 2: Create fixtures directory**

Run: `mkdir -p tests/phpunit/fixtures/musicbrainz`

**Step 3: Commit**

```bash
git add tests/phpunit/ApiTestCase.php tests/phpunit/fixtures/
git commit -m "test: add ApiTestCase base class with HTTP mocking infrastructure"
```

### Task 2.1: MusicBrainz Fixtures + Test

**Files:**
- Create: `tests/phpunit/fixtures/musicbrainz/search-recording.json`
- Create: `tests/phpunit/fixtures/musicbrainz/get-recording.json`
- Create: `tests/phpunit/fixtures/musicbrainz/search-artist.json`
- Create: `tests/phpunit/unit/MusicBrainzApiTest.php`

**Step 1: Create fixtures**

`tests/phpunit/fixtures/musicbrainz/search-recording.json`:
```json
{
  "recordings": [
    {
      "id": "b10bbbfc-cf9e-42e0-be17-e2c3e1d2600d",
      "title": "Bohemian Rhapsody",
      "score": 100,
      "length": 354000,
      "artist-credit": [
        {
          "artist": {
            "id": "0383dadf-2a4e-4d10-a46a-e9e041da8eb3",
            "name": "Queen"
          }
        }
      ],
      "releases": [
        {
          "id": "bb2d72e5-83de-4e0b-8c3c-5e41e6b3e011",
          "title": "A Night at the Opera"
        }
      ]
    }
  ]
}
```

`tests/phpunit/fixtures/musicbrainz/get-recording.json`:
```json
{
  "id": "b10bbbfc-cf9e-42e0-be17-e2c3e1d2600d",
  "title": "Bohemian Rhapsody",
  "length": 354000,
  "artist-credit": [
    {
      "artist": {
        "id": "0383dadf-2a4e-4d10-a46a-e9e041da8eb3",
        "name": "Queen"
      }
    }
  ],
  "releases": [
    {
      "id": "bb2d72e5-83de-4e0b-8c3c-5e41e6b3e011",
      "title": "A Night at the Opera"
    }
  ]
}
```

`tests/phpunit/fixtures/musicbrainz/search-artist.json`:
```json
{
  "artists": [
    {
      "id": "0383dadf-2a4e-4d10-a46a-e9e041da8eb3",
      "name": "Queen",
      "sort-name": "Queen",
      "type": "Group",
      "country": "GB",
      "score": 100
    }
  ]
}
```

**Step 2: Write the test file**

```php
<?php
namespace PostKindsForIndieWeb\Tests\Unit;

use PostKindsForIndieWeb\Tests\ApiTestCase;
use PostKindsForIndieWeb\APIs\MusicBrainz;

class MusicBrainzApiTest extends ApiTestCase {

	private MusicBrainz $api;

	public function set_up(): void {
		parent::set_up();
		// Mock cover art to avoid cascading requests.
		$this->mock_http_response( 'coverartarchive.org', [], 404 );
		$this->api = new MusicBrainz();
	}

	public function test_search_returns_normalized_results() {
		$this->mock_http_response(
			'musicbrainz.org/ws/2/recording',
			'musicbrainz/search-recording.json'
		);

		$results = $this->api->search( 'Bohemian Rhapsody' );

		$this->assertNotEmpty( $results );
		$this->assertSame( 'Bohemian Rhapsody', $results[0]['track'] );
		$this->assertSame( 'Queen', $results[0]['artist'] );
		$this->assertSame( 'A Night at the Opera', $results[0]['album'] );
		$this->assertSame( 'musicbrainz', $results[0]['source'] );
		$this->assertSame( 354, $results[0]['duration'] );
	}

	public function test_search_with_artist_filter() {
		$this->mock_http_response(
			'musicbrainz.org/ws/2/recording',
			'musicbrainz/search-recording.json'
		);

		$results = $this->api->search( 'Bohemian Rhapsody', 'Queen' );

		$this->assert_api_request_made( 'musicbrainz.org/ws/2/recording' );
		$this->assertNotEmpty( $results );
	}

	public function test_search_empty_results() {
		$this->mock_http_response(
			'musicbrainz.org/ws/2/recording',
			[ 'recordings' => [] ]
		);

		$results = $this->api->search( 'nonexistent_track_xyz' );
		$this->assertEmpty( $results );
	}

	public function test_search_handles_api_error() {
		$this->mock_http_error( 'musicbrainz.org' );

		$results = $this->api->search( 'test' );
		$this->assertEmpty( $results );
	}

	public function test_get_by_id_returns_recording() {
		$this->mock_http_response(
			'musicbrainz.org/ws/2/recording/b10bbbfc',
			'musicbrainz/get-recording.json'
		);

		$result = $this->api->get_by_id( 'b10bbbfc-cf9e-42e0-be17-e2c3e1d2600d' );

		$this->assertNotNull( $result );
		$this->assertSame( 'Bohemian Rhapsody', $result['track'] );
		$this->assertSame( 'Queen', $result['artist'] );
	}

	public function test_get_by_id_returns_null_on_error() {
		$this->mock_http_error( 'musicbrainz.org' );

		$result = $this->api->get_by_id( 'invalid-id' );
		$this->assertNull( $result );
	}

	public function test_search_artist() {
		$this->mock_http_response(
			'musicbrainz.org/ws/2/artist',
			'musicbrainz/search-artist.json'
		);

		$results = $this->api->search_artist( 'Queen' );

		$this->assertNotEmpty( $results );
		$this->assertSame( 'Queen', $results[0]['name'] );
		$this->assertSame( 'Group', $results[0]['type'] );
		$this->assertSame( 'GB', $results[0]['country'] );
	}

	public function test_search_uses_cache() {
		$this->mock_http_response(
			'musicbrainz.org/ws/2/recording',
			'musicbrainz/search-recording.json'
		);

		// First call hits API.
		$this->api->search( 'Bohemian Rhapsody' );

		// Second call should use cache (no new HTTP request).
		// Remove mock to verify cache is used.
		remove_filter( 'pre_http_request', [ $this, 'intercept_http_request' ], 10 );
		$results = $this->api->search( 'Bohemian Rhapsody' );
		add_filter( 'pre_http_request', [ $this, 'intercept_http_request' ], 10, 3 );

		$this->assertNotEmpty( $results );
	}

	public function test_test_connection_success() {
		$this->mock_http_response(
			'musicbrainz.org/ws/2/artist/5b11f4ce',
			[ 'id' => '5b11f4ce', 'name' => 'Nirvana' ]
		);

		$this->assertTrue( $this->api->test_connection() );
	}

	public function test_test_connection_failure() {
		$this->mock_http_error( 'musicbrainz.org' );
		$this->assertFalse( $this->api->test_connection() );
	}

	public function test_docs_url() {
		$this->assertStringContainsString( 'musicbrainz.org', $this->api->get_docs_url() );
	}
}
```

**Step 3: Run and commit**

Run: `vendor/bin/phpunit --filter MusicBrainzApiTest`

```bash
git add tests/phpunit/fixtures/musicbrainz/ tests/phpunit/unit/MusicBrainzApiTest.php
git commit -m "test: add MusicBrainzApiTest with record-and-replay fixtures"
```

### Task 2.2-2.16: Remaining API Client Tests

For each API client, create:
1. Fixture files in `tests/phpunit/fixtures/<api_name>/`
2. Test file in `tests/phpunit/unit/<ApiName>ApiTest.php`

Follow the MusicBrainzApiTest pattern. Each test covers:
- `search()` with normal results
- `search()` with empty results
- `search()` handles errors gracefully
- `get_by_id()` returns normalized data
- `get_by_id()` returns null on error
- `test_connection()` success/failure
- Cache behavior
- API-specific methods

**API clients to test (one commit per client):**

| # | Class | Fixture dir | Key test focus |
|---|---|---|---|
| 2.2 | ListenBrainz | `listenbrainz/` | Token auth header, recent listens endpoint |
| 2.3 | Lastfm | `lastfm/` | API key in query params, track.search/artist.getInfo |
| 2.4 | TMDB | `tmdb/` | Bearer token, movie/tv search, poster URL construction |
| 2.5 | Trakt | `trakt/` | Client ID header, movie/show lookup |
| 2.6 | Simkl | `simkl/` | Client ID param, search/history |
| 2.7 | TVmaze | `tvmaze/` | No auth needed, show/episode lookup |
| 2.8 | OpenLibrary | `openlibrary/` | No auth, ISBN search, cover URL |
| 2.9 | GoogleBooks | `google-books/` | API key, volumes search |
| 2.10 | Hardcover | `hardcover/` | GraphQL query format, book search |
| 2.11 | PodcastIndex | `podcastindex/` | Auth hash header, podcast/episode search |
| 2.12 | Foursquare | `foursquare/` | API key header, place search |
| 2.13 | Nominatim | `nominatim/` | No auth, reverse geocoding |
| 2.14 | BoardGameGeek | `boardgamegeek/` | XML response parsing, game search |
| 2.15 | RAWG | `rawg/` | API key, game search |
| 2.16 | Readwise | `readwise/` | Token auth, highlights/books |

### Task 2.17: ApiBaseTest

**Files:**
- Create: `tests/phpunit/unit/ApiBaseTest.php`

Test API_Base through a concrete stub:

```php
<?php
namespace PostKindsForIndieWeb\Tests\Unit;

use PostKindsForIndieWeb\Tests\ApiTestCase;
use PostKindsForIndieWeb\APIs\API_Base;

class ConcreteApi extends API_Base {
	protected string $api_name = 'test_api';
	protected string $base_url = 'https://api.test.com/v1/';
	protected int $max_retries = 2;

	public function test_connection(): bool { return true; }
	public function search( string $query, ...$args ): array { return []; }
	public function get_by_id( string $id ): ?array { return null; }
	protected function normalize_result( array $raw ): array { return $raw; }

	// Expose protected methods for testing.
	public function public_build_url( string $ep, array $params = [] ): string {
		return $this->build_url( $ep, $params );
	}
	public function public_get_cache_key( string $key ): string {
		return $this->get_cache_key( $key );
	}
	public function public_get( string $ep, array $params = [] ): array {
		return $this->get( $ep, $params );
	}
	public function public_cached_get( string $ep, array $params = [] ): array {
		return $this->cached_get( $ep, $params );
	}
}

class ApiBaseTest extends ApiTestCase {

	private ConcreteApi $api;

	public function set_up(): void {
		parent::set_up();
		$this->api = new ConcreteApi();
	}

	public function test_build_url_with_endpoint() {
		$url = $this->api->public_build_url( 'search' );
		$this->assertSame( 'https://api.test.com/v1/search', $url );
	}

	public function test_build_url_with_params() {
		$url = $this->api->public_build_url( 'search', [ 'q' => 'test' ] );
		$this->assertStringContainsString( 'q=test', $url );
	}

	public function test_cache_key_format() {
		$key = $this->api->public_get_cache_key( 'my_query' );
		$this->assertStringStartsWith( 'post_kinds_test_api_', $key );
	}

	public function test_get_request_success() {
		$this->mock_http_response( 'api.test.com', [ 'result' => 'ok' ] );
		$data = $this->api->public_get( 'endpoint' );
		$this->assertSame( 'ok', $data['result'] );
	}

	public function test_get_request_throws_on_network_error() {
		$this->mock_http_error( 'api.test.com', 'Network timeout' );
		$this->expectException( \Exception::class );
		$this->api->public_get( 'endpoint' );
	}

	public function test_get_request_throws_on_4xx() {
		$this->mock_http_response( 'api.test.com', [ 'error' => 'Not found' ], 404 );
		$this->expectException( \Exception::class );
		$this->api->public_get( 'endpoint' );
	}

	public function test_cached_get_uses_transient() {
		$this->mock_http_response( 'api.test.com', [ 'fresh' => true ] );

		// First call hits API and caches.
		$data1 = $this->api->public_cached_get( 'endpoint' );
		$this->assertTrue( $data1['fresh'] );

		// Clear mock, second call should use cache.
		remove_filter( 'pre_http_request', [ $this, 'intercept_http_request' ], 10 );
		$data2 = $this->api->public_cached_get( 'endpoint' );
		add_filter( 'pre_http_request', [ $this, 'intercept_http_request' ], 10, 3 );

		$this->assertTrue( $data2['fresh'] );
	}

	public function test_is_configured_default_true() {
		$this->assertTrue( $this->api->is_configured() );
	}
}
```

Run: `vendor/bin/phpunit --filter ApiBaseTest`

```bash
git add tests/phpunit/unit/ApiBaseTest.php
git commit -m "test: add ApiBaseTest covering URL building, caching, error handling, retries"
```

---

## Layer 3: Sync Service Tests

### Task 3.1-3.8: Sync Service Tests

Follow the ApiTestCase pattern. Each sync test:
1. Extends `ApiTestCase`
2. Mocks the underlying API client HTTP calls
3. Tests post creation with correct kind and meta
4. Tests duplicate prevention
5. Tests error handling

| # | File | Class | Key tests |
|---|---|---|---|
| 3.1 | `CheckinSyncBaseTest.php` | `Checkin_Sync_Base` | Interface contract, common mapping |
| 3.2 | `ListenSyncBaseTest.php` | `Listen_Sync_Base` | Interface contract, common mapping |
| 3.3 | `WatchSyncBaseTest.php` | `Watch_Sync_Base` | Interface contract, common mapping |
| 3.4 | `FoursquareCheckinSyncTest.php` | `Foursquare_Checkin_Sync` | Venue data -> checkin post, geo meta |
| 3.5 | `UntappdCheckinSyncTest.php` | `Untappd_Checkin_Sync` | Beer data -> drink post, brewery meta |
| 3.6 | `OwnTracksCheckinSyncTest.php` | `OwnTracks_Checkin_Sync` | Location data -> checkin, reverse geocode |
| 3.7 | `LastfmListenSyncTest.php` | `Lastfm_Listen_Sync` | Recent tracks -> listen posts, mbid meta |
| 3.8 | `TraktWatchSyncTest.php` | `Trakt_Watch_Sync` | History -> watch posts, season/episode meta |

Template for each (use LastfmListenSyncTest as example):

```php
<?php
namespace PostKindsForIndieWeb\Tests\Unit;

use PostKindsForIndieWeb\Tests\ApiTestCase;
use PostKindsForIndieWeb\Sync\Lastfm_Listen_Sync;

class LastfmListenSyncTest extends ApiTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_insert_term( 'listen', 'kind' );
	}

	public function test_sync_creates_listen_post() {
		// Mock Last.fm API response
		$this->mock_http_response( 'ws.audioscrobbler.com', 'lastfm/recent-tracks.json' );

		$sync = new Lastfm_Listen_Sync();
		$result = $sync->sync();

		$this->assertGreaterThan( 0, $result['created'] );

		// Verify post has listen kind and correct meta.
		$posts = get_posts( [ 'post_type' => 'post', 'meta_key' => '_postkind_listen_track' ] );
		$this->assertNotEmpty( $posts );
	}

	public function test_sync_prevents_duplicates() {
		$this->mock_http_response( 'ws.audioscrobbler.com', 'lastfm/recent-tracks.json' );

		$sync = new Lastfm_Listen_Sync();
		$sync->sync(); // First run
		$result = $sync->sync(); // Second run

		$this->assertSame( 0, $result['created'] );
	}
}
```

Commit each with: `test: add <ClassName> covering sync, dedup, error handling`

---

## Layer 4: Admin Class Tests

### Task 4.1-4.9: Admin Tests

Each admin test follows this pattern:

```php
public function set_up(): void {
	parent::set_up();
	$this->user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
	wp_set_current_user( $this->user_id );
	set_current_screen( 'edit-post' );
}
```

| # | File | Class | Key tests |
|---|---|---|---|
| 4.1 | `AdminTest.php` | `Admin` | Hook registration, menu pages, enqueue, conflict notice |
| 4.2 | `SettingsPageTest.php` | `Settings_Page` | register_setting, sections, sanitization, nonce validation |
| 4.3 | `ApiSettingsTest.php` | `API_Settings` | Key storage/retrieval, connection test dispatching |
| 4.4 | `ImportPageTest.php` | `Import_Page` | Form rendering (ob_start/ob_get_clean), service selection |
| 4.5 | `QuickPostTest.php` | `Quick_Post` | Form fields per kind, post creation, redirect |
| 4.6 | `MetaBoxesTest.php` | `Meta_Boxes` | Registration per kind, save_post, nonce, autosave skip |
| 4.7 | `CheckinDashboardTest.php` | `Checkin_Dashboard` | Widget registration, recent checkins query |
| 4.8 | `SyndicationPageTest.php` | `Syndication_Page` | Target config, list rendering |
| 4.9 | `WebhooksPageTest.php` | `Webhooks_Page` | URL generation, token management, enable/disable |

Commit each with: `test: add <ClassName> covering hooks, rendering, validation`

---

## Layer 5: Jest Block Tests + Editor Store

### Task 5.0: Jest Test Setup Verification

Run: `npm run test:unit`
Expected: Existing StarRating test passes.

### Task 5.1: post-kinds-store.test.js

**Files:**
- Create: `tests/js/editor/post-kinds-store.test.js`

```js
import { createReduxStore, register, select, dispatch } from '@wordpress/data';

// The actual store module
jest.mock( '@wordpress/api-fetch', () => jest.fn() );

describe( 'Post Kinds Store', () => {
	beforeEach( () => {
		// Reset store state between tests.
	} );

	test( 'store is registered with correct name', () => {
		// Import registers the store.
		require( '@/editor/store' );
		const store = select( 'post-kinds-indieweb/post-kinds' );
		expect( store ).toBeDefined();
	} );

	test( 'getKind returns null by default', () => {
		const kind = select( 'post-kinds-indieweb/post-kinds' ).getKind();
		expect( kind ).toBeNull();
	} );

	test( 'setKind updates store state', () => {
		dispatch( 'post-kinds-indieweb/post-kinds' ).setKind( 'listen' );
		const kind = select( 'post-kinds-indieweb/post-kinds' ).getKind();
		expect( kind ).toBe( 'listen' );
	} );
} );
```

Run: `npm run test:unit -- --testPathPattern=post-kinds-store`

### Task 5.2: KindGrid.test.js

```js
import { render, screen, fireEvent } from '@testing-library/react';
import KindGrid from '@/editor/components/KindGrid';

describe( 'KindGrid', () => {
	const defaultProps = {
		selectedKind: null,
		onSelectKind: jest.fn(),
	};

	test( 'renders 24 kind options', () => {
		render( <KindGrid { ...defaultProps } /> );
		const buttons = screen.getAllByRole( 'button' );
		expect( buttons.length ).toBe( 24 );
	} );

	test( 'highlights selected kind', () => {
		render( <KindGrid { ...defaultProps } selectedKind="listen" /> );
		const listenBtn = screen.getByText( /listen/i );
		expect( listenBtn.closest( 'button' ) ).toHaveClass( 'is-selected' );
	} );

	test( 'calls onSelectKind when clicked', () => {
		const onSelect = jest.fn();
		render( <KindGrid { ...defaultProps } onSelectKind={ onSelect } /> );
		fireEvent.click( screen.getByText( /note/i ) );
		expect( onSelect ).toHaveBeenCalledWith( 'note' );
	} );
} );
```

### Task 5.3-5.22: Remaining Jest Tests

Follow the pattern from star-rating.test.js (inline simplified mocks, @testing-library/react).

**Block card tests** (`tests/js/blocks/`):

| # | File | Test focus |
|---|---|---|
| 5.3 | `listen-card.test.js` | Edit: artist, track, album, cover inputs. Save: markup with data attributes |
| 5.4 | `watch-card.test.js` | Edit: title, season, episode, status. Save: markup |
| 5.5 | `read-card.test.js` | Edit: title, author, ISBN, status, progress. Save: markup |
| 5.6 | `checkin-card.test.js` | Edit: venue, address, geo, photo. Save: markup |
| 5.7 | `rsvp-card.test.js` | Edit: event URL, status radios. Save: markup |
| 5.8 | `play-card.test.js` | Edit: game, platform, hours, status. Save: markup |
| 5.9 | `eat-card.test.js` | Edit: meal, restaurant, photo. Save: markup |
| 5.10 | `drink-card.test.js` | Edit: drink, brewery, type, rating. Save: markup |
| 5.11 | `favorite-card.test.js` | Edit + save |
| 5.12 | `jam-card.test.js` | Edit + save |
| 5.13 | `wish-card.test.js` | Edit + save |
| 5.14 | `mood-card.test.js` | Edit: mood label, rating slider. Save: markup |
| 5.15 | `acquisition-card.test.js` | Edit + save |
| 5.16 | `media-lookup.test.js` | Search, API fetch mock, result selection, loading/error |
| 5.17 | `checkin-dashboard.test.js` | Container render, SSR attributes |
| 5.18 | `checkins-feed.test.js` | Container render, attributes |
| 5.19 | `venue-detail.test.js` | Container render, attributes |

**Editor component tests** (`tests/js/editor/`):

| # | File | Test focus |
|---|---|---|
| 5.20 | `KindFields.test.js` | Correct field set per kind |
| 5.21 | `AutoDetectionNotice.test.js` | Show/hide on URL, kind suggestion |
| 5.22 | `SyndicationControls.test.js` | Target checkboxes, toggle state |

Each test: `npm run test:unit -- --testPathPattern=<test-name>`, commit with `test: add <TestName>`.

---

## Layer 6: Playwright E2E Expansion

### Task 6.1-6.9: E2E Tests

Each uses `wp-env`, `page.route()` for API mocking, admin auth.

| # | File | Flow |
|---|---|---|
| 6.1 | `kind-selection.spec.js` | Open editor, select kind from grid, save, verify term on reload |
| 6.2 | `block-insertion.spec.js` | Insert listen-card block, fill fields, save, verify front-end |
| 6.3 | `media-lookup.spec.js` | Open lookup, search (mocked), select result, verify fields populated |
| 6.4 | `import-flow.spec.js` | Navigate to import page, select service, configure, trigger (mocked) |
| 6.5 | `webhook-receipt.spec.js` | POST to webhook endpoint, verify post created |
| 6.6 | `settings-page.spec.js` | Change options, save, reload, verify persisted |
| 6.7 | `api-settings.spec.js` | Enter API key, save, verify masked display |
| 6.8 | `quick-post.spec.js` | Select kind, fill form, submit, verify post |
| 6.9 | `microformats-output.spec.js` | Create post with kind, verify mf2 classes in front-end HTML |

Commit each with: `test(e2e): add <test-name> flow`

---

## Layer 7: PHPUnit Integration Tests

### Task 7.1-7.8: Integration Tests

Create `tests/phpunit/integration/` directory, then add each file.

| # | File | Flow |
|---|---|---|
| 7.1 | `KindPostLifecycleTest.php` | Create post + kind + meta -> REST API GET -> verify round-trip |
| 7.2 | `WebhookToPostTest.php` | Full webhook -> auth -> parse -> scrobble -> post creation |
| 7.3 | `ImportPipelineTest.php` | Import_Manager + Scheduled_Sync -> cron trigger -> dedup |
| 7.4 | `SyncToPostTest.php` | Each sync service e2e with fixtures |
| 7.5 | `RestApiEndpointsTest.php` | Full-stack REST lookups with mocked APIs |
| 7.6 | `BlockRenderingTest.php` | Register blocks, create posts with meta, render_block(), verify HTML |
| 7.7 | `FeatureFlagIntegrationTest.php` | Toggle flags, verify abilities register/deregister |
| 7.8 | `PluginConflictTest.php` | Simulate original Post Kinds active, verify error notice |

Commit each with: `test(integration): add <TestName>`

---

## Execution Order Summary

| Phase | Tasks | Commits |
|---|---|---|
| Layer 1: Core unit tests | 1.1-1.9 | 9 |
| Layer 2: API infrastructure + clients | 2.0-2.17 | 18 |
| Layer 3: Sync services | 3.1-3.8 | 8 |
| Layer 4: Admin classes | 4.1-4.9 | 9 |
| Layer 5: Jest tests | 5.0-5.22 | 22 |
| Layer 6: Playwright E2E | 6.1-6.9 | 9 |
| Layer 7: Integration tests | 7.1-7.8 | 8 |
| **Total** | **83 tasks** | **83 commits** |
