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
		remove_filter( 'the_content', 'Geo_Data::content_map', 11 );
		remove_filter( 'the_content', 'Geo_Data::location_content', 12 );
		foreach ( [ 'rss_item', 'rss2_item', 'atom_entry', 'rdf_item' ] as $hook ) {
			remove_filter( $hook, 'Geo_Base::georss_item', 10 );
		}
		remove_filter( 'json_feed_item', 'Geo_Base::json_feed_item', 10 );
		remove_filter( 'wp_head', 'Geo_Base::meta_tags', 10 );
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
		$this->assertSame( 11, has_filter( 'the_content', 'Geo_Data::content_map' ) );
		$this->assertSame( 12, has_filter( 'the_content', 'Geo_Data::location_content' ) );
		$this->assertSame( 'x', $archive->restore_content( 'x' ) );
		$this->assertSame( 11, has_filter( 'the_content', 'Geo_Data::content_map' ) );
		$this->assertSame( 12, has_filter( 'the_content', 'Geo_Data::location_content' ) );
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
					has_filter( 'the_content', 'Geo_Data::content_map' ),
					has_filter( 'the_content', 'Geo_Data::location_content' ),
				];
				return $content;
			},
			12
		);

		$this->assertStringContainsString( 'body', apply_filters( 'the_content', 'body' ) );

		$this->assertSame( [ [ false, false ] ], $seen );
		$this->assertSame( 11, has_filter( 'the_content', 'Geo_Data::content_map' ) );
		$this->assertSame( 12, has_filter( 'the_content', 'Geo_Data::location_content' ) );
	}

	public function test_chicken_content_pass_with_none_registered_adds_none(): void {
		$chicken = $this->chicken( 'No callbacks' );
		$this->set_global_post( $chicken );

		$this->assertStringContainsString( 'body', apply_filters( 'the_content', 'body' ) );

		$this->assertFalse( has_filter( 'the_content', 'Geo_Data::content_map' ) );
		$this->assertFalse( has_filter( 'the_content', 'Geo_Data::location_content' ) );
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

		$this->assertSame( 11, has_filter( 'the_content', 'Geo_Data::content_map' ) );
		$this->assertSame( 12, has_filter( 'the_content', 'Geo_Data::location_content' ) );
	}

	public function test_feed_callbacks_are_removed_for_chicken_items_and_not_for_notes(): void {
		$this->register_simple_location_callbacks();
		$chicken = $this->chicken( 'Feed hen' );
		$note    = $this->note( 'Feed note' );
		$seen    = [];
		$this->add_probe(
			'rss2_item',
			function () use ( &$seen ): void {
				$seen[] = has_filter( 'rss2_item', 'Geo_Base::georss_item' );
			},
			10
		);
		$this->set_global_post( $chicken );
		do_action( 'rss2_item' );

		$this->assertSame( [ false ], $seen );
		$this->assertSame( 10, has_filter( 'rss2_item', 'Geo_Base::georss_item' ) );

		$archive = new Chicken_Archive();
		$this->set_global_post( $note );
		$this->with_current_filter(
			'rss2_item',
			function () use ( $archive ): void {
				$archive->gate_feed_item();
				$this->assertSame( 10, has_filter( 'rss2_item', 'Geo_Base::georss_item' ) );
				$archive->restore_feed_item();
				$this->assertSame( 10, has_filter( 'rss2_item', 'Geo_Base::georss_item' ) );
			}
		);

		foreach ( [ 'atom_entry', 'rdf_item', 'rss_item' ] as $hook ) {
			$this->assertSame( 10, has_filter( $hook, 'Geo_Base::georss_item' ) );
		}
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
		$this->assertFalse( has_filter( 'wp_head', 'Geo_Base::meta_tags' ) );

		add_action( 'wp_head', 'Geo_Base::meta_tags', 10 );
		$this->go_to( get_permalink( $note ) );
		do_action( 'template_redirect' );
		$this->assertSame( 10, has_filter( 'wp_head', 'Geo_Base::meta_tags' ) );
	}

	public function test_head_meta_is_removed_only_for_chicken_singles(): void {
		$this->register_simple_location_callbacks();
		$archive = new Chicken_Archive();
		$chicken = $this->chicken( 'Head hen' );
		$note    = $this->note( 'Head note' );

		$this->go_to( get_permalink( $chicken ) );
		$archive->suppress_head_meta();
		$this->assertFalse( has_filter( 'wp_head', 'Geo_Base::meta_tags' ) );

		add_action( 'wp_head', 'Geo_Base::meta_tags', 10 );
		$this->go_to( get_permalink( $note ) );
		$archive->suppress_head_meta();
		$this->assertSame( 10, has_filter( 'wp_head', 'Geo_Base::meta_tags' ) );

		$this->go_to( get_term_link( 'chicken', Taxonomy::TAXONOMY ) );
		$archive->suppress_head_meta();
		$this->assertSame( 10, has_filter( 'wp_head', 'Geo_Base::meta_tags' ) );
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

	public function test_no_simple_location_callbacks_registered_keeps_all_hooks_empty(): void {
		$chicken = $this->chicken( 'Empty hooks' );
		$this->set_global_post( $chicken );

		apply_filters( 'the_content', 'body' );
		do_action( 'rss2_item' );
		( new Chicken_Archive() )->suppress_head_meta();

		$this->assertFalse( has_filter( 'the_content', 'Geo_Data::content_map' ) );
		$this->assertFalse( has_filter( 'the_content', 'Geo_Data::location_content' ) );
		$this->assertFalse( has_filter( 'rss2_item', 'Geo_Base::georss_item' ) );
		$this->assertFalse( has_filter( 'wp_head', 'Geo_Base::meta_tags' ) );
	}

	private function register_simple_location_callbacks(): void {
		add_filter( 'the_content', 'Geo_Data::content_map', 11 );
		add_filter( 'the_content', 'Geo_Data::location_content', 12 );
		foreach ( [ 'rss_item', 'rss2_item', 'atom_entry', 'rdf_item' ] as $hook ) {
			add_action( $hook, 'Geo_Base::georss_item', 10 );
		}
		add_filter( 'json_feed_item', 'Geo_Base::json_feed_item', 10 );
		add_action( 'wp_head', 'Geo_Base::meta_tags', 10 );

		$this->assertSame( 11, has_filter( 'the_content', 'Geo_Data::content_map' ) );
		$this->assertSame( 12, has_filter( 'the_content', 'Geo_Data::location_content' ) );
		$this->assertSame( 10, has_filter( 'rss2_item', 'Geo_Base::georss_item' ) );
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
				'title'       => [ 'rendered' => 'x' ],
			]
		);
	}
}
