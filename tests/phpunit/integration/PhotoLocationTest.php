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
	 * @param string|null $privacy Post Kinds privacy.
	 * @param string     $geo      Simple Location geo_public.
	 * @return int Post ID.
	 */
	private function photo( ?string $privacy = null, string $geo = '1' ): int {
		$id = $this->post_of_kind( 'photo' );
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

	public function test_gate_head_hides_meta_tags_for_non_public_photo_location_only(): void {
		$this->register_simple_location_callables();
		$location = $this->location();
		foreach ( [ null, 'approximate', 'private' ] as $privacy ) {
			remove_action( 'wp_head', 'Geo_Base::meta_tags', 10 );
			add_action( 'wp_head', 'Geo_Base::meta_tags', 10 );
			$id = $this->photo( $privacy );
			$this->go_to( (string) get_permalink( $id ) );
			$location->gate_head();
			$this->assertFalse( has_action( 'wp_head', 'Geo_Base::meta_tags' ) );
		}

		add_action( 'wp_head', 'Geo_Base::meta_tags', 10 );
		$public = $this->photo( 'public' );
		$this->go_to( (string) get_permalink( $public ) );
		$location->gate_head();
		$this->assertSame( 10, has_action( 'wp_head', 'Geo_Base::meta_tags' ) );

		$note = $this->post_of_kind( 'note' );
		$this->go_to( (string) get_permalink( $note ) );
		$location->gate_head();
		$this->assertSame( 10, has_action( 'wp_head', 'Geo_Base::meta_tags' ) );
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

	public function test_json_feed_item_unsets_geo_only_for_hidden_photo_location(): void {
		$location = $this->location();
		$hidden   = get_post( $this->photo( 'approximate' ) );
		$public   = get_post( $this->photo( 'public' ) );
		$note     = get_post( $this->post_of_kind( 'note' ) );

		$this->assertSame( [ 'title' => 'Hidden' ], $location->gate_json_feed_item( [ 'title' => 'Hidden', 'geo' => [ 'lat' => 40.1 ] ], $hidden ) );
		$this->assertArrayHasKey( 'geo', $location->gate_json_feed_item( [ 'title' => 'Public', 'geo' => [ 'lat' => 40.1 ] ], $public ) );
		$this->assertArrayHasKey( 'geo', $location->gate_json_feed_item( [ 'title' => 'Note', 'geo' => [ 'lat' => 40.1 ] ], $note ) );
	}
}
