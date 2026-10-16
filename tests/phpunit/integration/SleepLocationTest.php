<?php
/**
 * Sleep Simple Location suppression (issue 247).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Sleep_Location;
use PKIW\Taxonomy;

/**
 * A sleep post has no place on any surface. The gate removes Simple
 * Location's own callbacks for a sleep pass, restores exactly what
 * remove_filter() reported removing, strips REST coordinates, address and
 * timezone, and drops sleep posts from the map and geo endpoints and the
 * location and venue archives. No test defines Simple Location's classes:
 * callbacks are string and array callables checked with has_filter().
 *
 * @group integration
 */
final class SleepLocationTest extends WP_UnitTestCase {

	private string $original_timezone = '';

	/**
	 * Probe callbacks added by a test.
	 *
	 * @var array<int, array{0:string,1:callable,2:int}>
	 */
	private array $probes = [];

	public function set_up(): void {
		parent::set_up();
		$this->original_timezone = (string) get_option( 'timezone_string' );
		update_option( 'timezone_string', 'America/New_York' );
		( new Taxonomy() )->create_default_terms();
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
	}

	public function tear_down(): void {
		foreach ( $this->probes as $probe ) {
			remove_filter( $probe[0], $probe[1], $probe[2] );
		}
		$this->probes = [];
		remove_filter( 'the_content', [ 'Geo_Data', 'content_map' ], 11 );
		remove_filter( 'the_content', [ 'Geo_Data', 'location_content' ], 12 );
		foreach ( [ 'rss_item', 'rss2_item', 'atom_entry', 'rdf_item' ] as $hook ) {
			remove_filter( $hook, [ 'Geo_Base', 'georss_item' ], 10 );
		}
		remove_filter( 'json_feed_item', [ 'Geo_Base', 'json_feed_item' ], 10 );
		remove_filter( 'wp_head', [ 'Geo_Base', 'meta_tags' ], 10 );
		update_option( 'timezone_string', $this->original_timezone );
		$this->set_permalink_structure( '' );
		wp_set_current_user( 0 );
		wp_reset_postdata();
		parent::tear_down();
	}

	public function test_non_sleep_content_pass_leaves_registered_callbacks_in_place(): void {
		$this->register_simple_location_callbacks();
		$note = $this->note( 'Plain note' );
		$this->set_global_post( $note );
		$this->go_to( get_permalink( $note ) );
		$this->assertSame( 'x', Sleep_Location::gate_content( 'x' ) );
		$this->assertSame( 11, has_filter( 'the_content', [ 'Geo_Data', 'content_map' ] ) );
		$this->assertSame( 12, has_filter( 'the_content', [ 'Geo_Data', 'location_content' ] ) );
		$this->assertSame( 'x', Sleep_Location::restore_content( 'x' ) );
		$this->assertSame( 11, has_filter( 'the_content', [ 'Geo_Data', 'content_map' ] ) );
		$this->assertSame( 12, has_filter( 'the_content', [ 'Geo_Data', 'location_content' ] ) );
	}

	public function test_sleep_content_pass_removes_and_restores_callbacks(): void {
		$this->register_simple_location_callbacks();
		$sleep = $this->sleep( 'Mapless night' );
		$this->set_global_post( $sleep );
		$seen = [];
		$this->add_probe(
			'the_content',
			function ( string $content ) use ( &$seen ): string {
				$seen[] = [
					has_filter( 'the_content', [ 'Geo_Data', 'content_map' ] ),
					has_filter( 'the_content', [ 'Geo_Data', 'location_content' ] ),
				];
				return $content;
			},
			12
		);

		$this->assertStringContainsString( 'body', apply_filters( 'the_content', 'body' ) );

		$this->assertSame( [ [ false, false ] ], $seen );
		$this->assertSame( 11, has_filter( 'the_content', [ 'Geo_Data', 'content_map' ] ) );
		$this->assertSame( 12, has_filter( 'the_content', [ 'Geo_Data', 'location_content' ] ) );
	}

	public function test_sleep_content_pass_with_none_registered_adds_none(): void {
		$sleep = $this->sleep( 'No callbacks' );
		$this->set_global_post( $sleep );

		$this->assertStringContainsString( 'body', apply_filters( 'the_content', 'body' ) );

		$this->assertFalse( has_filter( 'the_content', [ 'Geo_Data', 'content_map' ] ) );
		$this->assertFalse( has_filter( 'the_content', [ 'Geo_Data', 'location_content' ] ) );
	}

	public function test_nested_content_pass_keeps_the_outer_removal_stack(): void {
		$this->register_simple_location_callbacks();
		$sleep = $this->sleep( 'Outer night' );
		$note    = $this->note( 'Inner note' );
		$this->set_global_post( $sleep );
		$nested = false;
		$this->add_probe(
			'the_content',
			function ( string $content ) use ( $sleep, $note, &$nested ): string {
				if ( $nested ) {
					return $content;
				}
				$nested = true;
				$this->set_global_post( $note );
				apply_filters( 'the_content', 'inner' );
				$this->set_global_post( $sleep );
				$nested = false;
				return $content;
			},
			12
		);

		apply_filters( 'the_content', 'outer' );

		$this->assertSame( 11, has_filter( 'the_content', [ 'Geo_Data', 'content_map' ] ) );
		$this->assertSame( 12, has_filter( 'the_content', [ 'Geo_Data', 'location_content' ] ) );
	}

	/**
	 * Feed hooks Simple Location registers its GeoRSS item callback on.
	 *
	 * @return array<string, array{0:string}>
	 */
	public static function feed_hooks(): array {
		return [
			'rss_item'   => [ 'rss_item' ],
			'rss2_item'  => [ 'rss2_item' ],
			'atom_entry' => [ 'atom_entry' ],
			'rdf_item'   => [ 'rdf_item' ],
		];
	}

	/**
	 * @dataProvider feed_hooks
	 */
	public function test_feed_callback_is_removed_for_a_sleep_item_and_restored_on_every_feed_hook( string $hook ): void {
		$this->register_simple_location_callbacks();
		$sleep = $this->sleep( 'Feed night' );
		$seen    = [];
		$this->add_probe(
			$hook,
			function () use ( &$seen, $hook ): void {
				$seen[] = has_filter( $hook, [ 'Geo_Base', 'georss_item' ] );
			},
			10
		);
		$this->set_global_post( $sleep );
		do_action( $hook );

		$this->assertSame( [ false ], $seen );
		foreach ( self::feed_hooks() as $registered ) {
			$this->assertSame( 10, has_filter( $registered[0], [ 'Geo_Base', 'georss_item' ] ), $registered[0] . ' keeps its callback after the item' );
		}
	}

	/**
	 * Note items go through the gate directly, because the surviving callbacks name
	 * Simple Location classes the test run does not load.
	 *
	 * @dataProvider feed_hooks
	 */
	public function test_feed_callback_stays_for_a_note_item_on_every_feed_hook( string $hook ): void {
		$this->register_simple_location_callbacks();
		$note    = $this->note( 'Feed note' );
		$this->set_global_post( $note );
		$this->with_current_filter(
			$hook,
			function () use ( $hook ): void {
				Sleep_Location::gate_feed_item();
				$this->assertSame( 10, has_filter( $hook, [ 'Geo_Base', 'georss_item' ] ) );
				Sleep_Location::restore_feed_item();
				$this->assertSame( 10, has_filter( $hook, [ 'Geo_Base', 'georss_item' ] ) );
			}
		);
	}

	public function test_callables_registered_in_string_form_are_removed_and_restored(): void {
		add_filter( 'the_content', 'Geo_Data::content_map', 11 );
		add_filter( 'the_content', 'Geo_Data::location_content', 12 );
		$sleep = $this->sleep( 'String form night' );
		$this->set_global_post( $sleep );
		$seen = [];
		$this->add_probe(
			'the_content',
			function ( string $content ) use ( &$seen ): string {
				$seen[] = [
					has_filter( 'the_content', 'Geo_Data::content_map' ),
					has_filter( 'the_content', 'Geo_Data::location_content' ),
				];
				return $content;
			},
			12
		);

		apply_filters( 'the_content', 'body' );

		$this->assertSame( [ [ false, false ] ], $seen );
		$this->assertSame( 11, has_filter( 'the_content', 'Geo_Data::content_map' ) );
		$this->assertSame( 12, has_filter( 'the_content', 'Geo_Data::location_content' ) );
	}

	public function test_array_callables_of_another_class_or_method_are_left_in_place_for_a_sleep_pass(): void {
		$this->register_simple_location_callbacks();
		$instance = new class() {
			public function content_map( $content ) {
				return $content;
			}
		};
		$neighbors = [
			'class'  => [ 'Other_Plugin', 'content_map' ],
			'method' => [ 'Geo_Data', 'other_method' ],
			'object' => [ $instance, 'content_map' ],
		];
		foreach ( $neighbors as $callable ) {
			add_filter( 'the_content', $callable, 11 );
		}
		$this->set_global_post( $this->sleep( 'Neighbor night' ) );

		try {
			Sleep_Location::gate_content( 'x' );
			$during = [
				'ours'   => has_filter( 'the_content', [ 'Geo_Data', 'content_map' ] ),
				'class'  => has_filter( 'the_content', $neighbors['class'] ),
				'method' => has_filter( 'the_content', $neighbors['method'] ),
				'object' => has_filter( 'the_content', $neighbors['object'] ),
			];
			Sleep_Location::restore_content( 'x' );
			$after = has_filter( 'the_content', [ 'Geo_Data', 'content_map' ] );
		} finally {
			foreach ( $neighbors as $callable ) {
				remove_filter( 'the_content', $callable, 11 );
			}
		}

		$this->assertSame(
			[
				'ours'   => false,
				'class'  => 11,
				'method' => 11,
				'object' => 11,
			],
			$during
		);
		$this->assertSame( 11, $after );
	}

	public function test_array_callable_with_a_leading_backslash_class_name_is_removed_and_restored(): void {
		add_filter( 'the_content', [ '\\Geo_Data', 'content_map' ], 11 );
		$sleep = $this->sleep( 'Backslash night' );
		$this->set_global_post( $sleep );
		$seen = null;
		$this->add_probe(
			'the_content',
			function ( string $content ) use ( &$seen ): string {
				$seen = has_filter( 'the_content', [ '\\Geo_Data', 'content_map' ] );
				return $content;
			},
			12
		);

		try {
			apply_filters( 'the_content', 'body' );
			$restored = has_filter( 'the_content', [ '\\Geo_Data', 'content_map' ] );
		} finally {
			remove_filter( 'the_content', [ '\\Geo_Data', 'content_map' ], 11 );
		}

		$this->assertFalse( $seen );
		$this->assertSame( 11, $restored );
	}

	public function test_json_feed_geo_is_stripped_only_for_sleep_posts(): void {
		$sleep = get_post( $this->sleep( 'Json night' ) );
		$note    = get_post( $this->note( 'Json note' ) );

		$sleep_item = Sleep_Location::strip_json_feed_geo( [ 'title' => 'x', 'geo' => [ 'latitude' => 1 ] ], $sleep );
		$note_item    = Sleep_Location::strip_json_feed_geo( [ 'title' => 'x', 'geo' => [ 'latitude' => 1 ] ], $note );

		$this->assertArrayNotHasKey( 'geo', $sleep_item );
		$this->assertSame( 'x', $sleep_item['title'] );
		$this->assertArrayHasKey( 'geo', $note_item );
	}

	public function test_registered_json_feed_filter_strips_geo_only_for_sleep_posts(): void {
		$this->add_probe(
			'json_feed_item',
			static function ( array $item ): array {
				$item['geo'] = [ 'latitude' => 1 ];
				return $item;
			},
			10
		);
		$sleep = get_post( $this->sleep( 'Registered json night' ) );
		$note    = get_post( $this->note( 'Registered json note' ) );

		$sleep_item = apply_filters( 'json_feed_item', [ 'title' => 'x' ], $sleep );
		$note_item    = apply_filters( 'json_feed_item', [ 'title' => 'x' ], $note );

		$this->assertArrayNotHasKey( 'geo', $sleep_item );
		$this->assertSame( 'x', $sleep_item['title'] );
		$this->assertArrayHasKey( 'geo', $note_item );
	}

	public function test_registered_template_redirect_hook_removes_head_meta_only_for_sleep_singles(): void {
		$this->register_simple_location_callbacks();
		$sleep = $this->sleep( 'Registered head night' );
		$note    = $this->note( 'Registered head note' );

		$this->go_to( get_permalink( $sleep ) );
		do_action( 'template_redirect' );
		$this->assertFalse( has_filter( 'wp_head', [ 'Geo_Base', 'meta_tags' ] ) );

		add_action( 'wp_head', [ 'Geo_Base', 'meta_tags' ], 10 );
		$this->go_to( get_permalink( $note ) );
		do_action( 'template_redirect' );
		$this->assertSame( 10, has_filter( 'wp_head', [ 'Geo_Base', 'meta_tags' ] ) );
	}

	public function test_head_meta_is_removed_only_for_sleep_singles(): void {
		$this->register_simple_location_callbacks();
		$sleep = $this->sleep( 'Head night' );
		$note    = $this->note( 'Head note' );

		$this->go_to( get_permalink( $sleep ) );
		Sleep_Location::suppress_head_meta();
		$this->assertFalse( has_filter( 'wp_head', [ 'Geo_Base', 'meta_tags' ] ) );

		add_action( 'wp_head', [ 'Geo_Base', 'meta_tags' ], 10 );
		$this->go_to( get_permalink( $note ) );
		Sleep_Location::suppress_head_meta();
		$this->assertSame( 10, has_filter( 'wp_head', [ 'Geo_Base', 'meta_tags' ] ) );

		$this->go_to( get_term_link( 'sleep', Taxonomy::TAXONOMY ) );
		Sleep_Location::suppress_head_meta();
		$this->assertSame( 10, has_filter( 'wp_head', [ 'Geo_Base', 'meta_tags' ] ) );
	}

	public function test_rest_location_fields_are_stripped_for_sleep_view_context_only(): void {
		$sleep = get_post( $this->sleep( 'Rest night' ) );
		$note    = get_post( $this->note( 'Rest note' ) );
		$view    = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $sleep->ID );
		$view->set_param( 'context', 'view' );
		$edit = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $sleep->ID );
		$edit->set_param( 'context', 'edit' );

		$response = apply_filters( 'rest_prepare_post', $this->location_response( $sleep->ID ), $sleep, $view );
		$data     = $response->get_data();
		foreach ( [ 'latitude', 'longitude', 'geo_address', 'timezone' ] as $key ) {
			$this->assertArrayNotHasKey( $key, $data );
		}
		$this->assertSame( $sleep->ID, $data['id'] );

		$edit_data = apply_filters( 'rest_prepare_post', $this->location_response( $sleep->ID ), $sleep, $edit )->get_data();
		$this->assertArrayHasKey( 'latitude', $edit_data );
		$this->assertArrayHasKey( 'timezone', $edit_data );

		$note_data = apply_filters( 'rest_prepare_post', $this->location_response( $note->ID ), $note, $view )->get_data();
		$this->assertArrayHasKey( 'latitude', $note_data );
		$this->assertArrayHasKey( 'timezone', $note_data );
	}

	public function test_rest_date_and_modified_of_a_sleep_drop_the_utc_offset_a_priority_ten_filter_wrote(): void {
		$sleep = get_post( $this->sleep( 'Offset night' ) );
		$note    = get_post( $this->note( 'Offset note' ) );
		$view    = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $sleep->ID );
		$view->set_param( 'context', 'view' );
		$edit = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $sleep->ID );
		$edit->set_param( 'context', 'edit' );
		$tokyo_date     = '2026-10-10T19:00:00+09:00';
		$tokyo_modified = '2026-10-10T19:30:00+09:00';
		$this->add_probe(
			'rest_prepare_post',
			static function ( $response ) use ( $tokyo_date, $tokyo_modified ) {
				$data = $response->get_data();
				if ( isset( $data['date'] ) ) {
					$data['date'] = $tokyo_date;
				}
				if ( isset( $data['modified'] ) ) {
					$data['modified'] = $tokyo_modified;
				}
				$response->set_data( $data );

				return $response;
			},
			10
		);

		$data = apply_filters( 'rest_prepare_post', $this->location_response( $sleep->ID ), $sleep, $view )->get_data();
		$this->assertSame( mysql_to_rfc3339( $sleep->post_date ), $data['date'] );
		$this->assertSame( mysql_to_rfc3339( $sleep->post_modified ), $data['modified'] );
		$this->assertStringNotContainsString( '+09:00', $data['date'] );
		$this->assertStringNotContainsString( '+09:00', $data['modified'] );
		$this->assertSame( $sleep->ID, $data['id'] );

		$edit_data = apply_filters( 'rest_prepare_post', $this->location_response( $sleep->ID ), $sleep, $edit )->get_data();
		$this->assertSame( $tokyo_date, $edit_data['date'] );
		$this->assertSame( $tokyo_modified, $edit_data['modified'] );

		$note_data = apply_filters( 'rest_prepare_post', $this->location_response( $note->ID ), $note, $view )->get_data();
		$this->assertSame( $tokyo_date, $note_data['date'] );
		$this->assertSame( $tokyo_modified, $note_data['modified'] );
	}

	public function test_rest_fields_filter_keeps_absent_date_keys_absent_and_null_dates_null(): void {
		$sleep = get_post( $this->sleep( 'Fields night' ) );
		$view    = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $sleep->ID );
		$view->set_param( 'context', 'view' );

		$trimmed = apply_filters( 'rest_prepare_post', new WP_REST_Response( [ 'id' => $sleep->ID, 'title' => [ 'rendered' => 'x' ] ] ), $sleep, $view )->get_data();
		$this->assertArrayNotHasKey( 'date', $trimmed );
		$this->assertArrayNotHasKey( 'modified', $trimmed );

		$draft = apply_filters( 'rest_prepare_post', new WP_REST_Response( [ 'id' => $sleep->ID, 'date' => null, 'modified' => null ] ), $sleep, $view )->get_data();
		$this->assertArrayHasKey( 'date', $draft );
		$this->assertNull( $draft['date'] );
		$this->assertNull( $draft['modified'] );
	}

	public function test_no_simple_location_callbacks_registered_keeps_all_hooks_empty(): void {
		$sleep = $this->sleep( 'Empty hooks' );
		$this->set_global_post( $sleep );

		apply_filters( 'the_content', 'body' );
		do_action( 'rss2_item' );
		Sleep_Location::suppress_head_meta();

		$this->assertFalse( has_filter( 'the_content', [ 'Geo_Data', 'content_map' ] ) );
		$this->assertFalse( has_filter( 'the_content', [ 'Geo_Data', 'location_content' ] ) );
		$this->assertFalse( has_filter( 'rss2_item', [ 'Geo_Base', 'georss_item' ] ) );
		$this->assertFalse( has_filter( 'wp_head', [ 'Geo_Base', 'meta_tags' ] ) );
	}

	public function test_the_timezone_filters_run_after_simple_locations_at_priority_ten(): void {
		foreach ( [ 'get_the_date', 'get_the_time', 'get_the_modified_date', 'get_the_modified_time' ] as $hook ) {
			$this->assertSame( 10, has_filter( $hook, [ Sleep_Location::class, 'keep_site_timezone' ] ), $hook );
		}
	}

	public function test_the_gate_hooks_itself_in_once_at_the_documented_priorities(): void {
		$this->assertSame( 10, has_filter( 'the_content', [ Sleep_Location::class, 'gate_content' ] ) );
		$this->assertSame( 13, has_filter( 'the_content', [ Sleep_Location::class, 'restore_content' ] ) );
		foreach ( [ 'rss_item', 'rss2_item', 'atom_entry', 'rdf_item' ] as $hook ) {
			$this->assertSame( 9, has_action( $hook, [ Sleep_Location::class, 'gate_feed_item' ] ), $hook );
			$this->assertSame( 11, has_action( $hook, [ Sleep_Location::class, 'restore_feed_item' ] ), $hook );
		}
		$this->assertSame( 11, has_filter( 'json_feed_item', [ Sleep_Location::class, 'strip_json_feed_geo' ] ) );
		$this->assertSame( 10, has_action( 'template_redirect', [ Sleep_Location::class, 'suppress_head_meta' ] ) );
		$this->assertSame( 30, has_filter( 'rest_prepare_post', [ Sleep_Location::class, 'strip_rest_location' ] ) );
		$this->assertNotFalse( has_action( 'pre_get_posts', [ Sleep_Location::class, 'exclude_from_place_listings' ] ) );

		( new Sleep_Location() )->register();
		( new Sleep_Location() )->register();
		$this->assertSame( 10, has_filter( 'the_content', [ Sleep_Location::class, 'gate_content' ] ), 'A second register() adds nothing.' );
	}

	// Simple Location's timezone filters.

	/**
	 * Copy Simple Location's Loc_Timezone filters: priority 9 on the four date
	 * and time getters, formatted in the post's own geo_timezone.
	 */
	private function simple_location_timezone_filters(): void {
		$tokyo = static function ( $value, $format = '', $post = null ) {
			$post = get_post( $post );
			$zone = $post instanceof WP_Post ? (string) get_post_meta( $post->ID, 'geo_timezone', true ) : '';
			if ( '' === $zone ) {
				return $value;
			}
			$hook     = current_filter();
			$modified = str_contains( $hook, 'modified' );
			$time     = str_ends_with( $hook, '_time' );
			$format   = '' !== (string) $format ? (string) $format : (string) get_option( $time ? 'time_format' : 'date_format' );
			$moment   = get_post_datetime( $post, $modified ? 'modified' : 'date' );

			return $moment ? wp_date( $format, $moment->getTimestamp(), new DateTimeZone( $zone ) ) : $value;
		};
		foreach ( [ 'get_the_date', 'get_the_time', 'get_the_modified_date', 'get_the_modified_time' ] as $hook ) {
			add_filter( $hook, $tokyo, 9, 3 );
			$this->probes[] = [ $hook, $tokyo, 9 ];
		}
	}

	private function tokyo_post( string $kind ): int {
		$id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Night', 'post_date' => '2026-08-23 12:00:00', 'post_modified' => '2026-08-24 08:15:00', 'post_modified_gmt' => '2026-08-24 12:15:00' ] );
		wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY );
		update_post_meta( $id, 'geo_timezone', 'Asia/Tokyo' );
		global $wpdb;
		$wpdb->update( $wpdb->posts, [ 'post_modified' => '2026-08-24 08:15:00', 'post_modified_gmt' => '2026-08-24 12:15:00' ], [ 'ID' => $id ] );
		clean_post_cache( $id );

		return (int) $id;
	}

	public function test_a_sleep_posts_dates_stay_in_the_site_timezone_whatever_simple_location_stored(): void {
		$this->simple_location_timezone_filters();
		$sleep = $this->tokyo_post( 'sleep' );
		$note  = $this->tokyo_post( 'note' );

		// Precondition: the stand-in moves a note to Tokyo, where noon in New York is 01:00 the next day.
		$this->assertSame( 'August 24, 2026', get_the_date( '', $note ) );
		$this->assertSame( '2026-08-24T01:00:00+09:00', get_the_date( 'c', $note ) );

		$this->assertSame( 'August 23, 2026', get_the_date( '', $sleep ) );
		$this->assertSame( '2026-08-23T12:00:00-04:00', get_the_date( 'c', $sleep ) );
		$this->assertSame( '2026-08-23', get_the_date( 'Y-m-d', $sleep ) );
		$this->assertSame( '12:00 pm', get_the_time( '', $sleep ) );
		$this->assertSame( '12:00', get_the_time( 'H:i', $sleep ) );
		$this->assertSame( 'August 24, 2026', get_the_modified_date( '', $sleep ) );
		$this->assertSame( '2026-08-24T08:15:00-04:00', get_the_modified_date( 'c', $sleep ) );
		$this->assertSame( '08:15', get_the_modified_time( 'H:i', $sleep ), 'The modified fields are read as modified, not as the date.' );
		$this->assertSame( '1787500800', get_the_date( 'U', $sleep ), 'The requested format is respected.' );
		$this->assertSame( '01:00', get_the_time( 'H:i', $note ), 'Other kinds are left alone.' );
		$this->assertSame( '21:15', get_the_modified_time( 'H:i', $note ) );
	}

	public function test_the_global_post_form_and_an_admin_screen_get_the_site_timezone_too(): void {
		$this->simple_location_timezone_filters();
		$sleep = $this->tokyo_post( 'sleep' );
		$this->set_global_post( $sleep );

		$this->assertSame( '2026-08-23T12:00:00-04:00', get_the_date( 'c' ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		set_current_screen( 'edit-post' );
		try {
			$this->assertSame( '2026-08-23T12:00:00-04:00', get_the_date( 'c', $sleep ) );
		} finally {
			set_current_screen( 'front' );
		}
	}

	// Listings and the real REST route.

	/**
	 * Make Simple Location's endpoint query vars public, as the plugin does.
	 */
	private function public_place_vars(): void {
		add_filter(
			'query_vars',
			static function ( array $vars ): array {
				return array_merge( $vars, [ 'map', 'geo' ] );
			}
		);
	}

	/**
	 * Post IDs of the main query for a URL.
	 *
	 * @return int[]
	 */
	private function main_ids( string $url ): array {
		$this->go_to( $url );

		return array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );
	}

	public function test_the_map_and_geo_endpoints_leave_sleep_posts_out_and_keep_notes(): void {
		$this->public_place_vars();
		$sleep = $this->sleep( 'Mapped night' );
		$note  = $this->note( 'Mapped note' );

		$this->assertContains( $sleep, $this->main_ids( home_url( '/' ) ), 'Control: the home loop lists it.' );

		foreach ( [ '?map=1', '?geo=public' ] as $query ) {
			$ids = $this->main_ids( home_url( '/' . $query ) );
			$this->assertNotContains( $sleep, $ids, $query );
			$this->assertContains( $note, $ids, $query );
		}
	}

	public function test_a_location_term_archive_and_a_venue_archive_leave_sleep_posts_out(): void {
		register_taxonomy( 'location', 'post', [ 'public' => true, 'query_var' => 'location', 'rewrite' => false ] );
		$sleep = $this->sleep( 'Placed night' );
		$note  = $this->note( 'Placed note' );
		$this->assertNotWPError( wp_set_object_terms( $sleep, 'home', 'location' ) );
		$this->assertNotWPError( wp_set_object_terms( $note, 'home', 'location' ) );
		$this->assertNotWPError( wp_set_object_terms( $sleep, 'example-venue', 'pkiw_venue' ) );
		$this->assertNotWPError( wp_set_object_terms( $note, 'example-venue', 'pkiw_venue' ) );

		foreach ( [ 'location' => 'home', 'pkiw_venue' => 'example-venue' ] as $taxonomy => $slug ) {
			$ids = $this->main_ids( get_term_link( $slug, $taxonomy ) );
			$this->assertNotContains( $sleep, $ids, $taxonomy );
			$this->assertContains( $note, $ids, $taxonomy );
		}
		unregister_taxonomy( 'location' );
	}

	public function test_a_sleep_post_stays_in_the_sleep_archive_which_is_no_place_listing(): void {
		$sleep = $this->sleep( 'Archive night' );

		$this->assertContains( $sleep, $this->main_ids( get_term_link( 'sleep', Taxonomy::TAXONOMY ) ) );
	}

	public function test_an_existing_tax_query_is_kept_and_anded_with_the_exclusion(): void {
		$this->public_place_vars();
		$sleep = $this->sleep( 'Anded night' );
		$note  = $this->note( 'Anded note' );
		$tag   = self::factory()->tag->create( [ 'slug' => 'example-tag' ] );
		wp_set_post_tags( $sleep, [ 'example-tag' ] );
		wp_set_post_tags( $note, [ 'example-tag' ] );

		$ids = $this->main_ids( home_url( '/?map=1&tag=example-tag' ) );

		$this->assertNotContains( $sleep, $ids );
		$this->assertContains( $note, $ids );
		$this->assertGreaterThan( 0, $tag );
	}

	public function test_a_query_that_is_not_the_main_query_is_left_alone(): void {
		$this->public_place_vars();
		$sleep = $this->sleep( 'Side query night' );
		$this->go_to( home_url( '/' ) );

		$query = new WP_Query( [ 'map' => 1, 'post_type' => 'post', 'fields' => 'ids' ] );

		$this->assertContains( $sleep, array_map( 'intval', $query->posts ) );
	}

	public function test_the_real_rest_route_drops_a_sleep_posts_place_and_keeps_a_notes(): void {
		$place = static function ( $response, $post ) {
			$data                = $response->get_data();
			$data['latitude']    = 0.0;
			$data['longitude']   = 0.0;
			$data['geo_address'] = 'Example Street 1';
			$data['timezone']    = 'Asia/Tokyo';
			$data['meta']        = [
				'geo_latitude'      => '0',
				'geo_address'       => 'Example Street 1',
				'_pkiw_geo_latitude' => 0.0,
				'other_meta'        => 'kept',
			];
			$data['indieblocks_location'] = [
				'geo_latitude' => '0',
				'geo_address'  => 'Example Street 1',
			];
			$response->set_data( $data );

			return $response;
		};
		add_filter( 'rest_prepare_post', $place, 10, 2 );
		$sleep = $this->sleep( 'Rest night' );
		$note  = $this->note( 'Rest note' );

		$sleep_data = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $sleep ) )->get_data();
		$note_data  = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $note ) )->get_data();
		remove_filter( 'rest_prepare_post', $place, 10 );

		$json = (string) wp_json_encode( $sleep_data );
		foreach ( [ 'latitude', 'longitude', 'geo_address', 'timezone', 'Example Street', 'geo:', 'indieblocks_location', 'geo_' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $json, $needle );
		}
		$this->assertSame( [ 'other_meta' => 'kept' ], $sleep_data['meta'], 'Only the place keys leave meta.' );
		$this->assertSame( 'Example Street 1', $note_data['geo_address'] );
		$this->assertArrayHasKey( 'latitude', $note_data );
		$this->assertArrayHasKey( 'indieblocks_location', $note_data, 'A note keeps the field; the gate only acts on sleep.' );
		$this->assertArrayHasKey( 'other_meta', $note_data['meta'] );
	}

	public function test_the_rest_collection_route_also_drops_the_place(): void {
		$place = static function ( $response ) {
			$data                = $response->get_data();
			$data['geo_address'] = 'Example Street 1';
			$response->set_data( $data );

			return $response;
		};
		add_filter( 'rest_prepare_post', $place, 10 );
		$sleep = $this->sleep( 'Collection night' );

		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$request->set_param( 'include', [ $sleep ] );
		$data = rest_do_request( $request )->get_data();
		remove_filter( 'rest_prepare_post', $place, 10 );

		$this->assertCount( 1, $data );
		$this->assertArrayNotHasKey( 'geo_address', $data[0] );
	}

	public function test_a_post_with_a_second_kind_term_and_the_sleep_term_is_still_gated(): void {
		$id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Two kinds', 'post_date' => '2026-10-10 10:00:00' ] );
		wp_set_object_terms( $id, [ 'note', 'sleep' ], Taxonomy::TAXONOMY );
		$this->set_global_post( (int) $id );
		$this->register_simple_location_callbacks();
		$seen = [];
		$this->add_probe(
			'the_content',
			function ( string $content ) use ( &$seen ): string {
				$seen[] = has_filter( 'the_content', [ 'Geo_Data', 'content_map' ] );
				return $content;
			},
			12
		);

		apply_filters( 'the_content', 'body' );

		$this->assertSame( [ false ], $seen, 'Any sleep term gates the pass.' );
	}

	private function register_simple_location_callbacks(): void {
		add_filter( 'the_content', [ 'Geo_Data', 'content_map' ], 11 );
		add_filter( 'the_content', [ 'Geo_Data', 'location_content' ], 12 );
		foreach ( [ 'rss_item', 'rss2_item', 'atom_entry', 'rdf_item' ] as $hook ) {
			add_action( $hook, [ 'Geo_Base', 'georss_item' ], 10 );
		}
		add_filter( 'json_feed_item', [ 'Geo_Base', 'json_feed_item' ], 10 );
		add_action( 'wp_head', [ 'Geo_Base', 'meta_tags' ], 10 );

		$this->assertSame( 11, has_filter( 'the_content', [ 'Geo_Data', 'content_map' ] ) );
		$this->assertSame( 12, has_filter( 'the_content', [ 'Geo_Data', 'location_content' ] ) );
		$this->assertSame( 10, has_filter( 'rss2_item', [ 'Geo_Base', 'georss_item' ] ) );
	}

	/**
	 * Add a removable probe callback for this test.
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 * @return void
	 */
	private function add_probe( string $hook, callable $callback, int $priority ): void {
		add_filter( $hook, $callback, $priority );
		$this->probes[] = [ $hook, $callback, $priority ];
	}

	private function sleep( string $title ): int {
		$id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => $title, 'post_date' => '2026-10-10 10:00:00' ] );
		wp_set_object_terms( $id, 'sleep', Taxonomy::TAXONOMY );

		return (int) $id;
	}

	private function note( string $title ): int {
		$id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => $title, 'post_date' => '2026-10-10 10:00:00' ] );
		wp_set_object_terms( $id, 'note', Taxonomy::TAXONOMY );

		return (int) $id;
	}

	private function set_global_post( int $post_id ): void {
		global $post;
		$post = get_post( $post_id );
		setup_postdata( $post );
	}

	private function with_current_filter( string $hook, callable $callback ): void {
		$GLOBALS['wp_current_filter'][] = $hook;
		try {
			$callback();
		} finally {
			array_pop( $GLOBALS['wp_current_filter'] );
		}
	}

	private function location_response( int $id ): WP_REST_Response {
		return new WP_REST_Response(
			[
				'id'          => $id,
				'latitude'    => 0.5,
				'longitude'   => -30.5,
				'geo_address' => '12 Example Lane, Sampletown',
				'timezone'    => 'Asia/Tokyo',
				'date'        => '2026-10-10T10:00:00',
				'modified'    => '2026-10-10T10:00:00',
				'title'       => [ 'rendered' => 'x' ],
			]
		);
	}
}
