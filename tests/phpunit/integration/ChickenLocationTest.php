<?php
/**
 * Chicken Simple Location suppression (issue 250).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Chicken_Archive;
use PKIW\Taxonomy;

/**
 * Chicken surfaces remove Simple Location output without wrapping callbacks.
 *
 * @group integration
 */
final class ChickenLocationTest extends WP_UnitTestCase {

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

	public function test_non_chicken_content_pass_leaves_registered_callbacks_in_place(): void {
		$this->register_simple_location_callbacks();
		$note = $this->note( 'Plain note' );
		$this->set_global_post( $note );
		$this->go_to( get_permalink( $note ) );

		$archive = new Chicken_Archive();
		$this->assertSame( 'x', $archive->gate_content( 'x' ) );
		$this->assertSame( 11, has_filter( 'the_content', [ 'Geo_Data', 'content_map' ] ) );
		$this->assertSame( 12, has_filter( 'the_content', [ 'Geo_Data', 'location_content' ] ) );
		$this->assertSame( 'x', $archive->restore_content( 'x' ) );
		$this->assertSame( 11, has_filter( 'the_content', [ 'Geo_Data', 'content_map' ] ) );
		$this->assertSame( 12, has_filter( 'the_content', [ 'Geo_Data', 'location_content' ] ) );
	}

	public function test_chicken_content_pass_removes_and_restores_callbacks(): void {
		$this->register_simple_location_callbacks();
		$chicken = $this->chicken( 'Mapless hen' );
		$this->set_global_post( $chicken );
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

	public function test_chicken_content_pass_with_none_registered_adds_none(): void {
		$chicken = $this->chicken( 'No callbacks' );
		$this->set_global_post( $chicken );

		$this->assertStringContainsString( 'body', apply_filters( 'the_content', 'body' ) );

		$this->assertFalse( has_filter( 'the_content', [ 'Geo_Data', 'content_map' ] ) );
		$this->assertFalse( has_filter( 'the_content', [ 'Geo_Data', 'location_content' ] ) );
	}

	public function test_nested_content_pass_keeps_the_outer_removal_stack(): void {
		$this->register_simple_location_callbacks();
		$chicken = $this->chicken( 'Outer hen' );
		$note    = $this->note( 'Inner note' );
		$this->set_global_post( $chicken );
		$nested = false;
		$this->add_probe(
			'the_content',
			function ( string $content ) use ( $chicken, $note, &$nested ): string {
				if ( $nested ) {
					return $content;
				}
				$nested = true;
				$this->set_global_post( $note );
				apply_filters( 'the_content', 'inner' );
				$this->set_global_post( $chicken );
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
	public function test_feed_callback_is_removed_for_a_chicken_item_and_restored_on_every_feed_hook( string $hook ): void {
		$this->register_simple_location_callbacks();
		$chicken = $this->chicken( 'Feed hen' );
		$seen    = [];
		$this->add_probe(
			$hook,
			function () use ( &$seen, $hook ): void {
				$seen[] = has_filter( $hook, [ 'Geo_Base', 'georss_item' ] );
			},
			10
		);
		$this->set_global_post( $chicken );
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
		$archive = new Chicken_Archive();
		$this->set_global_post( $note );
		$this->with_current_filter(
			$hook,
			function () use ( $archive, $hook ): void {
				$archive->gate_feed_item();
				$this->assertSame( 10, has_filter( $hook, [ 'Geo_Base', 'georss_item' ] ) );
				$archive->restore_feed_item();
				$this->assertSame( 10, has_filter( $hook, [ 'Geo_Base', 'georss_item' ] ) );
			}
		);
	}

	public function test_callables_registered_in_string_form_are_removed_and_restored(): void {
		add_filter( 'the_content', 'Geo_Data::content_map', 11 );
		add_filter( 'the_content', 'Geo_Data::location_content', 12 );
		$chicken = $this->chicken( 'String form hen' );
		$this->set_global_post( $chicken );
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

	public function test_array_callables_of_another_class_or_method_are_left_in_place_for_a_chicken_pass(): void {
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
		$archive = new Chicken_Archive();
		$this->set_global_post( $this->chicken( 'Neighbor hen' ) );

		try {
			$archive->gate_content( 'x' );
			$during = [
				'ours'   => has_filter( 'the_content', [ 'Geo_Data', 'content_map' ] ),
				'class'  => has_filter( 'the_content', $neighbors['class'] ),
				'method' => has_filter( 'the_content', $neighbors['method'] ),
				'object' => has_filter( 'the_content', $neighbors['object'] ),
			];
			$archive->restore_content( 'x' );
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
		$chicken = $this->chicken( 'Backslash hen' );
		$this->set_global_post( $chicken );
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

	public function test_json_feed_geo_is_stripped_only_for_chicken_posts(): void {
		$archive = new Chicken_Archive();
		$chicken = get_post( $this->chicken( 'Json hen' ) );
		$note    = get_post( $this->note( 'Json note' ) );

		$chicken_item = $archive->strip_json_feed_geo( [ 'title' => 'x', 'geo' => [ 'latitude' => 1 ] ], $chicken );
		$note_item    = $archive->strip_json_feed_geo( [ 'title' => 'x', 'geo' => [ 'latitude' => 1 ] ], $note );

		$this->assertArrayNotHasKey( 'geo', $chicken_item );
		$this->assertSame( 'x', $chicken_item['title'] );
		$this->assertArrayHasKey( 'geo', $note_item );
	}

	public function test_registered_json_feed_filter_strips_geo_only_for_chicken_posts(): void {
		$this->add_probe(
			'json_feed_item',
			static function ( array $item ): array {
				$item['geo'] = [ 'latitude' => 1 ];
				return $item;
			},
			10
		);
		$chicken = get_post( $this->chicken( 'Registered json hen' ) );
		$note    = get_post( $this->note( 'Registered json note' ) );

		$chicken_item = apply_filters( 'json_feed_item', [ 'title' => 'x' ], $chicken );
		$note_item    = apply_filters( 'json_feed_item', [ 'title' => 'x' ], $note );

		$this->assertArrayNotHasKey( 'geo', $chicken_item );
		$this->assertSame( 'x', $chicken_item['title'] );
		$this->assertArrayHasKey( 'geo', $note_item );
	}

	public function test_registered_template_redirect_hook_removes_head_meta_only_for_chicken_singles(): void {
		$this->register_simple_location_callbacks();
		$chicken = $this->chicken( 'Registered head hen' );
		$note    = $this->note( 'Registered head note' );

		$this->go_to( get_permalink( $chicken ) );
		do_action( 'template_redirect' );
		$this->assertFalse( has_filter( 'wp_head', [ 'Geo_Base', 'meta_tags' ] ) );

		add_action( 'wp_head', [ 'Geo_Base', 'meta_tags' ], 10 );
		$this->go_to( get_permalink( $note ) );
		do_action( 'template_redirect' );
		$this->assertSame( 10, has_filter( 'wp_head', [ 'Geo_Base', 'meta_tags' ] ) );
	}

	public function test_head_meta_is_removed_only_for_chicken_singles(): void {
		$this->register_simple_location_callbacks();
		$archive = new Chicken_Archive();
		$chicken = $this->chicken( 'Head hen' );
		$note    = $this->note( 'Head note' );

		$this->go_to( get_permalink( $chicken ) );
		$archive->suppress_head_meta();
		$this->assertFalse( has_filter( 'wp_head', [ 'Geo_Base', 'meta_tags' ] ) );

		add_action( 'wp_head', [ 'Geo_Base', 'meta_tags' ], 10 );
		$this->go_to( get_permalink( $note ) );
		$archive->suppress_head_meta();
		$this->assertSame( 10, has_filter( 'wp_head', [ 'Geo_Base', 'meta_tags' ] ) );

		$this->go_to( get_term_link( 'chicken', Taxonomy::TAXONOMY ) );
		$archive->suppress_head_meta();
		$this->assertSame( 10, has_filter( 'wp_head', [ 'Geo_Base', 'meta_tags' ] ) );
	}

	public function test_rest_location_fields_are_stripped_for_chicken_view_context_only(): void {
		$chicken = get_post( $this->chicken( 'Rest hen' ) );
		$note    = get_post( $this->note( 'Rest note' ) );
		$view    = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $chicken->ID );
		$view->set_param( 'context', 'view' );
		$edit = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $chicken->ID );
		$edit->set_param( 'context', 'edit' );

		$response = apply_filters( 'rest_prepare_post', $this->location_response( $chicken->ID ), $chicken, $view );
		$data     = $response->get_data();
		foreach ( [ 'latitude', 'longitude', 'geo_address', 'timezone' ] as $key ) {
			$this->assertArrayNotHasKey( $key, $data );
		}
		$this->assertSame( $chicken->ID, $data['id'] );

		$edit_data = apply_filters( 'rest_prepare_post', $this->location_response( $chicken->ID ), $chicken, $edit )->get_data();
		$this->assertArrayHasKey( 'latitude', $edit_data );
		$this->assertArrayHasKey( 'timezone', $edit_data );

		$note_data = apply_filters( 'rest_prepare_post', $this->location_response( $note->ID ), $note, $view )->get_data();
		$this->assertArrayHasKey( 'latitude', $note_data );
		$this->assertArrayHasKey( 'timezone', $note_data );
	}

	public function test_rest_date_and_modified_of_a_chicken_drop_the_utc_offset_a_priority_ten_filter_wrote(): void {
		$chicken = get_post( $this->chicken( 'Offset hen' ) );
		$note    = get_post( $this->note( 'Offset note' ) );
		$view    = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $chicken->ID );
		$view->set_param( 'context', 'view' );
		$edit = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $chicken->ID );
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

		$data = apply_filters( 'rest_prepare_post', $this->location_response( $chicken->ID ), $chicken, $view )->get_data();
		$this->assertSame( mysql_to_rfc3339( $chicken->post_date ), $data['date'] );
		$this->assertSame( mysql_to_rfc3339( $chicken->post_modified ), $data['modified'] );
		$this->assertStringNotContainsString( '+09:00', $data['date'] );
		$this->assertStringNotContainsString( '+09:00', $data['modified'] );
		$this->assertSame( $chicken->ID, $data['id'] );

		$edit_data = apply_filters( 'rest_prepare_post', $this->location_response( $chicken->ID ), $chicken, $edit )->get_data();
		$this->assertSame( $tokyo_date, $edit_data['date'] );
		$this->assertSame( $tokyo_modified, $edit_data['modified'] );

		$note_data = apply_filters( 'rest_prepare_post', $this->location_response( $note->ID ), $note, $view )->get_data();
		$this->assertSame( $tokyo_date, $note_data['date'] );
		$this->assertSame( $tokyo_modified, $note_data['modified'] );
	}

	public function test_rest_fields_filter_keeps_absent_date_keys_absent_and_null_dates_null(): void {
		$chicken = get_post( $this->chicken( 'Fields hen' ) );
		$view    = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $chicken->ID );
		$view->set_param( 'context', 'view' );

		$trimmed = apply_filters( 'rest_prepare_post', new WP_REST_Response( [ 'id' => $chicken->ID, 'title' => [ 'rendered' => 'x' ] ] ), $chicken, $view )->get_data();
		$this->assertArrayNotHasKey( 'date', $trimmed );
		$this->assertArrayNotHasKey( 'modified', $trimmed );

		$draft = apply_filters( 'rest_prepare_post', new WP_REST_Response( [ 'id' => $chicken->ID, 'date' => null, 'modified' => null ] ), $chicken, $view )->get_data();
		$this->assertArrayHasKey( 'date', $draft );
		$this->assertNull( $draft['date'] );
		$this->assertNull( $draft['modified'] );
	}

	public function test_no_simple_location_callbacks_registered_keeps_all_hooks_empty(): void {
		$chicken = $this->chicken( 'Empty hooks' );
		$this->set_global_post( $chicken );

		apply_filters( 'the_content', 'body' );
		do_action( 'rss2_item' );
		( new Chicken_Archive() )->suppress_head_meta();

		$this->assertFalse( has_filter( 'the_content', [ 'Geo_Data', 'content_map' ] ) );
		$this->assertFalse( has_filter( 'the_content', [ 'Geo_Data', 'location_content' ] ) );
		$this->assertFalse( has_filter( 'rss2_item', [ 'Geo_Base', 'georss_item' ] ) );
		$this->assertFalse( has_filter( 'wp_head', [ 'Geo_Base', 'meta_tags' ] ) );
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

	private function chicken( string $title ): int {
		$id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => $title, 'post_date' => '2026-10-10 10:00:00' ] );
		wp_set_object_terms( $id, 'chicken', Taxonomy::TAXONOMY );

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
