<?php
/**
 * Wish privacy boundaries across facts, archives, feeds, REST and Stream.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

use PKIW\Grouped_Archive;
use PKIW\Kind_Facts;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;
use PKIW\Wish_Archive;

/**
 * Keeps protected post data closed while preserving public price and reason.
 *
 * @since 1.9.0
 *
 * @group integration
 */
final class WishPrivacyTest extends WP_UnitTestCase {

	/**
	 * Stylesheet active before each test.
	 *
	 * @var string
	 */
	private string $original_stylesheet = '';

	/**
	 * Register taxonomy, meta and deterministic request settings.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'date_format', 'F j, Y' );
		add_filter( 'pre_http_request', '__return_empty_array' );
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
		wp_set_current_user( 0 );
	}

	/**
	 * Restore shared visitor and request state.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function tear_down(): void {
		wp_reset_postdata();
		wp_set_current_user( 0 );
		$this->set_permalink_structure( '' );
		switch_theme( $this->original_stylesheet );
		parent::tear_down();
	}

	/**
	 * Wish card comment.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, mixed> $attrs Card attributes.
	 * @return string Block comment.
	 */
	private function card( array $attrs ): string {
		return '<!-- wp:post-kinds-indieweb/wish-card ' . serialize_block_attributes( $attrs ) . ' /-->';
	}

	/**
	 * Create a wish and optionally bypass card sync with direct meta rows.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, mixed>  $args Post arguments.
	 * @param array<string, string> $meta Full meta keys and values.
	 * @return int Post ID.
	 */
	private function wish( array $args = [], array $meta = [] ): int {
		$post_id = self::factory()->post->create(
			array_merge(
				[
					'post_status' => 'publish',
					'post_title'  => 'Privacy wish',
					'post_date'   => '2026-07-05 10:00:00',
				],
				$args
			)
		);
		wp_set_object_terms( $post_id, 'wish', Taxonomy::TAXONOMY );
		foreach ( $meta as $key => $value ) {
			delete_post_meta( $post_id, $key );
			add_post_meta( $post_id, $key, $value );
		}

		return $post_id;
	}

	/**
	 * Render a post's wish card on its own single.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Post ID.
	 * @return string Card HTML.
	 */
	private function single( int $post_id ): string {
		$this->go_to( get_permalink( $post_id ) );
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $GLOBALS['post'] );
		$html = do_blocks( (string) get_post_field( 'post_content', $post_id ) );
		wp_reset_postdata();

		return $html;
	}

	/**
	 * Render a post's card content under a feed request.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Post ID.
	 * @return string Feed card HTML.
	 */
	private function feed_card( int $post_id ): string {
		$this->go_to( '/?feed=rss2' );
		$this->assertTrue( is_feed() );
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $GLOBALS['post'] );
		$html = apply_filters( 'the_content', (string) get_post_field( 'post_content', $post_id ) );
		wp_reset_postdata();

		return $html;
	}

	/**
	 * Render the real Stream card for one loop post.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Post ID.
	 * @return string Stream HTML.
	 */
	private function stream( int $post_id ): string {
		$this->go_to( home_url( '/' ) );
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $GLOBALS['post'] );
		$html = do_blocks( '<!-- wp:post-kinds-indieweb/stream-card /-->' );
		wp_reset_postdata();

		return $html;
	}

	/**
	 * Serve a small archive template through the real grouped query.
	 *
	 * @since 1.9.0
	 *
	 * @return string Archive HTML.
	 */
	private function archive(): string {
		$template = '<!-- wp:query {"queryId":238,"query":{"inherit":true}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-kinds-indieweb/archive-sections {"linesPerPage":20} /--><!-- wp:post-title {"level":3} /--><!-- /wp:post-template --></div><!-- /wp:query -->';
		$swap     = static function ( $templates ) use ( $template ) {
			foreach ( $templates as $found ) {
				if ( in_array( $found->slug, [ 'taxonomy-kind', 'taxonomy-kind-wish' ], true ) ) {
					$found->content = $template;
				}
			}
			return $templates;
		};
		add_filter( 'get_block_templates', $swap );
		$url = get_term_link( 'wish', Taxonomy::TAXONOMY );
		$this->assertIsString( $url );
		$this->go_to( $url );
		$html = do_blocks( $template );
		remove_filter( 'get_block_templates', $swap );

		return $html;
	}

	/**
	 * Protected wishes expose no facts and only a protected Stream title.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_protected_wish_has_no_facts_and_a_title_only_stream_card(): void {
		$post_id = $this->wish(
			[
				'post_title'    => 'Protected greenhouse',
				'post_password' => 'secret',
				'post_content'  => $this->card( [ 'title' => 'Hidden card name', 'price' => '$800', 'reason' => 'Hidden reason', 'image' => 'https://images.example/hidden.jpg' ] ),
			]
		);

		$this->assertSame( [], Wish_Archive::facts( $post_id ) );
		$this->assertSame( [], Kind_Facts::get( $post_id ) );
		$html = $this->stream( $post_id );
		$this->assertStringContainsString( 'pk-card--protected', $html );
		$this->assertStringContainsString( 'Protected: Protected greenhouse', $html );
		foreach ( [ 'Hidden card name', '$800', 'Hidden reason', 'images.example', 'pk-wish-chip' ] as $hidden ) {
			$this->assertStringNotContainsString( $hidden, $html );
		}
	}

	/**
	 * A protected type and priority become Other and medium in the archive.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_protected_archive_listing_uses_other_at_medium_rank(): void {
		$high = $this->wish( [ 'post_title' => 'Open high', 'post_date' => '2026-07-01 10:00:00' ], [ '_pkiw_wish_type' => 'other', '_pkiw_wish_priority' => 'high' ] );
		$protected = $this->wish(
			[ 'post_title' => 'Protected tech', 'post_date' => '2026-07-03 10:00:00', 'post_password' => 'secret' ],
			[ '_pkiw_wish_type' => 'tech', '_pkiw_wish_priority' => 'high' ]
		);
		$medium = $this->wish( [ 'post_title' => 'Open medium', 'post_date' => '2026-07-02 10:00:00' ], [ '_pkiw_wish_type' => 'other', '_pkiw_wish_priority' => 'medium' ] );

		$this->assertTrue( Grouped_Archive::group_of_post( 'wish', $protected )->is_empty() );
		$query = new WP_Query(
			[
				'post_status'       => 'publish',
				'posts_per_page'    => -1,
				'fields'            => 'ids',
				'pkiw_group_source' => Wish_Archive::SOURCE,
				'tax_query'         => [ [ 'taxonomy' => Taxonomy::TAXONOMY, 'field' => 'slug', 'terms' => 'wish' ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			]
		);
		$this->assertSame( [ $high, $protected, $medium ], array_map( 'intval', $query->posts ) );

		$html = $this->archive();
		$this->assertStringContainsString( '>Other</h2>', $html );
		$this->assertStringNotContainsString( '>Tech</h2>', $html );
		$this->assertMatchesRegularExpression( '/Open high.*Protected: Protected tech.*Open medium/s', $html );
	}

	/**
	 * Private and draft wishes stay out of visitor archives, feeds and REST.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_private_and_draft_wishes_are_invisible_to_logged_out_visitors(): void {
		$public  = $this->wish( [ 'post_title' => 'Public wish' ] );
		$private = $this->wish( [ 'post_title' => 'Private wish', 'post_status' => 'private' ] );
		$draft   = $this->wish( [ 'post_title' => 'Draft wish', 'post_status' => 'draft' ] );
		$query   = new WP_Query(
			[
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'tax_query'      => [ [ 'taxonomy' => Taxonomy::TAXONOMY, 'field' => 'slug', 'terms' => 'wish' ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			]
		);
		$this->assertContains( $public, array_map( 'intval', $query->posts ) );
		$this->assertNotContains( $private, array_map( 'intval', $query->posts ) );
		$this->assertNotContains( $draft, array_map( 'intval', $query->posts ) );

		$this->go_to( '/?feed=rss2' );
		$feed_ids = [];
		while ( have_posts() ) {
			the_post();
			$feed_ids[] = get_the_ID();
		}
		$this->assertContains( $public, $feed_ids );
		$this->assertNotContains( $private, $feed_ids );
		$this->assertNotContains( $draft, $feed_ids );

		foreach ( [ $private, $draft ] as $post_id ) {
			$GLOBALS['wp_rest_server'] = null;
			$response                  = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id ) );
			$this->assertNotSame( 200, $response->get_status() );
			$this->assertStringNotContainsString( (string) get_the_title( $post_id ), (string) wp_json_encode( $response->get_data() ) );
		}
	}

	/**
	 * Public facts contain no location fields and both reader entry points agree.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_public_fact_reader_matches_and_carries_no_location_key(): void {
		$post_id = $this->wish( [ 'post_content' => $this->card( [ 'title' => 'Public facts', 'price' => '$20' ] ) ] );
		$facts   = Wish_Archive::facts( $post_id );

		$this->assertSame( $facts, Kind_Facts::get( $post_id ) );
		foreach ( array_keys( $facts ) as $key ) {
			$this->assertDoesNotMatchRegularExpression( '/lat|lon|address|geo|location/i', $key );
		}
	}

	/**
	 * Feeds keep public rows and public detail but never single or Stream actions.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_public_feed_prints_heading_rows_price_and_reason_without_stream_chips(): void {
		$post_id = $this->wish(
			[
				'post_content' => $this->card( [ 'title' => 'Feed greenhouse', 'url' => 'https://shop.example/feed', 'price' => '$700', 'reason' => 'Public reason' ] ),
			]
		);
		$single = $this->single( $post_id );
		$this->assertStringContainsString( '$700', $single );
		$this->assertStringContainsString( 'Public reason', $single );

		$html = $this->feed_card( $post_id );

		$this->assertStringContainsString( '<h2 class="pk-title p-name">', $html );
		$this->assertStringContainsString( 'pk-wish-rows', $html );
		$this->assertStringContainsString( '$700', $html );
		$this->assertStringContainsString( 'Public reason', $html );
		$this->assertStringNotContainsString( 'View item', $html );
		$this->assertStringNotContainsString( 'pk-wish-chip', $html );
	}

	/**
	 * Core's feed password form contains none of the wish card body.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_protected_feed_content_is_only_the_core_password_form(): void {
		$post_id = $this->wish(
			[
				'post_title'    => 'Protected feed wish',
				'post_password' => 'secret',
				'post_content'  => $this->card( [ 'title' => 'Private card title', 'url' => 'https://shop.example/private', 'price' => '$999', 'reason' => 'Private reason' ] ),
			]
		);
		$this->go_to( '/?feed=rss2' );
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $GLOBALS['post'] );
		$html = get_the_content();
		wp_reset_postdata();

		$this->assertStringContainsString( 'post-password-form', $html );
		foreach ( [ 'Private card title', 'shop.example/private', '$999', 'Private reason' ] as $hidden ) {
			$this->assertStringNotContainsString( $hidden, $html );
		}
	}

	/**
	 * The item URL is an outbound single action and hidden Stream citation.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_item_url_is_view_item_on_single_and_hidden_data_on_stream(): void {
		$url     = 'https://shop.example/only-item';
		$post_id = $this->wish( [ 'post_content' => $this->card( [ 'title' => 'URL wish', 'url' => $url ] ) ] );
		$single  = $this->single( $post_id );
		$this->assertSame( 1, substr_count( $single, $url ) );
		$this->assertMatchesRegularExpression( '/<a class="u-url u-wish-of"[^>]+>View item<\/a>/', $single );

		$stream = $this->stream( $post_id );
		$this->assertSame( 1, substr_count( $stream, $url ) );
		$this->assertStringContainsString( '<data class="u-url u-wish-of" value="' . $url . '" hidden>', $stream );
		$this->assertStringNotContainsString( '<a class="u-url u-wish-of"', $stream );
	}

	/**
	 * Remote picture policy stays identical on single, feed and Stream surfaces.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_remote_images_suppress_referrers_everywhere_and_uploads_do_not(): void {
		$remote_id = $this->wish( [ 'post_content' => $this->card( [ 'title' => 'Remote image', 'image' => 'https://images.example/remote.jpg' ] ) ] );
		foreach ( [ $this->single( $remote_id ), $this->feed_card( $remote_id ), $this->stream( $remote_id ) ] as $html ) {
			$this->assertStringContainsString( 'referrerpolicy="no-referrer"', $html );
		}

		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$local_id      = $this->wish( [ 'post_content' => $this->card( [ 'title' => 'Local image', 'image' => wp_get_attachment_url( $attachment_id ) ] ) ] );
		foreach ( [ $this->single( $local_id ), $this->feed_card( $local_id ), $this->stream( $local_id ) ] as $html ) {
			$this->assertStringNotContainsString( 'referrerpolicy=', $html );
		}
	}
}
