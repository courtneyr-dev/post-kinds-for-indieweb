<?php
/**
 * Simple Location privacy gate for weather posts (issue 246).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Integrations\Simple_Location_Weather;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * Simple Location's map, location line, GeoRSS, JSON Feed geo and head meta
 * are held back from a weather post for the render pass that needs it, then
 * restored, and only what this class removed is restored. The classes
 * Simple Location owns are never defined here: the callables are strings,
 * and a callable that runs would fail the test.
 *
 * @group integration
 */
final class WeatherLocationGateTest extends WP_UnitTestCase {

	/**
	 * Hook => priority for the content and head callables this test registers.
	 *
	 * @var array<string, array{0:string,1:string,2:int}>
	 */
	private const CALLABLES = [
		'content_map'      => [ 'the_content', 'Geo_Data::content_map', 11 ],
		'location_content' => [ 'the_content', 'Geo_Data::location_content', 12 ],
		'meta_tags'        => [ 'wp_head', 'Geo_Base::meta_tags', 10 ],
	];

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		Simple_Location_Weather::reset();
		wp_set_current_user( 0 );
		update_option( 'geo_public', '0' );
	}

	public function tear_down(): void {
		$this->unregister_simple_location_callables();
		Simple_Location_Weather::reset();
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Register Simple Location's string callables without defining their classes.
	 */
	private function register_simple_location_callables(): void {
		foreach ( self::CALLABLES as [ $hook, $callable, $priority ] ) {
			add_filter( $hook, $callable, $priority );
		}
		foreach ( [ 'rss_item', 'rss2_item', 'atom_entry', 'rdf_item' ] as $hook ) {
			add_action( $hook, 'Geo_Base::georss_item', 10 );
		}
	}

	/**
	 * Remove every string callable this test registers.
	 */
	private function unregister_simple_location_callables(): void {
		foreach ( self::CALLABLES as [ $hook, $callable, $priority ] ) {
			remove_filter( $hook, $callable, $priority );
		}
		foreach ( [ 'rss_item', 'rss2_item', 'atom_entry', 'rdf_item' ] as $hook ) {
			remove_action( $hook, 'Geo_Base::georss_item', 10 );
		}
	}

	/**
	 * A published post of a kind.
	 *
	 * @param string               $kind Kind slug.
	 * @param array<string, mixed> $args Post arguments.
	 */
	private function post_of_kind( string $kind, array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Sample post',
					'post_content' => '<p>Out for a walk.</p>',
				],
				$args
			)
		);
		wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY );

		return (int) $id;
	}

	/**
	 * A weather post with a privacy tier and a Simple Location visibility.
	 *
	 * @param string|null          $privacy Post Kinds tier, or null for none.
	 * @param string               $geo     Simple Location geo_public, '' for none.
	 * @param array<string, mixed> $args    Post arguments.
	 */
	private function weather( ?string $privacy = null, string $geo = '1', array $args = [] ): int {
		$id = $this->post_of_kind( 'weather', $args );
		if ( null !== $privacy ) {
			update_post_meta( $id, Meta_Fields::PREFIX . 'geo_privacy', $privacy );
		}
		if ( '' !== $geo ) {
			update_post_meta( $id, 'geo_public', $geo );
		}

		return $id;
	}

	/**
	 * Make a post the current post.
	 *
	 * @param int $id Post ID.
	 */
	private function current_post( int $id ): void {
		$GLOBALS['post'] = get_post( $id );
		setup_postdata( $GLOBALS['post'] );
	}

	/**
	 * How many callbacks at a hook priority belong to Simple Location's classes.
	 *
	 * @param string $hook     Hook name.
	 * @param int    $priority Priority.
	 */
	private function callback_count( string $hook, int $priority ): int {
		$callbacks = $GLOBALS['wp_filter'][ $hook ]->callbacks[ $priority ] ?? [];

		return count( array_filter( array_keys( $callbacks ), static fn( $key ): bool => str_contains( (string) $key, 'Geo_' ) ) );
	}

	/**
	 * Both the_content callables are registered at their own priorities.
	 */
	private function assertContentCallablesRegistered( string $message = '' ): void {
		$this->assertSame( 11, has_filter( 'the_content', 'Geo_Data::content_map' ), $message );
		$this->assertSame( 12, has_filter( 'the_content', 'Geo_Data::location_content' ), $message );
		$this->assertSame( 1, $this->callback_count( 'the_content', 11 ), $message );
		$this->assertSame( 1, $this->callback_count( 'the_content', 12 ), $message );
	}

	/**
	 * Run a callback at the_content 11, where the gate has already acted, and
	 * record whether either callable is registered then.
	 *
	 * @param array<int, array{content_map:bool,location_content:bool}> $seen Filled as passes run.
	 * @return callable The recorder, so a test can remove it.
	 */
	private function record_passes( array &$seen ): callable {
		$recorder = static function ( $content ) use ( &$seen ) {
			$seen[] = [
				'content_map'      => false !== has_filter( 'the_content', 'Geo_Data::content_map' ),
				'location_content' => false !== has_filter( 'the_content', 'Geo_Data::location_content' ),
			];

			return $content;
		};
		add_filter( 'the_content', $recorder, 11 );

		return $recorder;
	}

	public function test_the_gate_is_wired_to_the_right_hooks_and_priorities(): void {
		$this->assertSame( 10, has_filter( 'the_content', [ Simple_Location_Weather::class, 'gate_content' ] ) );
		$this->assertSame( 13, has_filter( 'the_content', [ Simple_Location_Weather::class, 'restore_content' ] ) );
		foreach ( [ 'rss_item', 'rss2_item', 'atom_entry', 'rdf_item' ] as $hook ) {
			$this->assertSame( 9, has_action( $hook, [ Simple_Location_Weather::class, 'gate_feed_item' ] ), $hook );
			$this->assertSame( 11, has_action( $hook, [ Simple_Location_Weather::class, 'restore_feed_item' ] ), $hook );
		}
		$this->assertSame( 11, has_filter( 'json_feed_item', [ Simple_Location_Weather::class, 'gate_json_feed_item' ] ) );
		$this->assertSame( 10, has_action( 'template_redirect', [ Simple_Location_Weather::class, 'gate_head' ] ) );
	}

	public function test_a_weather_content_pass_removes_both_callables_and_puts_them_back(): void {
		$this->register_simple_location_callables();
		$this->current_post( $this->weather() );
		$seen = [];
		$this->record_passes( $seen );

		$html = apply_filters( 'the_content', '<p>Hello</p>' );

		$this->assertStringContainsString( 'Hello', $html );
		$this->assertSame( [ [ 'content_map' => false, 'location_content' => false ] ], $seen, 'Neither callable is registered while the pass runs.' );
		$this->assertContentCallablesRegistered( 'Both are back, once each, after the pass.' );
	}

	public function test_a_weather_post_is_gated_whatever_its_privacy(): void {
		$this->register_simple_location_callables();
		$seen = [];
		$this->record_passes( $seen );

		foreach ( [ null, 'approximate', 'private', 'public' ] as $privacy ) {
			$this->current_post( $this->weather( $privacy ) );
			apply_filters( 'the_content', '<p>Hello</p>' );
		}

		$this->assertSame( array_fill( 0, 4, [ 'content_map' => false, 'location_content' => false ] ), $seen );
		$this->assertContentCallablesRegistered();
	}

	public function test_a_protected_weather_post_is_gated_too(): void {
		$this->register_simple_location_callables();
		$this->current_post( $this->weather( 'public', '1', [ 'post_password' => 'secret' ] ) );
		$seen = [];
		$this->record_passes( $seen );

		apply_filters( 'the_content', '<p>Hello</p>' );

		$this->assertSame( [ [ 'content_map' => false, 'location_content' => false ] ], $seen );
		$this->assertContentCallablesRegistered();
	}

	public function test_an_excerpt_pass_is_gated(): void {
		$this->register_simple_location_callables();
		$post_id = $this->weather( 'public', '1', [ 'post_excerpt' => '' ] );
		$this->current_post( $post_id );
		$seen = [];
		$this->record_passes( $seen );

		get_the_excerpt( $post_id );

		$this->assertNotSame( [], $seen, 'The excerpt ran the_content.' );
		foreach ( $seen as $pass ) {
			$this->assertSame( [ 'content_map' => false, 'location_content' => false ], $pass );
		}
		$this->assertContentCallablesRegistered();
	}

	public function test_a_rest_content_pass_is_gated(): void {
		$this->register_simple_location_callables();
		$post_id = $this->weather( 'public' );
		$seen    = [];
		$this->record_passes( $seen );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'Out for a walk', (string) $response->get_data()['content']['rendered'] );
		$this->assertNotSame( [], $seen );
		foreach ( $seen as $pass ) {
			$this->assertSame( [ 'content_map' => false, 'location_content' => false ], $pass );
		}
		$this->assertContentCallablesRegistered();
	}

	public function test_an_atproto_preview_pass_is_gated(): void {
		$this->register_simple_location_callables();
		$this->current_post( $this->weather( 'public' ) );
		set_query_var( 'atproto', '1' );
		$seen = [];
		$this->record_passes( $seen );

		apply_filters( 'the_content', '<p>Hello</p>' );

		$this->assertSame( [ [ 'content_map' => false, 'location_content' => false ] ], $seen );
		$this->assertContentCallablesRegistered();
		set_query_var( 'atproto', null );
	}

	public function test_a_note_pass_removes_nothing(): void {
		$this->register_simple_location_callables();
		$this->current_post( $this->post_of_kind( 'note' ) );

		Simple_Location_Weather::gate_content( 'x' );
		$this->assertContentCallablesRegistered( 'Nothing removed during a note pass.' );
		Simple_Location_Weather::restore_content( 'x' );

		$this->assertContentCallablesRegistered( 'And nothing added twice.' );
	}

	public function test_a_nested_note_pass_inside_a_weather_pass_leaves_the_outer_list_intact(): void {
		$this->register_simple_location_callables();
		$note_id    = $this->post_of_kind( 'note' );
		$weather_id = $this->weather( 'public' );
		$this->current_post( $weather_id );
		$inside = [];
		$ran    = false;
		$nested = static function ( $content ) use ( &$inside, &$ran, $note_id, $weather_id ) {
			if ( $ran ) {
				return $content;
			}
			$ran             = true;
			$GLOBALS['post'] = get_post( $note_id );
			setup_postdata( $GLOBALS['post'] );
			apply_filters( 'the_content', '<p>Inner</p>' );
			$inside[] = [
				'content_map'      => false !== has_filter( 'the_content', 'Geo_Data::content_map' ),
				'location_content' => false !== has_filter( 'the_content', 'Geo_Data::location_content' ),
			];
			$GLOBALS['post'] = get_post( $weather_id );
			setup_postdata( $GLOBALS['post'] );

			return $content;
		};
		add_filter( 'the_content', $nested, 11 );

		apply_filters( 'the_content', '<p>Outer</p>' );

		$this->assertSame( [ [ 'content_map' => false, 'location_content' => false ] ], $inside, 'The inner pass did not hand the outer one\'s callables back early.' );
		$this->assertContentCallablesRegistered( 'The outer list survived the inner pass and restored both.' );
	}

	public function test_a_nested_weather_pass_inside_a_weather_pass_restores_once(): void {
		$this->register_simple_location_callables();
		$inner_id = $this->weather( 'public' );
		$outer_id = $this->weather( 'public' );
		$this->current_post( $outer_id );
		$ran    = false;
		$nested = static function ( $content ) use ( &$ran, $inner_id, $outer_id ) {
			if ( $ran ) {
				return $content;
			}
			$ran             = true;
			$GLOBALS['post'] = get_post( $inner_id );
			setup_postdata( $GLOBALS['post'] );
			apply_filters( 'the_content', '<p>Inner</p>' );
			$GLOBALS['post'] = get_post( $outer_id );
			setup_postdata( $GLOBALS['post'] );

			return $content;
		};
		add_filter( 'the_content', $nested, 11 );

		apply_filters( 'the_content', '<p>Outer</p>' );

		$this->assertContentCallablesRegistered();
	}

	public function test_nothing_is_registered_after_a_pass_when_nothing_was_registered_before(): void {
		$post_id = $this->weather( 'approximate' );
		$this->go_to( (string) get_permalink( $post_id ) );
		$this->assertTrue( is_singular() );
		$this->current_post( $post_id );

		apply_filters( 'the_content', '<p>Hello</p>' );
		Simple_Location_Weather::gate_head();
		$GLOBALS['wp_current_filter'][] = 'rss2_item';
		Simple_Location_Weather::gate_feed_item();
		Simple_Location_Weather::restore_feed_item();
		array_pop( $GLOBALS['wp_current_filter'] );

		$this->assertFalse( has_filter( 'the_content', 'Geo_Data::content_map' ) );
		$this->assertFalse( has_filter( 'the_content', 'Geo_Data::location_content' ) );
		$this->assertFalse( has_action( 'wp_head', 'Geo_Base::meta_tags' ) );
		foreach ( [ 'rss_item', 'rss2_item', 'atom_entry', 'rdf_item' ] as $hook ) {
			$this->assertFalse( has_action( $hook, 'Geo_Base::georss_item' ), $hook );
		}
	}

	public function test_an_unbalanced_restore_does_nothing(): void {
		$this->register_simple_location_callables();

		$this->assertSame( 'x', Simple_Location_Weather::restore_content( 'x' ) );
		$this->assertContentCallablesRegistered();
	}

	/**
	 * Run one feed item through the gate and report whether georss_item was registered meanwhile.
	 *
	 * @param string $hook Feed hook.
	 * @param int    $id   Post ID that is the current post.
	 * @return array{during:bool, after:bool}
	 */
	private function feed_item( string $hook, int $id ): array {
		$this->current_post( $id );
		$GLOBALS['wp_current_filter'][] = $hook;
		try {
			Simple_Location_Weather::gate_feed_item();
			$during = false !== has_action( $hook, 'Geo_Base::georss_item' );
			Simple_Location_Weather::restore_feed_item();
			$after = 10 === has_action( $hook, 'Geo_Base::georss_item' );
		} finally {
			array_pop( $GLOBALS['wp_current_filter'] );
		}

		return [
			'during' => $during,
			'after'  => $after,
		];
	}

	public function test_georss_is_absent_during_each_weather_feed_item_and_present_for_the_note_and_after(): void {
		$this->register_simple_location_callables();
		$weather_a = $this->weather( 'approximate' );
		$note      = $this->post_of_kind( 'note' );
		$weather_b = $this->weather( null );

		foreach ( [ 'rss_item', 'rss2_item', 'atom_entry', 'rdf_item' ] as $hook ) {
			$this->assertSame( [ 'during' => false, 'after' => true ], $this->feed_item( $hook, $weather_a ), $hook . ' weather' );
			$this->assertSame( [ 'during' => true, 'after' => true ], $this->feed_item( $hook, $note ), $hook . ' note' );
			$this->assertSame( [ 'during' => false, 'after' => true ], $this->feed_item( $hook, $weather_b ), $hook . ' weather again' );
			$this->assertSame( 10, has_action( $hook, 'Geo_Base::georss_item' ), $hook . ' after the loop' );
			$this->assertSame( 1, $this->callback_count( $hook, 10 ), $hook . ' registered once' );
		}
	}

	public function test_georss_stays_for_a_weather_item_that_keeps_its_geo(): void {
		$this->register_simple_location_callables();
		$public = $this->weather( 'public', '1' );

		$this->assertSame( [ 'during' => true, 'after' => true ], $this->feed_item( 'rss2_item', $public ) );
	}

	public function test_georss_is_removed_for_a_protected_weather_item_even_with_a_public_tier(): void {
		$this->register_simple_location_callables();
		$protected = $this->weather( 'public', '1', [ 'post_password' => 'secret' ] );

		$this->assertSame( [ 'during' => false, 'after' => true ], $this->feed_item( 'rss2_item', $protected ) );
	}

	public function test_a_nested_feed_pass_restores_in_order(): void {
		$this->register_simple_location_callables();
		$outer = $this->weather( 'approximate' );
		$inner = $this->weather( 'approximate' );
		$this->current_post( $outer );
		$GLOBALS['wp_current_filter'][] = 'rss2_item';
		try {
			Simple_Location_Weather::gate_feed_item();
			$this->current_post( $inner );
			Simple_Location_Weather::gate_feed_item();
			Simple_Location_Weather::restore_feed_item();
			$this->assertFalse( has_action( 'rss2_item', 'Geo_Base::georss_item' ), 'Still removed for the outer item.' );
			Simple_Location_Weather::restore_feed_item();
		} finally {
			array_pop( $GLOBALS['wp_current_filter'] );
		}

		$this->assertSame( 10, has_action( 'rss2_item', 'Geo_Base::georss_item' ) );
	}

	public function test_json_feed_geo_is_unset_for_a_weather_item_unless_it_keeps_its_geo(): void {
		$item    = [
			'id'  => 'x',
			'geo' => [ 'latitude' => 10.1 ],
		];
		$hidden  = get_post( $this->weather( 'approximate' ) );
		$private = get_post( $this->weather( 'private' ) );
		$kept    = get_post( $this->weather( 'public', '1' ) );
		$note    = get_post( $this->post_of_kind( 'note' ) );

		$this->assertArrayNotHasKey( 'geo', apply_filters( 'json_feed_item', $item, $hidden ) );
		$this->assertArrayNotHasKey( 'geo', apply_filters( 'json_feed_item', $item, $private ) );
		$this->assertArrayHasKey( 'geo', apply_filters( 'json_feed_item', $item, $kept ) );
		$this->assertArrayHasKey( 'geo', apply_filters( 'json_feed_item', $item, $note ) );
		$this->assertSame( 'x', apply_filters( 'json_feed_item', $item, $hidden )['id'], 'The rest of the item is untouched.' );
	}

	public function test_json_feed_geo_is_unset_for_a_protected_weather_item(): void {
		$item      = [ 'geo' => [ 'latitude' => 10.1 ] ];
		$protected = get_post( $this->weather( 'public', '1', [ 'post_password' => 'secret' ] ) );

		$this->assertArrayNotHasKey( 'geo', apply_filters( 'json_feed_item', $item, $protected ) );
	}

	/**
	 * @return array<string, array{0:string|null,1:string,2:bool}>
	 */
	public function keep_geo_cases(): array {
		return [
			'unset tier'              => [ null, '1', false ],
			'approximate'             => [ 'approximate', '1', false ],
			'private'                 => [ 'private', '1', false ],
			'public, simple location' => [ 'public', '1', true ],
			'public, no geo_public'   => [ 'public', '', true ],
			'public, protected'       => [ 'public', '2', false ],
			'public, private sloc'    => [ 'public', '0', false ],
		];
	}

	/**
	 * @dataProvider keep_geo_cases
	 *
	 * @param string|null $privacy  Post Kinds tier.
	 * @param string      $geo      Simple Location visibility.
	 * @param bool        $expected Whether the geo is kept.
	 */
	public function test_keep_geo_needs_street_and_coordinates_both_public( ?string $privacy, string $geo, bool $expected ): void {
		$post = get_post( $this->weather( $privacy, $geo ) );

		$this->assertSame( $expected, Simple_Location_Weather::keep_geo( $post ) );
	}

	public function test_keep_geo_is_false_for_any_password_even_a_blank_looking_one(): void {
		$locked = get_post( $this->weather( 'public', '1', [ 'post_password' => 'secret' ] ) );
		$spaces = get_post( $this->weather( 'public', '1', [ 'post_password' => ' ' ] ) );

		$this->assertFalse( Simple_Location_Weather::keep_geo( $locked ) );
		$this->assertFalse( Simple_Location_Weather::keep_geo( $spaces ) );
	}

	/**
	 * Run the head gate on a post's single and say whether meta_tags is still hooked.
	 *
	 * @param int $post_id Post ID.
	 */
	private function head_keeps_meta_tags( int $post_id ): bool {
		$this->register_simple_location_callables();
		$this->go_to( (string) get_permalink( $post_id ) );
		Simple_Location_Weather::gate_head();
		$kept = false !== has_action( 'wp_head', 'Geo_Base::meta_tags' );
		$this->unregister_simple_location_callables();

		return $kept;
	}

	public function test_head_meta_is_removed_on_unset_approximate_private_and_protected_weather_singles(): void {
		$this->assertFalse( $this->head_keeps_meta_tags( $this->weather( null ) ), 'unset' );
		$this->assertFalse( $this->head_keeps_meta_tags( $this->weather( 'approximate' ) ), 'approximate' );
		$this->assertFalse( $this->head_keeps_meta_tags( $this->weather( 'private' ) ), 'private' );
		$this->assertFalse( $this->head_keeps_meta_tags( $this->weather( 'public', '1', [ 'post_password' => 'secret' ] ) ), 'protected' );
	}

	public function test_head_meta_stays_on_a_public_weather_single_and_a_note_single(): void {
		$this->assertTrue( $this->head_keeps_meta_tags( $this->weather( 'public', '1' ) ), 'public weather' );
		$this->assertTrue( $this->head_keeps_meta_tags( $this->post_of_kind( 'note' ) ), 'note' );
	}

	public function test_head_meta_is_left_alone_off_a_single(): void {
		$this->register_simple_location_callables();
		$this->go_to( home_url( '/' ) );

		Simple_Location_Weather::gate_head();

		$this->assertSame( 10, has_action( 'wp_head', 'Geo_Base::meta_tags' ) );
	}
}
