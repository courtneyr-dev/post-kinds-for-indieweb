<?php
/**
 * Drawn comic privacy boundaries (issue 266).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Abilities\Core_Abilities;
use PKIW\Comic_Strip;
use PKIW\Kind_Facts;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class ComicStripPrivacyTest extends WP_UnitTestCase {

	private string $original_stylesheet = '';

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		( new Meta_Fields() )->register_meta_fields();
		( new Taxonomy() )->create_default_terms();
		( new Comic_Strip() )->register();
		Comic_Strip::reset_series_cache();
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
		wp_set_current_user( 0 );
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
	}

	public function tear_down(): void {
		switch_theme( $this->original_stylesheet );
		$this->set_permalink_structure( '' );
		wp_set_current_user( 0 );
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
		wp_reset_postdata();
		parent::tear_down();
	}

	private function gallery( int $attachment_id, string $caption ): string {
		return '<!-- wp:gallery --><figure class="wp-block-gallery has-nested-images"><!-- wp:image {"id":' . $attachment_id . '} --><figure class="wp-block-image"><img src="https://example.org/' . $attachment_id . '.jpg" alt="Panel" /><figcaption class="wp-element-caption">' . esc_html( $caption ) . '</figcaption></figure><!-- /wp:image --></figure><!-- /wp:gallery -->';
	}

	/**
	 * Create a strip with privacy-sensitive metadata and caption content.
	 *
	 * @param string               $series  Series value.
	 * @param string               $caption Caption sentinel.
	 * @param array<string, mixed> $args    Post overrides.
	 */
	private function strip( string $series, string $caption, array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => $series,
					'post_content' => $this->gallery( self::factory()->attachment->create(), $caption ),
				],
				$args
			)
		);
		wp_set_object_terms( $id, Comic_Strip::KIND, Taxonomy::TAXONOMY );
		update_post_meta( $id, Comic_Strip::SERIES_META, $series );
		update_post_meta( $id, Comic_Strip::ISSUE_META, '9' );
		Comic_Strip::reset_series_cache();

		return (int) $id;
	}

	private function drawn_url(): string {
		return add_query_arg(
			Comic_Strip::VIEW_VAR,
			Comic_Strip::VIEW_DRAWN,
			(string) get_term_link( Comic_Strip::KIND, Taxonomy::TAXONOMY )
		);
	}

	private function render_feed( string $url ): string {
		$this->go_to( $url );
		ob_start();
		try {
			@require ABSPATH . WPINC . '/feed-rss2.php'; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Core's test template emits headers.
		} finally {
			$output = (string) ob_get_clean();
		}

		return $output;
	}

	/**
	 * REST post response data.
	 *
	 * @return array<string, mixed>
	 */
	private function rest_post( int $post_id ): array {
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id );
		$request->set_param( 'context', 'view' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );

		return (array) $response->get_data();
	}

	public function test_series_index_excludes_password_private_draft_card_and_status_rows(): void {
		$this->strip( 'Public Series', 'Public caption' );
		$this->strip( 'Secret Series', 'Secret caption', [ 'post_password' => 'secret' ] );
		$this->strip( 'Private Series', 'Private caption', [ 'post_status' => 'private' ] );
		$this->strip( 'Draft Series', 'Draft caption', [ 'post_status' => 'draft' ] );
		$status = $this->strip( 'Former Card Series', 'Former caption' );
		update_post_meta( $status, Comic_Strip::STATUS_META, 'finished' );
		$card = $this->strip( 'Card Series', 'Card caption' );
		wp_update_post( [ 'ID' => $card, 'post_content' => '<!-- wp:post-kinds-indieweb/comic-card /-->' ] );
		Comic_Strip::reset_series_cache();

		$this->assertSame( [ 'Public Series' ], Comic_Strip::series_values() );
	}

	public function test_direct_and_registered_facts_hide_every_unreadable_post(): void {
		$locked = $this->strip( 'Secret Series', 'Secret caption', [ 'post_password' => 'secret' ] );
		$private = $this->strip( 'Private Series', 'Private caption', [ 'post_status' => 'private' ] );
		$draft = $this->strip( 'Draft Series', 'Draft caption', [ 'post_status' => 'draft' ] );

		foreach ( [ $locked, $private, $draft ] as $post_id ) {
			$this->assertSame( [], Comic_Strip::facts( $post_id ) );
			$this->assertSame( [], Kind_Facts::get( $post_id ) );
		}
	}

	public function test_rest_and_get_post_meta_ability_redact_protected_comic_metadata(): void {
		$public = $this->strip( 'Public Series', 'Public caption' );
		$locked = $this->strip( 'Secret Series', 'Secret caption', [ 'post_password' => 'secret' ] );
		$GLOBALS['wp_rest_server'] = null;
		wp_set_current_user( 0 );

		$public_data = $this->rest_post( $public );
		$this->assertSame( 'Public Series', $public_data['meta'][ Comic_Strip::SERIES_META ] );
		$this->assertSame( '9', $public_data['meta'][ Comic_Strip::ISSUE_META ] );

		$locked_data = $this->rest_post( $locked );
		$this->assertArrayNotHasKey( Comic_Strip::SERIES_META, $locked_data['meta'] ?? [] );
		$this->assertArrayNotHasKey( Comic_Strip::ISSUE_META, $locked_data['meta'] ?? [] );
		$this->assertStringNotContainsString( 'Secret Series', (string) wp_json_encode( $locked_data['meta'] ?? [] ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$result = Core_Abilities::instance()->execute_get_post_meta(
			[
				'post_id'  => $locked,
				'meta_keys' => [ 'comic_series', 'comic_issue', 'comic_status', 'comic_title' ],
			]
		);
		$this->assertIsArray( $result );
		$this->assertSame( [], $result['meta'] );
	}

	public function test_editor_can_read_stored_meta_but_still_needs_password_for_facts(): void {
		$locked = $this->strip( 'Secret Series', 'Secret caption', [ 'post_password' => 'secret' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$data = $this->rest_post( $locked );
		$this->assertSame( 'Secret Series', $data['meta'][ Comic_Strip::SERIES_META ] );
		$result = Core_Abilities::instance()->execute_get_post_meta( [ 'post_id' => $locked, 'meta_keys' => [ 'comic_series', 'comic_issue' ] ] );
		$this->assertSame( 'Secret Series', $result['meta']['comic_series'] );
		$this->assertSame( [], Comic_Strip::facts( $locked ) );
	}

	public function test_drawn_feed_contains_public_caption_and_excludes_protected_caption(): void {
		$this->strip( 'Public Series', 'Public transcript sentinel' );
		$this->strip( 'Secret Series', 'Secret transcript sentinel', [ 'post_password' => 'secret' ] );
		$term = get_term_by( 'slug', Comic_Strip::KIND, Taxonomy::TAXONOMY );
		$this->assertInstanceOf( WP_Term::class, $term );
		$feed_url = add_query_arg( Comic_Strip::VIEW_VAR, Comic_Strip::VIEW_DRAWN, get_term_feed_link( $term->term_id, Taxonomy::TAXONOMY ) );
		$feed = $this->render_feed( $feed_url );
		$this->assertTrue( is_feed() );
		$this->assertStringContainsString( 'Public transcript sentinel', $feed );
		$this->assertStringNotContainsString( 'Secret transcript sentinel', $feed );
	}

	public function test_protected_single_uses_core_password_form_without_caption(): void {
		$locked = $this->strip( 'Secret Series', 'Secret transcript sentinel', [ 'post_password' => 'secret' ] );
		$this->go_to( get_permalink( $locked ) );
		$GLOBALS['post'] = get_post( $locked );
		setup_postdata( $GLOBALS['post'] );
		$html = apply_filters( 'the_content', get_the_content( null, false, $GLOBALS['post'] ) );

		$this->assertStringContainsString( 'post-password-form', $html );
		$this->assertStringNotContainsString( 'Secret transcript sentinel', $html );
	}

	public function test_a_whitespace_only_password_keeps_a_strip_out_of_the_view_nav_index_and_feed(): void {
		$this->strip( 'Public Series', 'Public caption' );
		$id = $this->strip( 'Unannounced Project', 'Whitespace caption sentinel', [ 'post_password' => '   ' ] );
		$this->assertSame( '   ', get_post( $id )->post_password );
		$this->assertTrue( post_password_required( $id ) );

		$this->assertSame( [ 'Public Series' ], Comic_Strip::series_values() );

		$this->go_to( $this->drawn_url() );
		$this->assertNotContains( $id, array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) ) );
		$nav = render_block(
			[
				'blockName'    => Comic_Strip::SERIES_BLOCK,
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
		$this->assertStringContainsString( 'Public Series', $nav );
		$this->assertStringNotContainsString( 'Unannounced Project', $nav );

		$term = get_term_by( 'slug', Comic_Strip::KIND, Taxonomy::TAXONOMY );
		$feed = $this->render_feed( add_query_arg( Comic_Strip::VIEW_VAR, Comic_Strip::VIEW_DRAWN, get_term_feed_link( $term->term_id, Taxonomy::TAXONOMY ) ) );
		$this->assertStringNotContainsString( 'Whitespace caption sentinel', $feed );
	}

	public function test_a_card_post_with_no_stored_status_is_absent_from_view_feed_nav_index_and_rest_clause(): void {
		$this->strip( 'Public Series', 'Public caption' );
		$card = $this->strip( 'Card Series', 'Card caption' );
		wp_update_post(
			[
				'ID'           => $card,
				'post_content' => '<!-- wp:post-kinds-indieweb/comic-card {"title":"Card sentinel"} /-->',
			]
		);
		delete_post_meta( $card, Comic_Strip::STATUS_META );
		update_post_meta( $card, Comic_Strip::SERIES_META, 'Card Series' );
		Comic_Strip::reset_series_cache();
		$this->assertFalse( Comic_Strip::is_drawn( get_post( $card ) ) );

		$this->assertSame( [ 'Public Series' ], Comic_Strip::series_values() );

		$this->go_to( $this->drawn_url() );
		$this->assertNotContains( $card, array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) ) );
		$this->assertSame( 1, (int) $GLOBALS['wp_query']->found_posts );
		$nav = render_block(
			[
				'blockName'    => Comic_Strip::SERIES_BLOCK,
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
		$this->assertStringNotContainsString( 'Card Series', $nav );

		$term = get_term_by( 'slug', Comic_Strip::KIND, Taxonomy::TAXONOMY );
		$feed = $this->render_feed( add_query_arg( Comic_Strip::VIEW_VAR, Comic_Strip::VIEW_DRAWN, get_term_feed_link( $term->term_id, Taxonomy::TAXONOMY ) ) );
		$this->assertStringNotContainsString( 'Card sentinel', $feed );
		$this->assertStringContainsString( 'Public caption', $feed );

		// The REST and editor preview route builds its own query from the view's clause.
		$ids = get_posts(
			[
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'fields'           => 'ids',
				'posts_per_page'   => -1,
				'suppress_filters' => false,
				'tax_query'        => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Test fixture query.
					[
						'taxonomy' => Taxonomy::TAXONOMY,
						'field'    => 'slug',
						'terms'    => Comic_Strip::KIND,
					],
				],
				'meta_query'       => [ \PKIW\Kind_Archive_Layouts::filtered_view_meta_query( Comic_Strip::KIND, Comic_Strip::VIEW_DRAWN ) ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Test fixture query.
			]
		);
		$this->assertNotContains( $card, array_map( 'intval', $ids ) );
		$this->assertCount( 1, $ids );
	}
}
