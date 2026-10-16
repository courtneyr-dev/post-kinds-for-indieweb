<?php
/**
 * Simple Location stays off exercise posts (issue 245).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Exercise_Location;
use PKIW\Micropub_Content_Builder;
use PKIW\Taxonomy;
use PKIW\Venue_Taxonomy;

/**
 * Simple Location's map, address, GeoRSS, JSON Feed geo, head meta and REST
 * coordinates are held back for an exercise post, and its map and geo
 * endpoints, location archive and the venue archive leave exercise posts out.
 *
 * The tests register Simple Location's callables by name, as it does
 * (`[ 'Geo_Data', 'content_map' ]`), and never define a stand-in Geo_Data or
 * Geo_Base class (W3 conflict C2).
 *
 * @group integration
 */
final class ExerciseLocationTest extends WP_UnitTestCase {

	/**
	 * Probe callbacks added by a test.
	 *
	 * @var array<int, array{0:string,1:callable,2:int}>
	 */
	private array $probes = [];

	private bool $registered_location = false;

	/**
	 * The main query before a test swapped it.
	 *
	 * @var WP_Query|null
	 */
	private ?WP_Query $original_main = null;

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		$this->original_main = $GLOBALS['wp_the_query'] ?? null;
		Exercise_Location::reset();
		// The plugin wired the gate at load; a second call adds nothing.
		( new Exercise_Location() )->register();
		wp_set_current_user( 0 );
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
		if ( $this->registered_location && taxonomy_exists( 'location' ) ) {
			unregister_taxonomy( 'location' );
		}
		if ( null !== $this->original_main ) {
			$GLOBALS['wp_the_query'] = $this->original_main;
		}
		Exercise_Location::reset();
		wp_set_current_user( 0 );
		wp_reset_postdata();
		parent::tear_down();
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

	private function add_probe( string $hook, callable $callback, int $priority ): void {
		add_filter( $hook, $callback, $priority );
		$this->probes[] = [ $hook, $callback, $priority ];
	}

	/**
	 * A published post of a kind.
	 *
	 * @param string               $kind Kind slug.
	 * @param array<string, mixed> $args Post args.
	 */
	private function post( string $kind, array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status' => 'publish',
					'post_title'  => ucfirst( $kind ) . ' fixture',
					'post_date'   => '2026-08-23 10:00:00',
				],
				$args
			)
		);
		wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY );

		return (int) $id;
	}

	private function set_global_post( int $post_id ): void {
		global $post;
		$post = get_post( $post_id );
		setup_postdata( $post );
	}

	/**
	 * Both of Simple Location's content callables, as has_filter() sees them.
	 *
	 * @return array{0: int|false, 1: int|false}
	 */
	private function content_state(): array {
		return [
			has_filter( 'the_content', [ 'Geo_Data', 'content_map' ] ),
			has_filter( 'the_content', [ 'Geo_Data', 'location_content' ] ),
		];
	}

	/**
	 * One the_content pass for a post, run through the gate directly.
	 *
	 * A note pass can't run through apply_filters(): the callables it leaves
	 * in place name Simple Location classes this run doesn't load.
	 *
	 * @param int      $post_id Post ID.
	 * @param callable $inside  Called between the gate and the restore, with the post set up.
	 */
	private function direct_pass( int $post_id, callable $inside ): void {
		$this->set_global_post( $post_id );
		Exercise_Location::gate_content( 'x' );
		try {
			$inside();
		} finally {
			Exercise_Location::restore_content( 'x' );
		}
	}

	// The_content.

	public function test_a_note_pass_leaves_both_callbacks_in_place(): void {
		$this->register_simple_location_callbacks();
		$seen = [];

		$this->direct_pass(
			$this->post( 'note' ),
			function () use ( &$seen ): void {
				$seen[] = $this->content_state();
			}
		);

		$this->assertSame( [ [ 11, 12 ] ], $seen );
		$this->assertSame( [ 11, 12 ], $this->content_state() );
	}

	public function test_an_exercise_pass_removes_both_while_it_runs_and_restores_them_at_their_own_priorities(): void {
		$this->register_simple_location_callbacks();
		$this->set_global_post( $this->post( 'exercise' ) );
		$seen = [];
		$this->add_probe(
			'the_content',
			function ( string $content ) use ( &$seen ): string {
				$seen[] = $this->content_state();
				return $content;
			},
			12
		);

		$this->assertStringContainsString( 'body', apply_filters( 'the_content', 'body' ) );

		$this->assertSame( [ [ false, false ] ], $seen, 'Neither runs for an exercise post.' );
		$this->assertSame( [ 11, 12 ], $this->content_state(), 'Both are back at 11 and 12, not at 13.' );
	}

	public function test_a_second_exercise_pass_in_the_request_is_gated_again(): void {
		$this->register_simple_location_callbacks();
		$this->set_global_post( $this->post( 'exercise' ) );
		$seen = [];
		$this->add_probe(
			'the_content',
			function ( string $content ) use ( &$seen ): string {
				$seen[] = $this->content_state();
				return $content;
			},
			12
		);

		apply_filters( 'the_content', 'one' );
		apply_filters( 'the_content', 'two' );

		$this->assertSame( [ [ false, false ], [ false, false ] ], $seen );
		$this->assertSame( [ 11, 12 ], $this->content_state() );
	}

	public function test_with_nothing_registered_nothing_is_added(): void {
		$this->set_global_post( $this->post( 'exercise' ) );

		apply_filters( 'the_content', 'body' );
		do_action( 'rss2_item' );

		$this->assertSame( [ false, false ], $this->content_state() );
		$this->assertFalse( has_filter( 'rss2_item', [ 'Geo_Base', 'georss_item' ] ) );
		$this->assertFalse( has_filter( 'wp_head', [ 'Geo_Base', 'meta_tags' ] ) );
	}

	public function test_a_note_pass_nested_inside_an_exercise_pass_keeps_the_outer_removal(): void {
		$this->register_simple_location_callbacks();
		$exercise = $this->post( 'exercise' );
		$note     = $this->post( 'note' );
		$this->set_global_post( $exercise );
		$nested = false;
		$inner  = [];
		$outer  = [];
		$this->add_probe(
			'the_content',
			function ( string $content ) use ( $exercise, $note, &$nested, &$inner, &$outer ): string {
				if ( $nested ) {
					return $content;
				}
				$nested = true;
				$this->direct_pass(
					$note,
					function () use ( &$inner ): void {
						$inner[] = $this->content_state();
					}
				);
				$this->set_global_post( $exercise );
				$nested = false;
				$outer[] = $this->content_state();
				return $content;
			},
			12
		);

		apply_filters( 'the_content', 'outer' );

		$this->assertSame( [ [ false, false ] ], $inner, 'The outer removal still holds inside the nested note pass, which restores nothing early.' );
		$this->assertSame( [ [ false, false ] ], $outer, 'The outer exercise pass is still gated after the nested pass.' );
		$this->assertSame( [ 11, 12 ], $this->content_state() );
	}

	public function test_an_exercise_pass_nested_inside_a_note_pass_does_not_strip_the_outer_note(): void {
		$this->register_simple_location_callbacks();
		$exercise = $this->post( 'exercise' );
		$note     = $this->post( 'note' );
		$states   = [];

		$this->direct_pass(
			$note,
			function () use ( $exercise, &$states ): void {
				$states['outer before'] = $this->content_state();
				$this->direct_pass(
					$exercise,
					function () use ( &$states ): void {
						$states['inner'] = $this->content_state();
					}
				);
				$states['outer after'] = $this->content_state();
			}
		);

		$this->assertSame(
			[
				'outer before' => [ 11, 12 ],
				'inner'        => [ false, false ],
				'outer after'  => [ 11, 12 ],
			],
			$states,
			'The inner pass restored what it removed before the outer note reads.'
		);
		$this->assertSame( [ 11, 12 ], $this->content_state() );
	}

	public function test_a_post_that_holds_the_exercise_term_among_others_is_gated(): void {
		$this->register_simple_location_callbacks();
		$id = $this->post( 'note' );
		// Terms list by name, so 'Craft' comes first and 'Exercise' second.
		wp_set_object_terms( $id, [ 'craft', 'exercise' ], Taxonomy::TAXONOMY );
		$this->assertSame( 'craft', get_the_terms( $id, Taxonomy::TAXONOMY )[0]->slug, 'The exercise term is not the first.' );
		$this->set_global_post( $id );
		$seen = [];
		$this->add_probe(
			'the_content',
			function ( string $content ) use ( &$seen ): string {
				$seen[] = $this->content_state();
				return $content;
			},
			12
		);

		apply_filters( 'the_content', 'body' );

		$this->assertSame( [ [ false, false ] ], $seen, 'Not just the first term.' );
	}

	public function test_a_protected_or_private_exercise_post_is_gated_too(): void {
		$this->register_simple_location_callbacks();
		foreach ( [ [ 'post_password' => 'secret' ], [ 'post_status' => 'private' ] ] as $args ) {
			$this->set_global_post( $this->post( 'exercise', $args ) );
			$seen = [];
			$probe = function ( string $content ) use ( &$seen ): string {
				$seen[] = $this->content_state();
				return $content;
			};
			add_filter( 'the_content', $probe, 12 );
			apply_filters( 'the_content', 'body' );
			remove_filter( 'the_content', $probe, 12 );

			$this->assertSame( [ [ false, false ] ], $seen );
		}
	}

	public function test_callables_registered_in_string_form_or_with_a_leading_backslash_are_removed_and_restored(): void {
		add_filter( 'the_content', 'Geo_Data::content_map', 11 );
		add_filter( 'the_content', [ '\\Geo_Data', 'location_content' ], 12 );
		$this->set_global_post( $this->post( 'exercise' ) );
		$seen = [];
		$this->add_probe(
			'the_content',
			static function ( string $content ) use ( &$seen ): string {
				$seen[] = [
					has_filter( 'the_content', 'Geo_Data::content_map' ),
					has_filter( 'the_content', [ '\\Geo_Data', 'location_content' ] ),
				];
				return $content;
			},
			12
		);

		try {
			apply_filters( 'the_content', 'body' );
			$after = [
				has_filter( 'the_content', 'Geo_Data::content_map' ),
				has_filter( 'the_content', [ '\\Geo_Data', 'location_content' ] ),
			];
		} finally {
			remove_filter( 'the_content', 'Geo_Data::content_map', 11 );
			remove_filter( 'the_content', [ '\\Geo_Data', 'location_content' ], 12 );
		}

		$this->assertSame( [ [ false, false ] ], $seen );
		$this->assertSame( [ 11, 12 ], $after );
	}

	public function test_callables_of_another_class_or_method_are_left_alone(): void {
		$this->register_simple_location_callbacks();
		$instance  = new class() {
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
		$this->set_global_post( $this->post( 'exercise' ) );

		try {
			Exercise_Location::gate_content( 'x' );
			$during = [
				'ours'   => has_filter( 'the_content', [ 'Geo_Data', 'content_map' ] ),
				'class'  => has_filter( 'the_content', $neighbors['class'] ),
				'method' => has_filter( 'the_content', $neighbors['method'] ),
				'object' => has_filter( 'the_content', $neighbors['object'] ),
			];
			Exercise_Location::restore_content( 'x' );
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
	}

	public function test_a_restore_with_no_matching_gate_adds_nothing(): void {
		Exercise_Location::reset();

		$this->assertSame( 'x', Exercise_Location::restore_content( 'x' ) );
		$this->assertSame( [ false, false ], $this->content_state() );
	}

	// Feeds.

	/**
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
	 * One feed item through the gate: whether GeoRSS is on while it prints.
	 *
	 * @param string $hook Feed hook.
	 * @param int    $id   Post ID.
	 * @return int|false
	 */
	private function georss_during_item( string $hook, int $id ) {
		$this->set_global_post( $id );
		$GLOBALS['wp_current_filter'][] = $hook;
		try {
			Exercise_Location::gate_feed_item();
			$during = has_filter( $hook, [ 'Geo_Base', 'georss_item' ] );
			Exercise_Location::restore_feed_item();
		} finally {
			array_pop( $GLOBALS['wp_current_filter'] );
		}

		return $during;
	}

	/**
	 * @dataProvider feed_hooks
	 */
	public function test_georss_is_off_for_an_exercise_item_and_back_for_the_next_on_every_feed_hook( string $hook ): void {
		$this->register_simple_location_callbacks();
		$exercise = $this->post( 'exercise' );
		$note     = $this->post( 'note' );

		$seen = [
			$this->georss_during_item( $hook, $exercise ),
			$this->georss_during_item( $hook, $note ),
			$this->georss_during_item( $hook, $exercise ),
		];

		$this->assertSame( [ false, 10, false ], $seen, 'Off for exercise items, on for the note between them.' );
		foreach ( self::feed_hooks() as $registered ) {
			$this->assertSame( 10, has_filter( $registered[0], [ 'Geo_Base', 'georss_item' ] ), $registered[0] . ' keeps its callback after the loop' );
		}
	}

	/**
	 * A real feed loop over exercise items only: the registered gate runs at 9 and 11.
	 *
	 * @dataProvider feed_hooks
	 */
	public function test_the_registered_hooks_gate_a_real_feed_item( string $hook ): void {
		$this->register_simple_location_callbacks();
		$seen = [];
		$this->add_probe(
			$hook,
			function () use ( &$seen, $hook ): void {
				$seen[] = has_filter( $hook, [ 'Geo_Base', 'georss_item' ] );
			},
			10
		);

		$this->set_global_post( $this->post( 'exercise' ) );
		do_action( $hook );

		$this->assertSame( [ false ], $seen );
		$this->assertSame( 10, has_filter( $hook, [ 'Geo_Base', 'georss_item' ] ), 'Back at priority 10 after the item.' );
	}

	public function test_a_feed_with_nothing_registered_adds_nothing_back(): void {
		$this->set_global_post( $this->post( 'exercise' ) );
		do_action( 'rss2_item' );

		$this->assertFalse( has_filter( 'rss2_item', [ 'Geo_Base', 'georss_item' ] ) );
	}

	public function test_json_feed_geo_is_stripped_only_for_exercise_posts(): void {
		$this->add_probe(
			'json_feed_item',
			static function ( array $item ): array {
				$item['geo'] = [ 'latitude' => 1 ];
				return $item;
			},
			10
		);
		$exercise = get_post( $this->post( 'exercise' ) );
		$note     = get_post( $this->post( 'note' ) );

		$exercise_item = apply_filters( 'json_feed_item', [ 'title' => 'x' ], $exercise );
		$note_item     = apply_filters( 'json_feed_item', [ 'title' => 'x' ], $note );

		$this->assertArrayNotHasKey( 'geo', $exercise_item );
		$this->assertSame( 'x', $exercise_item['title'] );
		$this->assertArrayHasKey( 'geo', $note_item );
		$this->assertSame( [ 'title' => 'x' ], Exercise_Location::strip_json_feed_geo( [ 'title' => 'x' ], $exercise ) );
		$this->assertSame( 'not an item', Exercise_Location::strip_json_feed_geo( 'not an item', $exercise ) );
	}

	// Head.

	public function test_head_meta_comes_off_only_an_exercise_single(): void {
		$this->register_simple_location_callbacks();
		$exercise = $this->post( 'exercise' );
		$note     = $this->post( 'note' );

		$this->go_to( get_permalink( $exercise ) );
		do_action( 'template_redirect' );
		$this->assertFalse( has_filter( 'wp_head', [ 'Geo_Base', 'meta_tags' ] ) );

		add_action( 'wp_head', [ 'Geo_Base', 'meta_tags' ], 10 );
		$this->go_to( get_permalink( $note ) );
		do_action( 'template_redirect' );
		$this->assertSame( 10, has_filter( 'wp_head', [ 'Geo_Base', 'meta_tags' ] ) );

		$this->go_to( get_term_link( 'exercise', Taxonomy::TAXONOMY ) );
		do_action( 'template_redirect' );
		$this->assertSame( 10, has_filter( 'wp_head', [ 'Geo_Base', 'meta_tags' ] ), 'An archive has no single to protect.' );
	}

	// REST.

	private function location_response( int $id ): WP_REST_Response {
		return new WP_REST_Response(
			[
				'id'          => $id,
				'latitude'    => 0.5,
				'longitude'   => -30.5,
				'geo_address' => '12 Example Lane, Sampletown',
				'timezone'    => 'Asia/Tokyo',
				'title'       => [ 'rendered' => 'x' ],
			]
		);
	}

	public function test_rest_coordinates_and_address_are_stripped_for_an_exercise_post_and_not_a_note(): void {
		$exercise = get_post( $this->post( 'exercise' ) );
		$note     = get_post( $this->post( 'note' ) );
		$view     = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $exercise->ID );
		$view->set_param( 'context', 'view' );
		$edit = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $exercise->ID );
		$edit->set_param( 'context', 'edit' );

		$data = apply_filters( 'rest_prepare_post', $this->location_response( $exercise->ID ), $exercise, $view )->get_data();
		foreach ( [ 'latitude', 'longitude', 'geo_address' ] as $key ) {
			$this->assertArrayNotHasKey( $key, $data, $key );
		}
		$this->assertSame( 'Asia/Tokyo', $data['timezone'], 'The zone is coarser than the approximate tier and stays.' );
		$this->assertSame( $exercise->ID, $data['id'] );

		$edit_data = apply_filters( 'rest_prepare_post', $this->location_response( $exercise->ID ), $exercise, $edit )->get_data();
		$this->assertArrayHasKey( 'latitude', $edit_data, 'The author\'s own edit context.' );

		$note_data = apply_filters( 'rest_prepare_post', $this->location_response( $note->ID ), $note, $view )->get_data();
		foreach ( [ 'latitude', 'longitude', 'geo_address' ] as $key ) {
			$this->assertArrayHasKey( $key, $note_data, 'A note keeps ' . $key );
		}
	}

	public function test_a_real_rest_request_for_an_exercise_post_loses_the_fields_a_priority_ten_filter_added(): void {
		$this->add_probe(
			'rest_prepare_post',
			static function ( $response ) {
				$data                = $response->get_data();
				$data['latitude']    = 0.5;
				$data['longitude']   = -30.5;
				$data['geo_address'] = '12 Example Lane, Sampletown';
				$response->set_data( $data );

				return $response;
			},
			10
		);
		$exercise = $this->post( 'exercise' );
		$note     = $this->post( 'note' );

		$data = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $exercise ) )->get_data();
		$this->assertArrayNotHasKey( 'latitude', $data );
		$this->assertArrayNotHasKey( 'longitude', $data );
		$this->assertArrayNotHasKey( 'geo_address', $data );
		$this->assertStringNotContainsString( 'Example Lane', (string) wp_json_encode( $data ) );

		$note_data = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $note ) )->get_data();
		$this->assertArrayHasKey( 'latitude', $note_data );
	}

	public function test_a_response_that_is_not_a_post_response_passes_through(): void {
		$this->assertSame( 'x', Exercise_Location::strip_rest_location( 'x', null, null ) );
		$response = new WP_REST_Response( [ 'latitude' => 1 ] );
		$this->assertSame( $response, Exercise_Location::strip_rest_location( $response, get_post( $this->post( 'exercise' ) ), 'not a request' ) );
	}

	// Listings.

	/**
	 * Run a query as the main query.
	 *
	 * @param array<string, mixed> $args Query args.
	 * @return int[] Post IDs.
	 */
	private function main_query( array $args ): array {
		$query                   = new WP_Query();
		$GLOBALS['wp_the_query'] = $query;
		$posts                   = $query->query( array_merge( [ 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids' ], $args ) );

		return array_map( 'intval', $posts );
	}

	public function test_the_map_and_geo_endpoints_leave_exercise_posts_out(): void {
		$exercise = $this->post( 'exercise' );
		$note     = $this->post( 'note' );

		$this->assertSame( [ $note ], array_values( array_intersect( $this->main_query( [ 'map' => '1' ] ), [ $exercise, $note ] ) ) );
		$this->assertSame( [ $note ], array_values( array_intersect( $this->main_query( [ 'geo' => 'public' ] ), [ $exercise, $note ] ) ) );
		$this->assertEqualsCanonicalizing( [ $exercise, $note ], array_values( array_intersect( $this->main_query( [] ), [ $exercise, $note ] ) ), 'An ordinary main query is untouched.' );
	}

	public function test_a_location_archive_and_a_venue_archive_leave_exercise_posts_out(): void {
		if ( ! taxonomy_exists( 'location' ) ) {
			// Simple Location isn't loaded here; stand its public taxonomy in by name.
			$this->registered_location = true;
			register_taxonomy( 'location', 'post', [ 'public' => true, 'query_var' => 'location' ] );
		}
		$exercise = $this->post( 'exercise' );
		$note     = $this->post( 'note' );
		foreach ( [ 'location', Venue_Taxonomy::TAXONOMY ] as $taxonomy ) {
			$this->assertTrue( taxonomy_exists( $taxonomy ), $taxonomy );
			$term = wp_insert_term( 'Maplewood ' . $taxonomy, $taxonomy );
			$this->assertIsArray( $term );
			wp_set_object_terms( $exercise, [ (int) $term['term_id'] ], $taxonomy, true );
			wp_set_object_terms( $note, [ (int) $term['term_id'] ], $taxonomy, true );

			$ids = $this->main_query(
				[
					'tax_query' => [
						[
							'taxonomy' => $taxonomy,
							'field'    => 'term_id',
							'terms'    => [ (int) $term['term_id'] ],
						],
					],
				]
			);
			$this->assertSame( [ $note ], $ids, $taxonomy . ' archive' );
		}
	}

	public function test_a_query_that_is_not_the_main_query_is_never_touched(): void {
		$exercise = $this->post( 'exercise' );
		$other    = new WP_Query( [ 'map' => '1', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => -1 ] );

		$this->assertContains( $exercise, array_map( 'intval', $other->posts ) );
	}

	public function test_an_existing_tax_query_is_kept_beside_the_exclusion(): void {
		$exercise = $this->post( 'exercise' );
		$note     = $this->post( 'note' );
		wp_set_object_terms( $note, [ 'note', 'article' ], Taxonomy::TAXONOMY );

		$ids = $this->main_query(
			[
				'map'       => '1',
				'tax_query' => [
					[
						'taxonomy' => Taxonomy::TAXONOMY,
						'field'    => 'slug',
						'terms'    => [ 'note', 'exercise' ],
					],
				],
			]
		);

		$this->assertContains( $note, $ids );
		$this->assertNotContains( $exercise, $ids );
	}

	// What Post Kinds itself assigns.

	public function test_no_exercise_class_assigns_a_location_or_venue_term(): void {
		foreach ( [ 'class-exercise-archive.php', 'class-exercise-medals.php', 'class-exercise-location.php' ] as $file ) {
			$source = (string) file_get_contents( PKIW_PATH . 'includes/' . $file );
			foreach ( [ 'wp_set_object_terms', 'wp_set_post_terms', 'wp_insert_term', 'wp_add_object_terms', 'wp_set_post_categories' ] as $call ) {
				$this->assertStringNotContainsString( $call . '(', $source, "{$file} calls {$call}." );
			}
		}
	}

	public function test_a_micropub_exercise_with_a_location_gets_no_location_or_venue_term(): void {
		if ( ! taxonomy_exists( 'location' ) ) {
			$this->registered_location = true;
			register_taxonomy( 'location', 'post', [ 'public' => true ] );
		}
		$content = Micropub_Content_Builder::fill_empty_content(
			'',
			[
				'exercise' => [ 'Run' ],
				'location' => [ 'geo:45.1,-75.2' ],
			]
		);
		$id      = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => $content,
			]
		);

		$this->assertTrue( has_term( 'exercise', Taxonomy::TAXONOMY, $id ), 'The card put the post in the exercise kind.' );
		$this->assertSame( [], wp_get_object_terms( $id, [ 'location', Venue_Taxonomy::TAXONOMY ] ) );
		$this->assertStringNotContainsString( '45.1', $content );
		$this->assertStringNotContainsString( 'geo:', $content );
	}

	public function test_registering_twice_adds_no_second_callback(): void {
		( new Exercise_Location() )->register();
		( new Exercise_Location() )->register();

		$this->assertSame( 10, has_filter( 'the_content', [ Exercise_Location::class, 'gate_content' ] ) );
		$this->assertSame( 13, has_filter( 'the_content', [ Exercise_Location::class, 'restore_content' ] ) );
		$this->assertSame( 30, has_filter( 'rest_prepare_post', [ Exercise_Location::class, 'strip_rest_location' ] ) );
		$this->assertSame( 9, has_filter( 'rss2_item', [ Exercise_Location::class, 'gate_feed_item' ] ) );
		$this->assertSame( 11, has_filter( 'rss2_item', [ Exercise_Location::class, 'restore_feed_item' ] ) );
		$this->assertSame( 11, has_filter( 'json_feed_item', [ Exercise_Location::class, 'strip_json_feed_geo' ] ) );
		$this->assertSame( 10, has_filter( 'template_redirect', [ Exercise_Location::class, 'suppress_head_meta' ] ) );
		$this->assertSame( 10, has_filter( 'pre_get_posts', [ Exercise_Location::class, 'exclude_from_place_listings' ] ) );
	}
}
