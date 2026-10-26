<?php
/**
 * The weather single's microformats (issue 246).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Taxonomy;

/**
 * A weather single parses as one h-entry with one weather value, whichever
 * source holds the reading, and the hidden value never reaches a feed, a
 * REST response, an ATmosphere preview or a protected post.
 *
 * @group integration
 */
final class WeatherMicroformatsTest extends WP_UnitTestCase {

	private string $original_stylesheet = '';

	private int $author_id = 0;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		require_once dirname( __DIR__ ) . '/fixtures/simple-location/weather-stub.php';
		require_once dirname( __DIR__ ) . '/fixtures/simple-location/location-stub.php';
	}

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		( new Taxonomy() )->create_default_terms();
		update_option( 'sloc_measurements', 'metric' );
		update_option( 'geo_public', '0' );
		$this->author_id = self::factory()->user->create( [ 'display_name' => 'Weather Author' ] );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
		wp_reset_postdata();
		wp_set_current_user( 0 );
		if ( isset( $GLOBALS['wp_query'] ) ) {
			$GLOBALS['wp_query']->set( 'atproto', null );
		}
		switch_theme( $this->original_stylesheet );
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/**
	 * A weather post.
	 *
	 * @param string               $content    Post content.
	 * @param bool                 $observed   Whether Simple Location stored an observation.
	 * @param string               $geo_public Simple Location visibility, '' for none.
	 * @param array<string, mixed> $args       Extra post arguments.
	 */
	private function weather( string $content = '<!-- wp:paragraph --><p>Out for a walk.</p><!-- /wp:paragraph -->', bool $observed = true, string $geo_public = '1', array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => '',
					'post_author'  => $this->author_id,
					'post_content' => $content,
				],
				$args
			)
		);
		$this->assertNotWPError( wp_set_object_terms( $id, 'weather', Taxonomy::TAXONOMY ) );
		if ( $observed ) {
			add_post_meta( $id, 'weather_temperature', 26.8 );
			add_post_meta( $id, 'weather_code', 800 );
		}
		if ( '' !== $geo_public ) {
			add_post_meta( $id, 'geo_public', $geo_public );
		}

		return $id;
	}

	/**
	 * Post content holding Micropub's authored weather span.
	 *
	 * @param string $text Reading.
	 */
	private function authored( string $text ): string {
		return '<!-- wp:paragraph --><p><span class="p-weather">' . $text . '</span></p><!-- /wp:paragraph -->';
	}

	/**
	 * Render a single through the theme's block template, as served.
	 *
	 * @param int $post_id Post ID.
	 */
	private function render_single( int $post_id ): string {
		$this->go_to( (string) get_permalink( $post_id ) );
		$this->assertTrue( is_singular() );
		setup_postdata( get_post( $post_id ) );
		$template = resolve_block_template( 'single', [ 'single-post.php', 'single.php', 'singular.php', 'index.php' ], '' );
		$this->assertNotNull( $template );

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;

		return get_the_block_template_html();
	}

	/**
	 * The h-entry for a post in a parse of the page.
	 *
	 * @param string $html    Page HTML.
	 * @param int    $post_id Post ID.
	 * @return array<string, mixed>
	 */
	private function entry( string $html, int $post_id ): array {
		$url   = (string) get_permalink( $post_id );
		$found = [];
		$walk  = static function ( array $items ) use ( &$walk, &$found, $url ): void {
			foreach ( $items as $item ) {
				if ( in_array( 'h-entry', $item['type'] ?? [], true ) && in_array( $url, $item['properties']['url'] ?? [], true ) ) {
					$found[] = $item;
				}
				$walk( $item['children'] ?? [] );
			}
		};
		$walk( \Mf2\parse( $html, $url )['items'] ?? [] );
		$this->assertCount( 1, $found, 'One h-entry carries the post URL.' );

		return $found[0];
	}

	/**
	 * Run the_content for a post that is also the queried single.
	 *
	 * @param int $post_id Post ID.
	 */
	private function content_pass( int $post_id ): string {
		$this->go_to( (string) get_permalink( $post_id ) );
		$GLOBALS['post'] = get_post( $post_id );
		setup_postdata( $GLOBALS['post'] );

		return (string) apply_filters( 'the_content', get_post( $post_id )->post_content );
	}

	public function test_the_hidden_value_is_hooked_ahead_of_the_microformats_wrapper(): void {
		$this->assertSame( 98, has_filter( 'the_content', [ \PKIW\Integrations\Simple_Location_Weather::class, 'single_hidden_weather' ] ) );
	}

	public function test_an_observation_only_single_parses_one_weather_value(): void {
		$post_id = $this->weather();
		$html    = $this->render_single( $post_id );
		$entry   = $this->entry( $html, $post_id );

		$this->assertSame( [ 'Clear Sky, 27 °C' ], $entry['properties']['weather'] ?? null );
		$this->assertSame( 1, substr_count( $html, 'class="p-weather"' ) );
	}

	public function test_an_authored_only_single_keeps_the_authored_value_and_adds_no_data(): void {
		$post_id = $this->weather( $this->authored( 'Sunny and warm' ), false, '' );
		$html    = $this->render_single( $post_id );
		$entry   = $this->entry( $html, $post_id );

		$this->assertSame( [ 'Sunny and warm' ], $entry['properties']['weather'] ?? null );
		$this->assertStringNotContainsString( '<data class="p-weather"', $html );
	}

	public function test_a_single_with_both_sources_keeps_the_authored_value_only(): void {
		$post_id = $this->weather( $this->authored( 'Sunny and warm' ) );
		$html    = $this->render_single( $post_id );
		$entry   = $this->entry( $html, $post_id );

		$this->assertSame( [ 'Sunny and warm' ], $entry['properties']['weather'] ?? null );
		$this->assertStringNotContainsString( '<data class="p-weather"', $html );
	}

	public function test_the_data_element_is_hidden_and_inside_the_entry_content(): void {
		$post_id = $this->weather();

		$html = $this->content_pass( $post_id );

		$this->assertMatchesRegularExpression( '/<data class="p-weather" value="Clear Sky, 27 °C" hidden><\/data>/', $html );
		$this->assertStringContainsString( 'pkiw-singular-entry', $html );
		$this->assertLessThan( strpos( $html, '<data class="p-weather"' ), strpos( $html, 'pkiw-singular-entry' ), 'It lands inside the h-entry wrapper.' );
		$this->assertLessThan( strrpos( $html, '</div>' ), strpos( $html, '<data class="p-weather"' ) );
	}

	public function test_a_hidden_observation_adds_no_data_for_anonymous_or_an_editor_on_the_front_end(): void {
		$post_id = $this->weather( '<p>Out for a walk.</p>', true, '0' );

		$this->assertStringNotContainsString( '<data class="p-weather"', $this->content_pass( $post_id ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertStringNotContainsString( '<data class="p-weather"', $this->content_pass( $post_id ), 'The front end shows an editor what a visitor sees.' );
	}

	public function test_a_protected_single_adds_nothing(): void {
		$post_id = $this->weather( '<p>Out for a walk.</p>', true, '1', [ 'post_password' => 'secret' ] );

		$this->assertStringNotContainsString( '<data class="p-weather"', $this->content_pass( $post_id ) );
	}

	public function test_a_feed_pass_adds_no_data(): void {
		$post_id = $this->weather();
		$this->go_to( add_query_arg( [ 'p' => $post_id, 'feed' => 'rss2' ], home_url( '/' ) ) );
		$this->assertTrue( is_feed() );
		$this->assertTrue( is_singular() );
		$GLOBALS['post'] = get_post( $post_id );
		setup_postdata( $GLOBALS['post'] );

		$html = (string) apply_filters( 'the_content', get_post( $post_id )->post_content );

		$this->assertStringNotContainsString( '<data class="p-weather"', $html );
	}

	public function test_a_rest_pass_adds_no_data(): void {
		$post_id = $this->weather();

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringNotContainsString( '<data class="p-weather"', (string) $response->get_data()['content']['rendered'] );
	}

	public function test_an_atproto_preview_pass_adds_no_data(): void {
		$post_id = $this->weather();
		$this->go_to( (string) get_permalink( $post_id ) );
		$GLOBALS['post'] = get_post( $post_id );
		setup_postdata( $GLOBALS['post'] );
		set_query_var( 'atproto', '1' );

		$html = (string) apply_filters( 'the_content', get_post( $post_id )->post_content );

		$this->assertStringNotContainsString( '<data class="p-weather"', $html );
	}

	public function test_another_posts_content_pass_on_a_single_adds_no_data(): void {
		$post_id = $this->weather();
		$other   = $this->weather();
		$this->go_to( (string) get_permalink( $post_id ) );
		$GLOBALS['post'] = get_post( $other );
		setup_postdata( $GLOBALS['post'] );

		$html = (string) apply_filters( 'the_content', get_post( $other )->post_content );

		$this->assertStringNotContainsString( '<data class="p-weather"', $html, 'A related-posts loop on a single is not the entry.' );
	}

	public function test_a_note_single_adds_no_weather_data(): void {
		$note = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => '<p>Just a note.</p>',
			]
		);
		wp_set_object_terms( $note, 'note', Taxonomy::TAXONOMY );
		add_post_meta( $note, 'weather_temperature', 26.8 );
		add_post_meta( $note, 'geo_public', '1' );

		$this->assertStringNotContainsString( '<data class="p-weather"', $this->content_pass( $note ) );
	}

	public function test_no_simple_location_line_survives_on_the_single(): void {
		$content = '<!-- wp:paragraph --><p>Out for a walk.</p><!-- /wp:paragraph -->';
		$post_id = $this->weather( $content );
		add_post_meta( $post_id, 'geo_address', '100 Example Avenue' );
		add_post_meta( $post_id, 'geo_latitude', '10.1' );
		add_post_meta( $post_id, 'geo_longitude', '20.2' );
		add_filter( 'the_content', 'Geo_Data::location_content', 12 );

		try {
			$html = $this->content_pass( $post_id );
		} finally {
			remove_filter( 'the_content', 'Geo_Data::location_content', 12 );
		}

		$this->assertStringNotContainsString( 'sloc-display', $html );
		$this->assertStringNotContainsString( '100 Example Avenue', $html );
		$this->assertStringNotContainsString( 'sloc-weather', $html );
	}
}
