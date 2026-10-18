<?php
/**
 * Photo Simple Location privacy gate tests for issue 241.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Meta_Fields;
use PKIW\Photo_Location;
use PKIW\Taxonomy;

/**
 * Photo location output is removed only for each render pass that needs it.
 *
 * @group integration
 */
final class PhotoLocationTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		Photo_Location::reset();
	}

	public function tear_down(): void {
		$this->unregister_simple_location_callables();
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
		wp_set_current_user( 0 );
		Photo_Location::reset();
		parent::tear_down();
	}

	/**
	 * A location gate instance.
	 */
	private function location(): Photo_Location {
		return new Photo_Location();
	}

	/**
	 * Register string callables without defining their classes.
	 */
	private function register_simple_location_callables(): void {
		add_filter( 'the_content', 'Geo_Data::content_map', 11 );
		add_filter( 'the_content', 'Geo_Data::location_content', 12 );
		add_action( 'wp_head', 'Geo_Base::meta_tags', 10 );
		foreach ( [ 'rss_item', 'rss2_item', 'atom_entry', 'rdf_item' ] as $hook ) {
			add_action( $hook, 'Geo_Base::georss_item', 10 );
		}
		add_filter( 'json_feed_item', 'Geo_Base::json_feed_item', 10, 2 );
	}

	/**
	 * Remove all string callables this test registers.
	 */
	private function unregister_simple_location_callables(): void {
		remove_filter( 'the_content', 'Geo_Data::content_map', 11 );
		remove_filter( 'the_content', 'Geo_Data::location_content', 12 );
		remove_action( 'wp_head', 'Geo_Base::meta_tags', 10 );
		foreach ( [ 'rss_item', 'rss2_item', 'atom_entry', 'rdf_item' ] as $hook ) {
			remove_action( $hook, 'Geo_Base::georss_item', 10 );
		}
		remove_filter( 'json_feed_item', 'Geo_Base::json_feed_item', 10 );
	}

	/**
	 * A post assigned to a kind.
	 *
	 * @param string               $kind Kind slug.
	 * @param array<string, mixed> $args Post arguments.
	 * @return int Post ID.
	 */
	private function post_of_kind( string $kind, array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status' => 'publish',
					'post_title'  => 'Sample post',
				],
				$args
			)
		);
		wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY );

		return (int) $id;
	}

	/**
	 * A photo post with optional privacy.
	 *
	 * @param string|null          $privacy Post Kinds privacy.
	 * @param string               $geo     Simple Location geo_public.
	 * @param array<string, mixed> $args    Extra post arguments.
	 * @return int Post ID.
	 */
	private function photo( ?string $privacy = null, string $geo = '1', array $args = [] ): int {
		return $this->located( 'photo', $privacy, $geo, $args );
	}

	/**
	 * A post of any kind with optional privacy.
	 *
	 * @param string               $kind    Kind slug.
	 * @param string|null          $privacy Post Kinds privacy.
	 * @param string               $geo     Simple Location geo_public.
	 * @param array<string, mixed> $args    Extra post arguments.
	 * @return int Post ID.
	 */
	private function located( string $kind, ?string $privacy = null, string $geo = '1', array $args = [] ): int {
		$id = $this->post_of_kind( $kind, $args );
		if ( 'photo' !== $kind ) {
			// Non-photo posts are gated only when they store a location (issue 422).
			update_post_meta( $id, 'geo_address', '100 Example Avenue, Sampletown' );
			update_post_meta( $id, 'geo_latitude', '0.1' );
			update_post_meta( $id, 'geo_longitude', '0.1' );
		}
		if ( null !== $privacy ) {
			update_post_meta( $id, Meta_Fields::PREFIX . 'geo_privacy', $privacy );
		}
		if ( '' !== $geo ) {
			update_post_meta( $id, 'geo_public', $geo );
		}

		return $id;
	}

	/**
	 * Make a post current.
	 *
	 * @param int $id Post ID.
	 */
	private function current_post( int $id ): void {
		$GLOBALS['post'] = get_post( $id );
		setup_postdata( $GLOBALS['post'] );
	}

	/**
	 * Count callbacks at a hook priority.
	 *
	 * @param string $hook     Hook name.
	 * @param int    $priority Priority.
	 * @return int Callback count.
	 */
	private function callback_count( string $hook, int $priority ): int {
		$callbacks = $GLOBALS['wp_filter'][ $hook ]->callbacks[ $priority ] ?? [];

		return count( array_filter( array_keys( $callbacks ), static fn( $key ): bool => str_contains( (string) $key, 'Geo_' ) ) );
	}

	/**
	 * Privacy values that hide public content location details.
	 *
	 * @return array<string, array{0:string|null}>
	 */
	public function hidden_privacy_values(): array {
		return [
			'unset'       => [ null ],
			'approximate' => [ 'approximate' ],
			'private'     => [ 'private' ],
		];
	}

	/**
	 * @dataProvider hidden_privacy_values
	 *
	 * @param string|null $privacy Privacy value.
	 */
	public function test_hidden_photo_location_removes_and_restores_content_callables( ?string $privacy ): void {
		wp_set_current_user( 0 );
		$this->register_simple_location_callables();
		$this->current_post( $this->photo( $privacy ) );
		$location = $this->location();

		$location->gate_content( 'x' );

		$this->assertFalse( has_filter( 'the_content', 'Geo_Data::content_map' ) );
		$this->assertFalse( has_filter( 'the_content', 'Geo_Data::location_content' ) );

		$location->restore_content( 'x' );

		$this->assertSame( 11, has_filter( 'the_content', 'Geo_Data::content_map' ) );
		$this->assertSame( 12, has_filter( 'the_content', 'Geo_Data::location_content' ) );
	}

	public function test_public_location_leaves_content_callables_in_place_without_duplicates(): void {
		$this->register_simple_location_callables();
		$this->current_post( $this->photo( 'public' ) );
		$location = $this->location();

		$location->gate_content( 'x' );
		$this->assertSame( 11, has_filter( 'the_content', 'Geo_Data::content_map' ) );
		$this->assertSame( 12, has_filter( 'the_content', 'Geo_Data::location_content' ) );
		$location->restore_content( 'x' );

		$this->assertSame( 1, $this->callback_count( 'the_content', 11 ) );
		$this->assertSame( 1, $this->callback_count( 'the_content', 12 ) );
	}

	public function test_editor_sees_content_location_but_head_and_feed_use_public_fields(): void {
		$this->register_simple_location_callables();
		$post_id = $this->photo( 'private' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->current_post( $post_id );
		$location = $this->location();

		$location->gate_content( 'x' );
		$this->assertSame( 11, has_filter( 'the_content', 'Geo_Data::content_map' ) );
		$this->assertSame( 12, has_filter( 'the_content', 'Geo_Data::location_content' ) );

		$this->go_to( (string) get_permalink( $post_id ) );
		$location->gate_head();
		$this->assertFalse( has_action( 'wp_head', 'Geo_Base::meta_tags' ) );

		$GLOBALS['wp_current_filter'][] = 'rss2_item';
		try {
			$location->gate_feed_item();
			$this->assertFalse( has_action( 'rss2_item', 'Geo_Base::georss_item' ) );
		} finally {
			array_pop( $GLOBALS['wp_current_filter'] );
		}
	}

	public function test_non_photo_content_pass_changes_nothing(): void {
		$this->register_simple_location_callables();
		$this->current_post( $this->post_of_kind( 'note' ) );
		$location = $this->location();

		$location->gate_content( 'x' );
		$out = $location->restore_content( 'x' );

		$this->assertSame( 'x', $out );
		$this->assertSame( 11, has_filter( 'the_content', 'Geo_Data::content_map' ) );
		$this->assertSame( 12, has_filter( 'the_content', 'Geo_Data::location_content' ) );
	}

	public function test_place_line_prints_only_approximate_public_place_parts(): void {
		foreach (
			[
				'unset'       => [ null, '<p>x</p><p class="pkiw-photo-place">Example Hall, Sampletown, Example Region, Exampleland</p>' ],
				'approximate' => [ 'approximate', '<p>x</p><p class="pkiw-photo-place">Example Hall, Sampletown, Example Region, Exampleland</p>' ],
				'private'     => [ 'private', '<p>x</p>' ],
				'public'      => [ 'public', '<p>x</p>' ],
				'editor'      => [ 'editor', '<p>x</p>' ],
			] as $case => [ $privacy, $expected ]
		) {
			$this->unregister_simple_location_callables();
			Photo_Location::reset();
			$this->register_simple_location_callables();
			$post_id = $this->photo( 'editor' === $privacy ? 'approximate' : $privacy );
			foreach (
				[
					'geo_venue'        => 'Example Hall',
					'geo_locality'     => 'Sampletown',
					'geo_region'       => 'Example Region',
					'geo_country_name' => 'Exampleland',
					'geo_address'      => '100 Example Avenue, Sampletown',
					'geo_latitude'     => '40.1',
					'geo_longitude'    => '-75.1',
				] as $key => $value
			) {
				update_post_meta( $post_id, $key, $value );
			}
			if ( 'editor' === $case ) {
				wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
			} else {
				wp_set_current_user( 0 );
			}
			$this->current_post( $post_id );

			$location = $this->location();
			$location->gate_content( '<p>x</p>' );
			$out = $location->restore_content( '<p>x</p>' );

			$this->assertSame( $expected, $out, $case );
			$this->assertStringNotContainsString( '100 Example Avenue', $out, $case );
			$this->assertStringNotContainsString( '40.1', $out, $case );
		}
	}

	public function test_restore_only_readds_callables_that_were_removed(): void {
		$this->current_post( $this->photo() );
		$location = $this->location();

		$location->gate_content( 'x' );
		$location->restore_content( 'x' );

		$this->assertFalse( has_filter( 'the_content', 'Geo_Data::content_map' ) );
		$this->assertFalse( has_filter( 'the_content', 'Geo_Data::location_content' ) );
		$callbacks = wp_json_encode( $GLOBALS['wp_filter']['the_content']->callbacks ?? [] );
		$this->assertStringNotContainsString( 'Geo_Data', (string) $callbacks );
	}

	public function test_partially_registered_content_callables_are_restored_partially(): void {
		add_filter( 'the_content', 'Geo_Data::content_map', 11 );
		$this->current_post( $this->photo() );
		$location = $this->location();

		$location->gate_content( 'x' );
		$location->restore_content( 'x' );

		$this->assertSame( 11, has_filter( 'the_content', 'Geo_Data::content_map' ) );
		$this->assertFalse( has_filter( 'the_content', 'Geo_Data::location_content' ) );
	}

	public function test_nested_pass_does_not_clear_the_outer_removed_list(): void {
		$this->register_simple_location_callables();
		$photo = $this->photo();
		$note  = $this->post_of_kind( 'note' );
		$location = $this->location();

		$this->current_post( $photo );
		$location->gate_content( 'outer' );
		$this->current_post( $note );
		$location->gate_content( 'inner' );
		$location->restore_content( 'inner' );
		$this->assertFalse( has_filter( 'the_content', 'Geo_Data::content_map' ) );
		$this->assertFalse( has_filter( 'the_content', 'Geo_Data::location_content' ) );
		$location->restore_content( 'outer' );
		$this->assertSame( 11, has_filter( 'the_content', 'Geo_Data::content_map' ) );
		$this->assertSame( 12, has_filter( 'the_content', 'Geo_Data::location_content' ) );
	}

	public function test_gate_head_hides_meta_tags_for_any_kind_when_privacy_hides_precise_fields(): void {
		$this->register_simple_location_callables();
		$location = $this->location();
		// Issue 422: a private-location note printed its street in geo.placename.
		foreach ( [ 'photo', 'note', 'article' ] as $kind ) {
			foreach ( [ null, 'approximate', 'private' ] as $privacy ) {
				remove_action( 'wp_head', 'Geo_Base::meta_tags', 10 );
				add_action( 'wp_head', 'Geo_Base::meta_tags', 10 );
				$id = $this->located( $kind, $privacy );
				$this->go_to( (string) get_permalink( $id ) );
				$location->gate_head();
				$this->assertFalse( has_action( 'wp_head', 'Geo_Base::meta_tags' ), $kind . ' ' . var_export( $privacy, true ) );
			}

			remove_action( 'wp_head', 'Geo_Base::meta_tags', 10 );
			add_action( 'wp_head', 'Geo_Base::meta_tags', 10 );
			$public = $this->located( $kind, 'public' );
			$this->go_to( (string) get_permalink( $public ) );
			$location->gate_head();
			$this->assertSame( 10, has_action( 'wp_head', 'Geo_Base::meta_tags' ), $kind . ' public' );
		}

		// A note with no stored location has nothing to hide, so the gate leaves it alone.
		$plain = $this->post_of_kind( 'note' );
		$this->go_to( (string) get_permalink( $plain ) );
		$location->gate_head();
		$this->assertSame( 10, has_action( 'wp_head', 'Geo_Base::meta_tags' ), 'note without location' );
	}

	/**
	 * Feed item hooks.
	 *
	 * @return array<string, array{0:string}>
	 */
	public function feed_hooks(): array {
		return [
			'rss_item'   => [ 'rss_item' ],
			'rss2_item'  => [ 'rss2_item' ],
			'atom_entry' => [ 'atom_entry' ],
			'rdf_item'   => [ 'rdf_item' ],
		];
	}

	/**
	 * @dataProvider feed_hooks
	 *
	 * @param string $hook Feed hook.
	 */
	public function test_feed_item_georss_is_removed_and_restored_per_hook( string $hook ): void {
		$location = $this->location();
		add_action( $hook, 'Geo_Base::georss_item', 10 );
		$this->current_post( $this->photo( 'approximate' ) );

		$GLOBALS['wp_current_filter'][] = $hook;
		try {
			$location->gate_feed_item();
			$this->assertFalse( has_action( $hook, 'Geo_Base::georss_item' ) );
			$location->restore_feed_item();
		} finally {
			array_pop( $GLOBALS['wp_current_filter'] );
		}
		$this->assertSame( 10, has_action( $hook, 'Geo_Base::georss_item' ) );

		$this->current_post( $this->photo( 'public' ) );
		$GLOBALS['wp_current_filter'][] = $hook;
		try {
			$location->gate_feed_item();
			$this->assertSame( 10, has_action( $hook, 'Geo_Base::georss_item' ) );
			$location->restore_feed_item();
		} finally {
			array_pop( $GLOBALS['wp_current_filter'] );
		}

		$this->current_post( $this->located( 'note', 'private' ) );
		$GLOBALS['wp_current_filter'][] = $hook;
		try {
			$location->gate_feed_item();
			$this->assertFalse( has_action( $hook, 'Geo_Base::georss_item' ), 'private note' );
			$location->restore_feed_item();
		} finally {
			array_pop( $GLOBALS['wp_current_filter'] );
		}
		$this->assertSame( 10, has_action( $hook, 'Geo_Base::georss_item' ) );

		$this->current_post( $this->located( 'note', 'public' ) );
		$GLOBALS['wp_current_filter'][] = $hook;
		try {
			$location->gate_feed_item();
			$this->assertSame( 10, has_action( $hook, 'Geo_Base::georss_item' ), 'public note' );
			$location->restore_feed_item();
		} finally {
			array_pop( $GLOBALS['wp_current_filter'] );
		}

		foreach ( [ $this->photo( 'private' ), $this->post_of_kind( 'note' ) ] as $post_id ) {
			$this->current_post( $post_id );
			$GLOBALS['wp_current_filter'][] = $hook;
			try {
				$location->gate_feed_item();
				$location->restore_feed_item();
			} finally {
				array_pop( $GLOBALS['wp_current_filter'] );
			}
		}
		$this->assertSame( 10, has_action( $hook, 'Geo_Base::georss_item' ) );

		remove_action( $hook, 'Geo_Base::georss_item', 10 );
		$this->current_post( $this->photo( 'private' ) );
		$GLOBALS['wp_current_filter'][] = $hook;
		try {
			$location->gate_feed_item();
			$location->restore_feed_item();
		} finally {
			array_pop( $GLOBALS['wp_current_filter'] );
		}
		$this->assertFalse( has_action( $hook, 'Geo_Base::georss_item' ) );
	}

	public function test_json_feed_item_unsets_geo_for_any_kind_with_hidden_location(): void {
		$location     = $this->location();
		$hidden       = get_post( $this->photo( 'approximate' ) );
		$public       = get_post( $this->photo( 'public' ) );
		$private_note = get_post( $this->located( 'note', 'private' ) );
		$public_note  = get_post( $this->located( 'note', 'public' ) );

		$this->assertSame( [ 'title' => 'Hidden' ], $location->gate_json_feed_item( [ 'title' => 'Hidden', 'geo' => [ 'lat' => 40.1 ] ], $hidden ) );
		$this->assertArrayHasKey( 'geo', $location->gate_json_feed_item( [ 'title' => 'Public', 'geo' => [ 'lat' => 40.1 ] ], $public ) );
		$this->assertSame( [ 'title' => 'Note' ], $location->gate_json_feed_item( [ 'title' => 'Note', 'geo' => [ 'lat' => 40.1 ] ], $private_note ) );
		$this->assertArrayHasKey( 'geo', $location->gate_json_feed_item( [ 'title' => 'Public note', 'geo' => [ 'lat' => 40.1 ] ], $public_note ) );
	}

	/**
	 * Remove every Photo_Location callback so a test sees only the wiring it triggers.
	 */
	private function strip_photo_location_hooks(): void {
		foreach ( [ 'the_content', 'template_redirect', 'rss_item', 'rss2_item', 'atom_entry', 'rdf_item', 'json_feed_item' ] as $hook ) {
			if ( ! isset( $GLOBALS['wp_filter'][ $hook ] ) ) {
				continue;
			}
			foreach ( $GLOBALS['wp_filter'][ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Photo_Location ) {
						remove_filter( $hook, $callback['function'], $priority );
					}
				}
			}
		}
	}

	public function test_registered_content_gate_and_restore_hooks_run_through_the_content_filter(): void {
		$this->strip_photo_location_hooks();
		$this->location()->register();
		$this->register_simple_location_callables();
		$this->current_post( $this->photo( 'private' ) );
		$seen = [];
		add_filter(
			'the_content',
			static function ( $content ) use ( &$seen ) {
				$seen['mid'] = [ has_filter( 'the_content', 'Geo_Data::content_map' ), has_filter( 'the_content', 'Geo_Data::location_content' ) ];
				return $content;
			},
			12
		);
		add_filter(
			'the_content',
			static function ( $content ) use ( &$seen ) {
				$seen['end'] = [ has_filter( 'the_content', 'Geo_Data::content_map' ), has_filter( 'the_content', 'Geo_Data::location_content' ) ];
				return $content;
			},
			20
		);

		apply_filters( 'the_content', 'x' );

		$this->assertSame( [ false, false ], $seen['mid'] );
		$this->assertSame( [ 11, 12 ], $seen['end'] );
	}

	public function test_registered_head_feed_and_json_gates_run_through_their_hooks(): void {
		$this->strip_photo_location_hooks();
		$this->location()->register();
		$this->register_simple_location_callables();
		$id = $this->photo( 'private' );

		$this->go_to( (string) get_permalink( $id ) );
		do_action( 'template_redirect' );
		$this->assertFalse( has_action( 'wp_head', 'Geo_Base::meta_tags' ) );

		$this->register_simple_location_callables();
		$this->current_post( $id );
		foreach ( [ 'rss_item', 'rss2_item', 'atom_entry', 'rdf_item' ] as $hook ) {
			$during = null;
			add_action(
				$hook,
				static function () use ( &$during, $hook ) {
					$during = has_action( $hook, 'Geo_Base::georss_item' );
				},
				10
			);
			do_action( $hook );
			$this->assertFalse( $during, $hook . ' during' );
			$this->assertSame( 10, has_action( $hook, 'Geo_Base::georss_item' ), $hook . ' after' );
		}

		remove_filter( 'json_feed_item', 'Geo_Base::json_feed_item', 10 );
		$item = apply_filters( 'json_feed_item', [ 'geo' => [ 'lat' => 1 ], 'id' => 'a' ], get_post( $id ) );
		$this->assertArrayNotHasKey( 'geo', $item );
	}

	public function test_protected_public_photo_hides_every_location_output(): void {
		wp_set_current_user( 0 );
		$this->register_simple_location_callables();
		$post_id = $this->photo( 'public', '1', [ 'post_password' => 'pw' ] );
		$post    = get_post( $post_id );
		$this->current_post( $post_id );
		$location = $this->location();

		$location->gate_content( 'x' );
		$this->assertFalse( has_filter( 'the_content', 'Geo_Data::content_map' ) );
		$this->assertFalse( has_filter( 'the_content', 'Geo_Data::location_content' ) );
		$this->assertSame( 'x', $location->restore_content( 'x' ), 'A protected post prints no place line.' );
		$this->assertSame( 11, has_filter( 'the_content', 'Geo_Data::content_map' ) );
		$this->assertSame( 12, has_filter( 'the_content', 'Geo_Data::location_content' ) );

		$this->go_to( (string) get_permalink( $post_id ) );
		$location->gate_head();
		$this->assertFalse( has_action( 'wp_head', 'Geo_Base::meta_tags' ) );

		$GLOBALS['wp_current_filter'][] = 'rss2_item';
		try {
			$location->gate_feed_item();
			$this->assertFalse( has_action( 'rss2_item', 'Geo_Base::georss_item' ) );
			$location->restore_feed_item();
		} finally {
			array_pop( $GLOBALS['wp_current_filter'] );
		}
		$this->assertSame( 10, has_action( 'rss2_item', 'Geo_Base::georss_item' ) );

		$item = $location->gate_json_feed_item( [ 'id' => 'a', 'geo' => [ 'lat' => 1 ] ], $post );
		$this->assertArrayNotHasKey( 'geo', $item );
	}

	public function test_protected_approximate_photo_prints_no_place_line_and_registers_no_geo_callable_mid_pass(): void {
		wp_set_current_user( 0 );
		$meta = [
			'geo_venue'        => 'Example Hall',
			'geo_locality'     => 'Sampletown',
			'geo_region'       => 'Example Region',
			'geo_country_name' => 'Exampleland',
		];

		$this->strip_photo_location_hooks();
		$this->location()->register();
		$this->register_simple_location_callables();
		$seen = [];
		add_filter(
			'the_content',
			static function ( $content ) use ( &$seen ) {
				$seen[] = [ has_filter( 'the_content', 'Geo_Data::content_map' ), has_filter( 'the_content', 'Geo_Data::location_content' ) ];
				return $content;
			},
			12
		);

		$protected = $this->photo( 'approximate', '1', [ 'post_password' => 'pw' ] );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $protected, $key, $value );
		}
		$this->current_post( $protected );
		$out = apply_filters( 'the_content', '<p>x</p>' );

		$this->assertSame( '<p>x</p>', trim( $out ) );
		$this->assertStringNotContainsString( 'pkiw-photo-place', $out );
		$this->assertStringNotContainsString( 'Example Hall', $out );
		$this->assertSame( [ [ false, false ] ], $seen, 'No Simple Location content callable runs mid-pass for a protected post.' );
		$this->assertSame( 11, has_filter( 'the_content', 'Geo_Data::content_map' ) );
		$this->assertSame( 12, has_filter( 'the_content', 'Geo_Data::location_content' ) );

		$open = $this->photo( 'approximate' );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $open, $key, $value );
		}
		$this->current_post( $open );
		$this->assertStringContainsString(
			'<p class="pkiw-photo-place">Example Hall, Sampletown, Example Region, Exampleland</p>',
			apply_filters( 'the_content', '<p>x</p>' ),
			'An unprotected approximate photo still prints its place line.'
		);
	}

	public function test_street_visible_map_hidden_tier_keeps_location_line_and_drops_map(): void {
		wp_set_current_user( 0 );
		$this->register_simple_location_callables();
		$this->current_post( $this->photo( 'public', '2' ) );
		$location = $this->location();

		$location->gate_content( 'x' );
		$this->assertFalse( has_filter( 'the_content', 'Geo_Data::content_map' ) );
		$this->assertSame( 12, has_filter( 'the_content', 'Geo_Data::location_content' ) );

		$this->assertSame( 'x', $location->restore_content( 'x' ) );
		$this->assertSame( 11, has_filter( 'the_content', 'Geo_Data::content_map' ) );
		$this->assertSame( 12, has_filter( 'the_content', 'Geo_Data::location_content' ) );
		$this->assertSame( 1, $this->callback_count( 'the_content', 11 ) );
		$this->assertSame( 1, $this->callback_count( 'the_content', 12 ) );
	}

	public function test_photo_archive_register_wires_the_location_gate(): void {
		$this->strip_photo_location_hooks();
		( new \PKIW\Photo_Archive() )->register();

		$found = false;
		foreach ( $GLOBALS['wp_filter']['the_content']->callbacks[10] ?? [] as $callback ) {
			if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Photo_Location ) {
				$found = true;
			}
		}
		$this->assertTrue( $found );
	}

	/**
	 * Give the current visitor a post-password cookie.
	 *
	 * @param string $password Password the cookie proves.
	 */
	private function send_postpass_cookie( string $password ): void {
		require_once ABSPATH . WPINC . '/class-phpass.php';
		$hasher                                 = new PasswordHash( 8, true );
		$_COOKIE[ 'wp-postpass_' . COOKIEHASH ] = $hasher->HashPassword( $password );
	}

	/**
	 * Run one feed item gate/restore pair and report whether GeoRSS was hooked in between.
	 *
	 * @param Photo_Location $location Gate under test.
	 * @param string         $hook     Feed hook.
	 * @return int|false Priority of the GeoRSS callback during the item, or false when removed.
	 */
	private function georss_during_gate( Photo_Location $location, string $hook ) {
		$GLOBALS['wp_current_filter'][] = $hook;
		try {
			$location->gate_feed_item();
			$during = has_action( $hook, 'Geo_Base::georss_item' );
			$location->restore_feed_item();
		} finally {
			array_pop( $GLOBALS['wp_current_filter'] );
		}

		return $during;
	}

	/**
	 * @dataProvider feed_hooks
	 *
	 * @param string $hook Feed hook.
	 */
	public function test_protected_public_photo_hides_georss_on_every_feed_hook( string $hook ): void {
		wp_set_current_user( 0 );
		$this->strip_photo_location_hooks();
		$this->location()->register();
		$this->register_simple_location_callables();
		$this->current_post( $this->photo( 'public', '1', [ 'post_password' => 'pw' ] ) );

		$during = null;
		add_action(
			$hook,
			static function () use ( &$during, $hook ) {
				$during = has_action( $hook, 'Geo_Base::georss_item' );
			},
			10
		);
		do_action( $hook );

		$this->assertFalse( $during, $hook . ' hides GeoRSS for a protected post during the item' );
		$this->assertSame( 10, has_action( $hook, 'Geo_Base::georss_item' ), $hook . ' restores GeoRSS afterward' );
	}

	/**
	 * @dataProvider feed_hooks
	 *
	 * @param string $hook Feed hook.
	 */
	public function test_visitor_with_the_post_password_cookie_gets_the_normal_public_tier_in_feeds( string $hook ): void {
		wp_set_current_user( 0 );
		$this->register_simple_location_callables();
		$location    = $this->location();
		$public      = $this->photo( 'public', '1', [ 'post_password' => 'pw' ] );
		$approximate = $this->photo( 'approximate', '1', [ 'post_password' => 'pw' ] );

		$this->current_post( $public );
		$this->assertFalse( $this->georss_during_gate( $location, $hook ), $hook . ' hides GeoRSS before the password is entered' );

		$this->send_postpass_cookie( 'other' );
		$this->assertFalse( $this->georss_during_gate( $location, $hook ), $hook . ' hides GeoRSS for a wrong password cookie' );

		$this->send_postpass_cookie( 'pw' );
		$this->assertFalse( post_password_required( $public ) );
		$this->assertSame( 10, $this->georss_during_gate( $location, $hook ), $hook . ' keeps GeoRSS on a public photo once the password is entered' );
		$this->assertSame( 10, has_action( $hook, 'Geo_Base::georss_item' ) );

		$this->current_post( $approximate );
		$this->assertFalse( $this->georss_during_gate( $location, $hook ), $hook . ' still hides GeoRSS on an approximate photo' );
		$this->assertSame( 10, has_action( $hook, 'Geo_Base::georss_item' ) );

		$item = $location->gate_json_feed_item( [ 'id' => 'a', 'geo' => [ 'lat' => 1 ] ], get_post( $public ) );
		$this->assertArrayHasKey( 'geo', $item, 'The JSON item keeps geo for a public photo once the password is entered.' );
		$item = $location->gate_json_feed_item( [ 'id' => 'a', 'geo' => [ 'lat' => 1 ] ], get_post( $approximate ) );
		$this->assertArrayNotHasKey( 'geo', $item );
	}

	public function test_visitor_with_the_post_password_cookie_gets_the_normal_content_and_head_tiers(): void {
		wp_set_current_user( 0 );
		$this->register_simple_location_callables();
		$location = $this->location();
		$public   = $this->photo( 'public', '1', [ 'post_password' => 'pw' ] );
		$approx   = $this->photo( 'approximate', '1', [ 'post_password' => 'pw' ] );
		update_post_meta( $approx, 'geo_venue', 'Example Hall' );
		update_post_meta( $approx, 'geo_locality', 'Sampletown' );

		// A wrong cookie proves nothing, so the protected public photo hides its content callables.
		$this->send_postpass_cookie( 'other' );
		$this->assertTrue( post_password_required( $public ) );
		$this->current_post( $public );
		$location->gate_content( 'x' );
		$this->assertFalse( has_filter( 'the_content', 'Geo_Data::content_map' ), 'A wrong password cookie hides the map.' );
		$this->assertFalse( has_filter( 'the_content', 'Geo_Data::location_content' ), 'A wrong password cookie hides the place.' );
		$this->assertSame( 'x', $location->restore_content( 'x' ) );

		// The correct cookie restores the normal tiers: a public photo keeps both callables.
		$this->send_postpass_cookie( 'pw' );
		$this->assertFalse( post_password_required( $public ) );
		$location->gate_content( 'x' );
		$this->assertSame( 11, has_filter( 'the_content', 'Geo_Data::content_map' ), 'A public photo keeps the map once the password is entered.' );
		$this->assertSame( 12, has_filter( 'the_content', 'Geo_Data::location_content' ), 'A public photo keeps the place once the password is entered.' );
		$this->assertSame( 'x', $location->restore_content( 'x' ) );

		// An approximate photo drops the precise callables and prints its place line.
		$this->current_post( $approx );
		$location->gate_content( 'x' );
		$this->assertFalse( has_filter( 'the_content', 'Geo_Data::content_map' ) );
		$this->assertFalse( has_filter( 'the_content', 'Geo_Data::location_content' ) );
		$this->assertSame( 'x<p class="pkiw-photo-place">Example Hall, Sampletown</p>', $location->restore_content( 'x' ), 'An approximate photo prints its place line once the password is entered.' );
		$this->assertSame( 11, has_filter( 'the_content', 'Geo_Data::content_map' ) );
		$this->assertSame( 12, has_filter( 'the_content', 'Geo_Data::location_content' ) );

		// Head meta follows the same tiers.
		$this->go_to( (string) get_permalink( $public ) );
		$location->gate_head();
		$this->assertSame( 10, has_action( 'wp_head', 'Geo_Base::meta_tags' ), 'A public photo keeps head meta once the password is entered.' );

		$this->go_to( (string) get_permalink( $approx ) );
		$location->gate_head();
		$this->assertFalse( has_action( 'wp_head', 'Geo_Base::meta_tags' ), 'An approximate photo still hides head meta.' );
	}
}
