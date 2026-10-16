<?php
/**
 * What the sleep card lets out of the site (issue 247).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Meta_Fields;
use PKIW\Micropub_Content_Builder;
use PKIW\Taxonomy;

/**
 * Hidden bedtime and wake time must be absent from every surface that builds
 * from the card's render: REST, the feeds, oEmbed, the embed excerpt and the
 * microformats parse. A post that opts in prints them in the record only,
 * never with an mf2 class. Rows the Micropub plugin keeps outside Post Kinds
 * (mf2_sleep, mf2_start, mf2_end) print nowhere.
 *
 * @group integration
 */
final class SleepRenderPrivacyTest extends WP_UnitTestCase {

	private const BLOCK = 'post-kinds-indieweb/sleep-card';

	private string $original_timezone = '';

	private bool $registered_here = false;

	public function set_up(): void {
		parent::set_up();
		$this->original_timezone = (string) get_option( 'timezone_string' );
		update_option( 'timezone_string', 'America/New_York' );
		( new Meta_Fields() )->register_meta_fields();
		foreach ( [ 'sleep', 'note' ] as $slug ) {
			if ( ! term_exists( $slug, 'kind' ) ) {
				wp_insert_term( ucfirst( $slug ), 'kind', [ 'slug' => $slug ] );
			}
		}
		wp_set_current_user( 0 );
		$this->set_permalink_structure( '/%year%/%monthnum%/%day%/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );

		$this->registered_here = ! WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK );
		if ( $this->registered_here ) {
			register_block_type(
				self::BLOCK,
				[
					'api_version'     => 3,
					'attributes'      => [
						'durationMinutes'   => [ 'type' => 'integer' ],
						'bedtime'           => [ 'type' => 'string' ],
						'wakeTime'          => [ 'type' => 'string' ],
						'showTimesPublicly' => [
							'type'    => 'boolean',
							'default' => false,
						],
						'origin'            => [ 'type' => 'string' ],
					],
					'uses_context'    => [ 'postId' ],
					'render_callback' => static function ( array $attributes, string $content, WP_Block $block ): string {
						ob_start();
						include PKIW_PATH . 'src/blocks/sleep-card/render.php';
						return (string) ob_get_clean();
					},
				]
			);
		}
	}

	public function tear_down(): void {
		if ( $this->registered_here ) {
			unregister_block_type( self::BLOCK );
		}
		update_option( 'timezone_string', $this->original_timezone );
		$this->set_permalink_structure( '' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * @param array<string, mixed> $attrs Card attributes.
	 */
	private function card( array $attrs ): string {
		return '<!-- wp:post-kinds-indieweb/sleep-card ' . serialize_block_attributes( $attrs ) . ' /-->';
	}

	/**
	 * @param array<string, mixed> $attrs Card attributes.
	 * @param array<string, mixed> $post  Post overrides.
	 */
	private function sleep( string $night, array $attrs, array $post = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Night',
					'post_excerpt' => 'Same excerpt',
					'post_name'    => 'night-' . wp_generate_password( 6, false ),
					'post_date'    => $night . ' 12:00:00',
					'post_content' => $this->card( $attrs ),
				],
				$post
			)
		);
		wp_set_object_terms( $id, 'sleep', 'kind' );

		return (int) $id;
	}

	/**
	 * The hidden-times and never-stored pair on one date.
	 *
	 * @return array{0: int, 1: int}
	 */
	private function h01_and_n01(): array {
		return [
			$this->sleep(
				'2026-08-09',
				[
					'durationMinutes' => 420,
					'bedtime'         => '2026-08-09T23:10',
					'wakeTime'        => '2026-08-10T06:10',
				]
			),
			$this->sleep( '2026-08-09', [ 'durationMinutes' => 420 ] ),
		];
	}

	/**
	 * A logged-out REST GET of a post.
	 *
	 * @return array<string, mixed>
	 */
	private function rest( int $id, string $query = '' ): array {
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $id );
		foreach ( wp_parse_args( $query ) as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );

		return (array) $response->get_data();
	}

	public function test_rest_content_holds_the_duration_and_no_hidden_time_and_h01_equals_n01(): void {
		[ $h01, $n01 ] = $this->h01_and_n01();
		$hidden        = $this->rest( $h01 );
		$never         = $this->rest( $n01 );

		$this->assertStringContainsString( '7 hr', $hidden['content']['rendered'] );
		foreach ( [ '23:10', '06:10', '11:10 pm', '6:10 am' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, wp_json_encode( $hidden ), $needle );
		}

		$normalize = static function ( array $data ): array {
			foreach ( [ 'id', 'guid', 'link', 'slug' ] as $key ) {
				unset( $data[ $key ] );
			}
			unset( $data['_links'], $data['class_list'] );

			return $data;
		};
		$this->assertSame( $normalize( $never ), $normalize( $hidden ), 'Hidden times answer like a card that never stored them.' );
	}

	public function test_rest_date_is_noon_of_the_night_and_the_response_names_no_place(): void {
		[ $h01 ] = $this->h01_and_n01();
		$data    = $this->rest( $h01 );

		$this->assertSame( '2026-08-09T12:00:00', $data['date'] );
		$this->assertSame( '2026-08-09T16:00:00', $data['date_gmt'] );
		$this->assertSame( '2026-08-09T12:00:00', $data['modified'], 'Modified stays at noon after the first publish.' );
		$this->assertStringNotContainsString( 'sleep_bedtime', (string) wp_json_encode( $data ) );
		$this->assertStringNotContainsString( 'sleep_wake', (string) wp_json_encode( $data ) );
		$this->assertStringNotContainsString( 'showTimesPublicly', (string) wp_json_encode( $data ) );
	}

	public function test_an_opted_in_post_prints_its_times_in_rest_content_and_nowhere_else_in_the_body(): void {
		$id   = $this->sleep(
			'2026-08-11',
			[
				'durationMinutes'   => 510,
				'bedtime'           => '2026-08-11T21:45',
				'wakeTime'          => '2026-08-12T06:15',
				'showTimesPublicly' => true,
			]
		);
		$data = $this->rest( $id );

		$this->assertStringContainsString( '2026-08-11T21:45', $data['content']['rendered'] );
		$this->assertStringNotContainsString( '21:45', (string) wp_json_encode( [ $data['excerpt'], $data['title'], $data['date'], $data['modified'] ] ) );
	}

	public function test_a_logged_out_rest_search_cannot_find_a_hidden_time(): void {
		[ $h01 ] = $this->h01_and_n01();
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$request->set_param( 'search', '23:10' );
		$found = wp_list_pluck( rest_do_request( $request )->get_data(), 'id' );

		$this->assertNotContains( $h01, $found );
	}

	public function test_the_feed_item_holds_no_hidden_time_and_an_opted_in_item_holds_its_times(): void {
		[ $h01 ] = $this->h01_and_n01();
		$t01     = $this->sleep(
			'2026-08-11',
			[
				'durationMinutes'   => 510,
				'bedtime'           => '2026-08-11T21:45',
				'wakeTime'          => '2026-08-12T06:15',
				'showTimesPublicly' => true,
			],
			[ 'post_title' => 'Early night' ]
		);

		$this->assertStringNotContainsString( '23:10', $this->feed_content( $h01 ) );
		$this->assertStringNotContainsString( '06:10', $this->feed_content( $h01 ) );
		$this->assertStringContainsString( '7 hr', $this->feed_content( $h01 ) );
		$this->assertStringContainsString( '21:45', $this->feed_content( $t01 ) );
	}

	private function feed_content( int $id ): string {
		$GLOBALS['post'] = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test setup.
		setup_postdata( $GLOBALS['post'] );
		$content = (string) get_the_content_feed( 'rss2' );
		wp_reset_postdata();

		return $content;
	}

	public function test_the_whole_rss_feed_of_the_kind_holds_no_hidden_time_and_orders_by_date_descending(): void {
		[ $h01 ] = $this->h01_and_n01();
		$later   = $this->sleep( '2026-08-12', [ 'durationMinutes' => 400 ] );

		$this->go_to( home_url( '/kind/sleep/feed/' ) );
		$this->assertTrue( is_feed() );
		$xml = $this->render_feed();

		$this->assertStringNotContainsString( '23:10', $xml );
		$this->assertStringNotContainsString( '06:10', $xml );
		$this->assertStringContainsString( '<pubDate>Wed, 12 Aug 2026 16:00:00 +0000</pubDate>', $xml, 'pubDate is noon in New York.' );
		$this->assertStringContainsString( '<pubDate>Sun, 09 Aug 2026 16:00:00 +0000</pubDate>', $xml );
		$this->assertLessThan( strpos( $xml, get_permalink( $h01 ) ), strpos( $xml, get_permalink( $later ) ), 'Newest night first.' );
	}

	private function render_feed(): string {
		ob_start();
		try {
			// The template sends headers after PHPUnit's output, as core's feed tests do.
			@require ABSPATH . WPINC . '/feed-rss2.php'; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		} finally {
			$out = (string) ob_get_clean();
		}

		return $out;
	}

	public function test_oembed_and_the_embed_excerpt_hold_no_hidden_time(): void {
		[ $h01 ] = $this->h01_and_n01();
		$post    = get_post( $h01 );

		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test setup.
		setup_postdata( $post );
		$excerpt = (string) apply_filters( 'the_excerpt_embed', get_the_excerpt( $post ) );
		wp_reset_postdata();
		$oembed = (array) get_oembed_response_data( $post, 600 );

		foreach ( [ '23:10', '06:10' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $excerpt );
			$this->assertStringNotContainsString( $needle, (string) wp_json_encode( $oembed ) );
		}
		$this->assertStringNotContainsString( 'Moon phase', $excerpt, 'The dynamic card is left out of an auto-excerpt.' );
	}

	public function test_micropub_plugin_rows_print_through_no_surface(): void {
		$id = $this->sleep( '2026-08-12', [ 'durationMinutes' => 425, 'origin' => 'micropub' ] );
		update_post_meta( $id, 'mf2_sleep', [ '7h 05m, restless after 3am' ] );
		update_post_meta( $id, 'mf2_start', [ '2026-08-12T23:40:00-04:00' ] );
		update_post_meta( $id, 'mf2_end', [ '2026-08-13T06:45:00-04:00' ] );

		$surfaces = [
			wp_json_encode( $this->rest( $id ) ),
			$this->feed_content( $id ),
		];
		$this->go_to( get_permalink( $id ) );
		$surfaces[] = apply_filters( 'the_content', get_post( $id )->post_content );

		foreach ( $surfaces as $surface ) {
			foreach ( [ '23:40', '06:45', '07:02', 'restless' ] as $needle ) {
				$this->assertStringNotContainsString( $needle, (string) $surface, $needle );
			}
			$this->assertStringContainsString( '7 hr 5 min', (string) $surface );
		}
	}

	public function test_a_micropub_sleep_answer_prints_its_text_as_written_and_the_card_prints_the_parsed_duration(): void {
		$markup = Micropub_Content_Builder::fill_empty_content(
			'',
			[
				'properties' => [
					'sleep'     => [ '7h 05m, restless after 3am' ],
					'start'     => [ '2026-08-12T23:40:00-04:00' ],
					'end'       => [ '2026-08-13T06:45:00-04:00' ],
					'published' => [ '2026-08-13T07:02:00-04:00' ],
				],
			]
		);
		$id     = $this->sleep( '2026-08-12', [], [ 'post_content' => $markup ] );
		$data   = $this->rest( $id );
		$body   = $data['content']['rendered'];

		$this->assertStringContainsString( '<time datetime="PT7H5M">7 hr 5 min</time>', $body );
		$this->assertStringContainsString( '7h 05m, restless after 3am', $body, 'The author\'s words print as written.' );
		foreach ( [ '23:40', '06:45', '07:02' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, (string) wp_json_encode( $data ), $needle );
		}
	}

	public function test_a_hint_only_sleep_request_builds_a_card_that_prints_no_time_or_place(): void {
		$markup = Micropub_Content_Builder::fill_empty_content(
			'',
			[
				'properties' => [
					'pkiw-kind' => [ 'sleep' ],
					'location'  => [ 'geo:0,0' ],
				],
			]
		);
		$id     = $this->sleep( '2026-08-13', [], [ 'post_content' => $markup ] );
		$data   = $this->rest( $id );

		$this->assertStringNotContainsString( 'geo:', (string) wp_json_encode( $data ) );
		$this->assertStringContainsString( 'Moon phase', $data['content']['rendered'] );
		$this->assertStringNotContainsString( 'Duration', $data['content']['rendered'] );
	}

	public function test_a_single_parses_as_one_h_entry_whose_sleep_property_is_the_duration_only(): void {
		[ $h01 ] = $this->h01_and_n01();
		$t01     = $this->sleep(
			'2026-08-11',
			[
				'durationMinutes'   => 462,
				'bedtime'           => '2026-08-11T21:45',
				'wakeTime'          => '2026-08-12T06:15',
				'showTimesPublicly' => true,
			]
		);

		foreach ( [ $h01 => '7 hr', $t01 => '7 hr 42 min' ] as $id => $expected ) {
			$this->go_to( get_permalink( $id ) );
			$html   = (string) apply_filters( 'the_content', get_post( $id )->post_content );
			$parsed = \Mf2\parse( $html, 'https://example.org/' );

			$this->assertCount( 1, $parsed['items'] );
			$this->assertSame( [ $expected ], $parsed['items'][0]['properties']['sleep'] );
			$this->assertStringNotContainsString( '23:10', wp_json_encode( $parsed ) );
		}
	}

	public function test_activitypub_style_content_and_dates_are_the_card_at_its_tier_and_noon(): void {
		[ $h01 ] = $this->h01_and_n01();
		$post    = get_post( $h01 );

		$content = (string) apply_filters( 'the_content', $post->post_content );
		$this->assertStringNotContainsString( '23:10', $content );
		$this->assertSame( '2026-08-09 16:00:00', $post->post_date_gmt, 'post_date_gmt is noon of the night.' );
	}
}
