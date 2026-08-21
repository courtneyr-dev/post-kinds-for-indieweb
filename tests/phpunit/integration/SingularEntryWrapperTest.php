<?php
/**
 * Entry-attachment coverage for singular permalinks.
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * Verifies a kind post's canonical property attaches to an h-entry at the
 * permalink — the URL webmention receivers actually fetch.
 *
 * MicroformatsRenderTest wraps do_blocks() output in an h-entry it injects
 * itself, so it can only prove property/root co-location on the card. These
 * tests render the real block template for a singular request (Twenty
 * Twenty-Five's single template does not call post_class(), so no theme
 * wrapper exists) and parse what a consumer would see.
 *
 * @group integration
 */
final class SingularEntryWrapperTest extends WP_UnitTestCase {

	/**
	 * Create a published like post carrying only a like card.
	 */
	private function make_like_post(): int {
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Entry wrapper probe',
				'post_content' => '<!-- wp:post-kinds-indieweb/like-card {"title":"Target","url":"https://example.com/target"} /-->',
			]
		);

		$term_result = wp_set_object_terms( $post_id, 'like', 'kind' );
		$this->assertNotWPError( $term_result );

		return $post_id;
	}

	/**
	 * Recursively search parsed mf2 items for an h-entry carrying a property.
	 *
	 * @param array<int, array<string, mixed>> $items    Parsed mf2 items.
	 * @param string                           $property Canonical property name.
	 * @return array<string, mixed>|null The matching h-entry, or null.
	 */
	private function find_entry_with_property( array $items, string $property ): ?array {
		foreach ( $items as $item ) {
			$types = $item['type'] ?? [];
			if ( in_array( 'h-entry', $types, true ) && isset( $item['properties'][ $property ] ) ) {
				return $item;
			}
			if ( ! empty( $item['children'] ) ) {
				$found = $this->find_entry_with_property( $item['children'], $property );
				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}

	/**
	 * Render the current request's block template exactly as template-canvas does.
	 */
	private function render_block_template(): string {
		$template = resolve_block_template(
			'single',
			[ 'single-post.php', 'single.php', 'singular.php', 'index.php' ],
			''
		);
		$this->assertNotNull( $template, 'No block template resolved for the singular request.' );

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;

		return get_the_block_template_html();
	}

	/**
	 * The full Twenty Twenty-Five single template attaches like-of to an h-entry.
	 */
	public function test_singular_template_attaches_property_to_h_entry(): void {
		switch_theme( 'twentytwentyfive' );
		$post_id = $this->make_like_post();

		$this->go_to( get_permalink( $post_id ) );
		$this->assertTrue( is_singular(), 'Request did not resolve as singular.' );

		$html  = $this->render_block_template();
		$entry = $this->find_entry_with_property( \Mf2\parse( $html )['items'] ?? [], 'like-of' );

		$this->assertNotNull(
			$entry,
			'like-of did not attach to any h-entry in the rendered singular template.'
		);
		$this->assertPropertyContainsTarget(
			$entry['properties']['like-of'],
			'https://example.com/target'
		);
	}

	/**
	 * The content filter alone produces a complete h-entry when no theme wrapper exists.
	 */
	public function test_content_filter_wraps_orphan_singular_content(): void {
		$post_id = $this->make_like_post();

		$this->go_to( get_permalink( $post_id ) );
		$this->assertTrue( is_singular() );

		$content = apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) );
		$parsed  = \Mf2\parse( $content, get_permalink( $post_id ) );
		$entry   = $this->find_entry_with_property( $parsed['items'] ?? [], 'like-of' );

		$this->assertNotNull( $entry, 'Filtered content did not carry an h-entry with like-of.' );
		$this->assertContains(
			get_permalink( $post_id ),
			$entry['properties']['url'] ?? [],
			'The injected h-entry does not declare the permalink as u-url.'
		);
	}

	/**
	 * A theme that wraps the post via post_class() must not get a second h-entry.
	 */
	public function test_no_double_wrap_when_theme_applies_post_class(): void {
		$post_id = $this->make_like_post();

		$this->go_to( get_permalink( $post_id ) );

		// A post_class()-based wrapper renders before its inner content, so the
		// filter fires first — exactly what core/post-template does per item.
		get_post_class( '', $post_id );

		$content = apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) );

		$this->assertStringNotContainsString(
			'pkiw-singular-entry',
			$content,
			'Content was wrapped even though the theme already applied post_class().'
		);
	}

	/**
	 * Kinds without a kind_formats entry still get the default h-entry root —
	 * their cards emit properties too (the favorite card's u-favorite-of made
	 * this gap visible in the 1.5.0 pre-release gate).
	 */
	public function test_formatless_kind_content_is_wrapped(): void {
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Formatless kind probe',
				'post_content' => '<!-- wp:post-kinds-indieweb/favorite-card {"title":"Target","url":"https://example.com/fav"} /-->',
			]
		);
		wp_set_object_terms( $post_id, 'favorite', 'kind' );

		$this->go_to( get_permalink( $post_id ) );
		$content = apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) );
		$entry   = $this->find_entry_with_property( \Mf2\parse( $content, get_permalink( $post_id ) )['items'] ?? [], 'favorite-of' );

		$this->assertNotNull( $entry, 'favorite-of did not attach to an h-entry for a kind with no format entry.' );
	}

	/**
	 * Posts without a kind keep their content untouched.
	 */
	public function test_kindless_post_content_is_untouched(): void {
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>Plain post.</p><!-- /wp:paragraph -->',
			]
		);

		$this->go_to( get_permalink( $post_id ) );
		$content = apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) );

		$this->assertStringNotContainsString( 'pkiw-singular-entry', $content );
	}

	/**
	 * Non-singular contexts are never wrapped.
	 */
	public function test_archive_context_is_never_wrapped(): void {
		$post_id = $this->make_like_post();

		$this->go_to( home_url( '/' ) );
		$this->assertFalse( is_singular() );

		$content = apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) );

		$this->assertStringNotContainsString( 'pkiw-singular-entry', $content );
	}

	/**
	 * Assert a parsed property contains a target URL, as a plain value or
	 * inside an embedded microformat (a u- property on an h-cite parses to
	 * an embedded object whose url property carries the target).
	 *
	 * @param array<int, mixed> $values     Parsed property values.
	 * @param string            $target_url Expected URL.
	 */
	private function assertPropertyContainsTarget( array $values, string $target_url ): void {
		foreach ( $values as $value ) {
			if ( $target_url === $value ) {
				$this->addToAssertionCount( 1 );
				return;
			}

			if ( ! is_array( $value ) ) {
				continue;
			}

			if ( $target_url === ( $value['value'] ?? null ) ) {
				$this->addToAssertionCount( 1 );
				return;
			}

			foreach ( $value['properties']['url'] ?? [] as $url ) {
				if ( $target_url === $url ) {
					$this->addToAssertionCount( 1 );
					return;
				}
			}
		}

		$this->fail( 'No parsed value carries ' . $target_url );
	}
}
