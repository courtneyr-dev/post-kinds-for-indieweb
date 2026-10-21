<?php
/**
 * Operational abilities integration coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Abilities\Operations_Abilities;
use PKIW\Abilities_Manager;
use PKIW\Card_Meta_Sync;
use PKIW\CLI_Commands;
use PKIW\Featured_Artwork;
use PKIW\Listen_Media_Type;
use PKIW\Taxonomy;
use PKIW\Venue_Taxonomy;

require_once dirname( __DIR__ ) . '/fixtures/wp-cli-stub.php';
require_once dirname( __DIR__, 3 ) . '/includes/class-cli-commands.php';

/**
 * @group integration
 */
final class OperationsAbilitiesTest extends WP_UnitTestCase {

	private const COVER = 'https://coverartarchive.org/release/abc/front-500.png';

	/**
	 * Number of intercepted artwork requests.
	 */
	private int $http_requests = 0;

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->register_taxonomy();
		$venues = new Venue_Taxonomy();
		$venues->register_taxonomy();
		$venues->register_term_meta();
		foreach ( [ 'article', 'checkin', 'listen', 'note' ] as $slug ) {
			if ( ! term_exists( $slug, Taxonomy::TAXONOMY ) ) {
				wp_insert_term( ucfirst( $slug ), Taxonomy::TAXONOMY, [ 'slug' => $slug ] );
			}
		}
		$this->http_requests = 0;
		add_filter( 'pre_http_request', [ $this, 'stub_artwork_request' ], 10, 3 );
		WP_CLI::reset();
	}

	public function tear_down(): void {
		remove_all_filters( 'pkiw_stream_kinds' );
		remove_filter( 'pre_http_request', [ $this, 'stub_artwork_request' ], 10 );
		wp_clear_scheduled_hook( Card_Meta_Sync::BACKFILL_HOOK );
		$this->reset_abilities_registry();
		parent::tear_down();
	}

	/**
	 * Discard the abilities registry singleton so the next call rebuilds it
	 * from the hooks that exist then (WP_UnitTestCase restores $wp_filter).
	 */
	private function reset_abilities_registry(): void {
		if ( ! class_exists( 'WP_Abilities_Registry' ) ) {
			return;
		}

		$property = new ReflectionProperty( WP_Abilities_Registry::class, 'instance' );
		$property->setValue( null, null );
	}

	public function test_all_operations_abilities_are_registered_with_schemas(): void {
		foreach ( $this->ability_names() as $name ) {
			$ability = $this->ability( $name );
			$this->assertSame( 'post-kinds', $ability->get_category(), $name );
			$this->assertNotEmpty( $ability->get_input_schema(), $name );
			$this->assertNotEmpty( $ability->get_output_schema(), $name );
		}
	}

	public function test_annotations_match_the_operations_contract(): void {
		$expected = [
			'post-kinds/backfill-surfaces'          => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
			'post-kinds/backfill-card-meta'         => [ 'readonly' => false, 'destructive' => true, 'idempotent' => true ],
			'post-kinds/backfill-listen-media-type' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
			'post-kinds/backfill-featured-artwork'  => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
			'post-kinds/get-checkin-stats'           => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
			'post-kinds/list-venues'                 => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
			'post-kinds/get-surface'                 => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
			'post-kinds/get-public-location-fields'  => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
		];

		foreach ( $expected as $name => $annotations ) {
			$this->assertSame( $annotations, $this->ability( $name )->get_meta()['annotations'], $name );
		}
	}

	public function test_mcp_exposure_matches_read_and_write_contract(): void {
		foreach ( $this->write_names() as $name ) {
			$this->assertFalse( $this->ability( $name )->get_meta()['mcp']['public'], $name );
		}
		foreach ( $this->read_names() as $name ) {
			$this->assertTrue( $this->ability( $name )->get_meta()['mcp']['public'], $name );
		}
	}

	/**
	 * The exposure filter is only hooked when the mcp_integration flag is on.
	 * With it absent, each write must still be registered private on its own.
	 */
	public function test_writes_register_private_even_without_the_exposure_filter(): void {
		$this->ability( 'post-kinds/backfill-surfaces' );
		remove_filter( 'wp_register_ability_args', [ Abilities_Manager::class, 'filter_ability_args' ], 10 );
		$this->reset_abilities_registry();

		foreach ( $this->write_names() as $name ) {
			$this->assertFalse( $this->ability( $name )->get_meta()['mcp']['public'] ?? null, $name );
		}
	}

	public function test_backfills_cannot_opt_in_to_mcp_through_registration_args(): void {
		foreach ( $this->write_names() as $name ) {
			$args = Abilities_Manager::filter_ability_args(
				[
					'meta' => [
						'mcp' => [ 'public' => true ],
					],
				],
				$name
			);
			$this->assertFalse( $args['meta']['mcp']['public'], $name );
		}
	}

	public function test_write_input_schemas_are_closed_and_default_to_dry_run(): void {
		foreach ( $this->write_names() as $name ) {
			$schema = $this->ability( $name )->get_input_schema();
			$this->assertFalse( $schema['additionalProperties'], $name );
			$this->assertSame( [ 'dry_run' => true ], $schema['default'], $name );
			$this->assertSame( 'boolean', $schema['properties']['dry_run']['type'], $name );
			$this->assertTrue( $schema['properties']['dry_run']['default'], $name );
		}

		$batch = $this->ability( 'post-kinds/backfill-card-meta' )->get_input_schema()['properties']['batch'];
		$this->assertSame( 'integer', $batch['type'] );
		$this->assertSame( 1, $batch['minimum'] );

		$ids = $this->ability( 'post-kinds/backfill-listen-media-type' )->get_input_schema()['properties']['ids'];
		$this->assertSame( 'array', $ids['type'] );
		$this->assertSame( 'integer', $ids['items']['type'] );
	}

	/**
	 * @dataProvider administrator_only_ability_names
	 */
	public function test_administrator_only_operations_deny_lower_roles( string $name ): void {
		wp_set_current_user( 0 );
		$this->assertErrorCode( 'ability_invalid_permissions', $this->ability( $name )->execute( [] ) );

		foreach ( [ 'subscriber', 'author', 'editor' ] as $role ) {
			wp_set_current_user( self::factory()->user->create( [ 'role' => $role ] ) );
			$this->assertErrorCode( 'ability_invalid_permissions', $this->ability( $name )->execute( [] ) );
		}

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertNotWPError( $this->ability( $name )->execute( [] ) );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function administrator_only_ability_names(): array {
		$names = [
			'post-kinds/backfill-surfaces',
			'post-kinds/backfill-card-meta',
			'post-kinds/backfill-listen-media-type',
			'post-kinds/backfill-featured-artwork',
			'post-kinds/list-venues',
		];
		return array_combine( $names, array_map( static fn( $name ) => [ $name ], $names ) );
	}

	public function test_checkin_stats_requires_edit_posts(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertErrorCode( 'ability_invalid_permissions', $this->ability( 'post-kinds/get-checkin-stats' )->execute( [] ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'contributor' ] ) );
		$this->assertNotWPError( $this->ability( 'post-kinds/get-checkin-stats' )->execute( [] ) );
	}

	public function test_surface_backfill_defaults_to_dry_run_and_is_idempotent(): void {
		add_filter( 'pkiw_stream_kinds', static fn() => [ 'checkin' ] );
		$checkin = $this->post_with_kind( 'checkin' );
		$article = $this->post_with_kind( 'article' );
		update_post_meta( $checkin, '_pkiw_surface', 'main' );
		delete_post_meta( $article, '_pkiw_surface' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$ability = $this->ability( 'post-kinds/backfill-surfaces' );

		foreach ( [ [], null ] as $input ) {
			$result = $ability->execute( $input );
			$this->assertIsArray( $result );
			$this->assertTrue( $result['dry_run'] );
			$this->assertGreaterThanOrEqual( 2, $result['processed'] );
			$this->assertSame( 2, $result['changed'] );
			$this->assertSame( 'main', get_post_meta( $checkin, '_pkiw_surface', true ) );
			$this->assertSame( '', get_post_meta( $article, '_pkiw_surface', true ) );
		}

		$written = $ability->execute( [ 'dry_run' => false ] );
		$this->assertFalse( $written['dry_run'] );
		$this->assertSame( 2, $written['changed'] );
		$this->assertSame( 'stream', get_post_meta( $checkin, '_pkiw_surface', true ) );
		$this->assertSame( 'main', get_post_meta( $article, '_pkiw_surface', true ) );

		$second = $ability->execute( [ 'dry_run' => false ] );
		$this->assertSame( 0, $second['changed'] );
	}

	public function test_card_meta_backfill_defaults_to_dry_run_then_writes_in_batches(): void {
		$one   = $this->listen_card_post( 'One' );
		$two   = $this->listen_card_post( 'Two' );
		$plain = self::factory()->post->create( [ 'post_content' => '<!-- wp:paragraph --><p>No card.</p><!-- /wp:paragraph -->' ] );
		delete_post_meta( $one, '_pkiw_listen_track' );
		delete_post_meta( $two, '_pkiw_listen_track' );
		delete_option( Card_Meta_Sync::BACKFILL_OPTION );
		$scheduled = wp_next_scheduled( Card_Meta_Sync::BACKFILL_HOOK );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$ability = $this->ability( 'post-kinds/backfill-card-meta' );

		foreach ( [ [], null ] as $input ) {
			$result = $ability->execute( $input );
			$this->assertIsArray( $result );
			$this->assertTrue( $result['dry_run'] );
			$this->assertSame( 2, $result['processed'] );
			$this->assertNull( get_metadata_raw( 'post', $one, '_pkiw_listen_track', true ) );
			$this->assertNull( get_metadata_raw( 'post', $two, '_pkiw_listen_track', true ) );
			$this->assertFalse( get_option( Card_Meta_Sync::BACKFILL_OPTION, false ) );
			$this->assertSame( $scheduled, wp_next_scheduled( Card_Meta_Sync::BACKFILL_HOOK ) );
		}
		$this->assertSame( '', get_post_meta( $plain, '_pkiw_listen_track', true ) );

		$written = $ability->execute( [ 'dry_run' => false ] );
		$this->assertSame( 2, $written['processed'] );
		$this->assertSame( 'One', get_post_meta( $one, '_pkiw_listen_track', true ) );
		$this->assertSame( 'Two', get_post_meta( $two, '_pkiw_listen_track', true ) );
		$this->assertSame( Card_Meta_Sync::BACKFILL_VERSION, get_option( Card_Meta_Sync::BACKFILL_OPTION ) );

		$one_at_a_time = $ability->execute( [ 'dry_run' => false, 'batch' => 1 ] );
		$this->assertSame( 2, $one_at_a_time['processed'] );
		$this->assertSame( 1, $one_at_a_time['batch'] );

		$this->assertErrorCode( 'ability_invalid_input', $ability->execute( [ 'batch' => 0 ] ) );
	}

	public function test_listen_media_type_dry_run_and_explicit_write_contract(): void {
		$one = $this->listen_candidate( 'Selected candidate' );
		$two = $this->listen_candidate( 'Unselected candidate' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$ability = $this->ability( 'post-kinds/backfill-listen-media-type' );

		foreach ( [ [], null ] as $input ) {
			$result = $ability->execute( $input );
			$this->assertIsArray( $result );
			$this->assertTrue( $result['dry_run'] );
			$this->assertSame( Listen_Media_Type::candidates(), $result['candidates'] );
			$this->assertSame( [], $result['written'] );
			$this->assertSame( [], $result['skipped'] );
			$this->assertFalse( metadata_exists( 'post', $one, Listen_Media_Type::META ) );
			$this->assertFalse( metadata_exists( 'post', $two, Listen_Media_Type::META ) );
		}

		$this->assertErrorCode( 'pkiw_ids_required', $ability->execute( [ 'dry_run' => false ] ) );

		$written = $ability->execute( [ 'dry_run' => false, 'ids' => [ $one, 99999 ] ] );
		$this->assertSame( [ $one ], $written['written'] );
		$this->assertSame( [ [ 'id' => 99999, 'reason' => 'not a candidate' ] ], $written['skipped'] );
		$this->assertSame( Listen_Media_Type::PODCAST, Listen_Media_Type::stored( $one ) );
		$this->assertFalse( metadata_exists( 'post', $two, Listen_Media_Type::META ) );

		$empty = $ability->execute( [ 'dry_run' => false, 'ids' => [] ] );
		$this->assertSame( [], $empty['written'] );
		$this->assertSame( [], $empty['skipped'] );
		$this->assertFalse( metadata_exists( 'post', $two, Listen_Media_Type::META ) );

		$ignored = $ability->execute( [ 'dry_run' => true, 'ids' => [ $two ] ] );
		$this->assertTrue( $ignored['dry_run'] );
		$this->assertFalse( metadata_exists( 'post', $two, Listen_Media_Type::META ) );
	}

	public function test_featured_artwork_backfill_defaults_to_dry_run_then_writes(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$post_id = $this->listen_artwork_post();
		delete_post_thumbnail( $post_id );
		delete_post_meta( $post_id, Featured_Artwork::SOURCE_META );
		delete_post_meta( $post_id, Featured_Artwork::ATTACHMENT_META );
		$this->http_requests = 0;
		$ability             = $this->ability( 'post-kinds/backfill-featured-artwork' );

		foreach ( [ [], null ] as $input ) {
			$result = $ability->execute( $input );
			$this->assertIsArray( $result );
			$this->assertTrue( $result['dry_run'] );
			$this->assertSame( 1, $result['updated'] );
			$this->assertSame( 0, $this->http_requests );
			$this->assertSame( 0, (int) get_post_thumbnail_id( $post_id ) );
		}

		$written = $ability->execute( [ 'dry_run' => false ] );
		$this->assertSame( 1, $written['updated'] );
		$this->assertGreaterThan( 0, get_post_thumbnail_id( $post_id ) );

		$second = $ability->execute( [ 'dry_run' => false ] );
		$this->assertSame( 0, $second['updated'] );
		$this->assertSame( 1, $second['skipped'] );
	}

	public function test_invalid_dry_run_value_is_rejected_by_schema(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		foreach ( $this->write_names() as $name ) {
			$this->assertErrorCode( 'ability_invalid_input', $this->ability( $name )->execute( [ 'dry_run' => 'yes' ] ) );
		}
	}

	public function test_direct_execute_uses_fail_safe_dry_run_for_unknown_value(): void {
		add_filter( 'pkiw_stream_kinds', static fn() => [ 'checkin' ] );
		$post_id = $this->post_with_kind( 'checkin' );
		update_post_meta( $post_id, '_pkiw_surface', 'main' );

		$result = Operations_Abilities::instance()->execute_backfill_surfaces( [ 'dry_run' => 'nonsense' ] );

		$this->assertTrue( $result['dry_run'] );
		$this->assertSame( 'main', get_post_meta( $post_id, '_pkiw_surface', true ) );
	}

	/**
	 * @dataProvider explicit_false_values
	 * @param mixed $value Explicit false representation.
	 */
	public function test_direct_execute_writes_only_for_explicit_false_values( $value ): void {
		add_filter( 'pkiw_stream_kinds', static fn() => [ 'checkin' ] );
		$post_id = $this->post_with_kind( 'checkin' );
		update_post_meta( $post_id, '_pkiw_surface', 'main' );

		$result = Operations_Abilities::instance()->execute_backfill_surfaces( [ 'dry_run' => $value ] );

		$this->assertFalse( $result['dry_run'] );
		$this->assertSame( 'stream', get_post_meta( $post_id, '_pkiw_surface', true ) );
	}

	/**
	 * @return array<string, array{0:mixed}>
	 */
	public static function explicit_false_values(): array {
		return [
			'boolean false' => [ false ],
			'integer zero'  => [ 0 ],
			'string zero'   => [ '0' ],
			'string false'  => [ 'false' ],
		];
	}

	public function test_unknown_input_key_is_rejected_for_every_write(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		foreach ( $this->write_names() as $name ) {
			$this->assertErrorCode( 'ability_invalid_input', $this->ability( $name )->execute( [ 'unknown' => true ] ) );
		}
	}

	public function test_checkin_stats_matches_cli_output(): void {
		for ( $i = 0; $i < 3; ++$i ) {
			$this->post_with_kind( 'checkin' );
		}
		$this->post_with_kind( 'checkin', [ 'post_status' => 'draft' ] );
		Venue_Taxonomy::create_or_get( [ 'name' => 'Corner Cafe' ] );
		Venue_Taxonomy::create_or_get( [ 'name' => 'Riverside Park' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'contributor' ] ) );

		$result = $this->ability( 'post-kinds/get-checkin-stats' )->execute( [] );

		$this->assertSame( [ 'checkin_posts' => 3, 'venue_terms' => 2 ], $result );

		$calls         = $this->transcript( static fn() => ( new CLI_Commands() )->checkin_stats( [], [] ) );
		$checkin_posts = null;
		$venue_terms   = null;
		foreach ( $calls as $call ) {
			if ( 'log' !== $call[0] ) {
				continue;
			}
			if ( str_starts_with( $call[1], 'Posts with checkin kind: ' ) ) {
				$checkin_posts = (int) substr( $call[1], strlen( 'Posts with checkin kind: ' ) );
			}
			if ( str_starts_with( $call[1], 'Venue terms: ' ) ) {
				$venue_terms = (int) substr( $call[1], strlen( 'Venue terms: ' ) );
			}
		}
		$this->assertSame( $result['checkin_posts'], $checkin_posts );
		$this->assertSame( $result['venue_terms'], $venue_terms );
	}

	public function test_list_venues_shapes_full_bare_and_zero_coordinate_rows(): void {
		$full = Venue_Taxonomy::create_or_get(
			[
				'name'      => 'Central Park',
				'city'      => 'New York',
				'country'   => 'USA',
				'latitude'  => '40.7829',
				'longitude' => '-73.9654',
			]
		);
		$bare = Venue_Taxonomy::create_or_get( [ 'name' => 'Back Alley' ] );
		$zero = Venue_Taxonomy::create_or_get( [ 'name' => 'Null Island', 'latitude' => '0' ] );
		$post = $this->post_with_kind( 'checkin' );
		wp_set_object_terms( $post, [ (int) $full ], Venue_Taxonomy::TAXONOMY );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$result = $this->ability( 'post-kinds/list-venues' )->execute( [] );

		$this->assertIsArray( $result );
		$this->assertSame( 3, $result['total'] );
		$this->assertSame( [ (int) $bare, (int) $full, (int) $zero ], array_column( $result['venues'], 'id' ) );
		$this->assertSame(
			[
				'id'        => (int) $bare,
				'name'      => 'Back Alley',
				'slug'      => 'back-alley',
				'city'      => '',
				'country'   => '',
				'latitude'  => null,
				'longitude' => null,
				'count'     => 0,
			],
			$result['venues'][0]
		);
		$this->assertSame( 40.7829, $result['venues'][1]['latitude'] );
		$this->assertSame( -73.9654, $result['venues'][1]['longitude'] );
		$this->assertSame( 1, $result['venues'][1]['count'] );
		$this->assertSame( 0.0, $result['venues'][2]['latitude'] );
		$this->assertNull( $result['venues'][2]['longitude'] );
	}

	public function test_list_venues_returns_empty_collection_when_none_exist(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$result = $this->ability( 'post-kinds/list-venues' )->execute( [] );

		$this->assertSame( [ 'venues' => [], 'total' => 0 ], $result );
	}

	public function test_list_venues_ids_names_and_counts_match_cli_rows(): void {
		$one  = Venue_Taxonomy::create_or_get( [ 'name' => 'One Place' ] );
		$two  = Venue_Taxonomy::create_or_get( [ 'name' => 'Two Place' ] );
		$post = $this->post_with_kind( 'checkin' );
		wp_set_object_terms( $post, [ (int) $two ], Venue_Taxonomy::TAXONOMY );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$result = $this->ability( 'post-kinds/list-venues' )->execute( [] );
		$calls  = $this->transcript( static fn() => ( new CLI_Commands() )->venues( [], [] ) );

		$this->assertSame( 'format_items', $calls[0][0] );
		$cli_rows     = array_map( [ $this, 'identity_columns' ], $calls[0][1]['items'] );
		$ability_rows = array_map( [ $this, 'identity_columns' ], $result['venues'] );
		$this->assertSame( [ (int) $one, (int) $two ], array_column( $ability_rows, 'id' ) );
		$this->assertSame( $ability_rows, $cli_rows );
	}

	/**
	 * HTTP stub for featured artwork sideloading.
	 *
	 * @param mixed                $preempt     Existing preemption value.
	 * @param array<string, mixed> $parsed_args HTTP arguments.
	 * @param string               $url         Request URL.
	 * @return array<string, mixed>
	 */
	public function stub_artwork_request( $preempt, array $parsed_args, string $url ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
		++$this->http_requests;
		$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' );
		if ( ! empty( $parsed_args['filename'] ) ) {
			file_put_contents( $parsed_args['filename'], $png ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test HTTP stream stub.
		}
		return [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'headers'  => [ 'content-type' => 'image/png' ],
			'body'     => $png,
			'cookies'  => [],
		];
	}

	/**
	 * Fetch one registered ability.
	 *
	 * @return WP_Ability
	 */
	private function ability( string $name ) {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			$this->markTestSkipped( 'Abilities API not available on this WordPress version (needs 6.9+).' );
		}
		$ability = wp_get_ability( $name );
		$this->assertNotNull( $ability, sprintf( 'Ability "%s" must be registered.', $name ) );
		return $ability;
	}

	/**
	 * @return string[]
	 */
	private function ability_names(): array {
		return array_merge( $this->write_names(), $this->read_names() );
	}

	/**
	 * @return string[]
	 */
	private function write_names(): array {
		return [
			'post-kinds/backfill-surfaces',
			'post-kinds/backfill-card-meta',
			'post-kinds/backfill-listen-media-type',
			'post-kinds/backfill-featured-artwork',
		];
	}

	/**
	 * @return string[]
	 */
	private function read_names(): array {
		return [
			'post-kinds/get-checkin-stats',
			'post-kinds/list-venues',
			'post-kinds/get-surface',
			'post-kinds/get-public-location-fields',
		];
	}

	/**
	 * Create a post with one kind.
	 *
	 * @param string               $kind Kind slug.
	 * @param array<string, mixed> $args Post overrides.
	 */
	private function post_with_kind( string $kind, array $args = [] ): int {
		$post_id = self::factory()->post->create( array_merge( [ 'post_status' => 'publish' ], $args ) );
		wp_set_object_terms( $post_id, $kind, Taxonomy::TAXONOMY );
		return $post_id;
	}

	private function listen_card_post( string $track ): int {
		return self::factory()->post->create(
			[
				'post_content' => '<!-- wp:post-kinds-indieweb/listen-card {"trackTitle":"' . $track . '"} /-->',
			]
		);
	}

	private function listen_candidate( string $title ): int {
		$post_id = $this->post_with_kind( 'listen', [ 'post_title' => $title ] );
		update_post_meta( $post_id, '_pkiw_listen_artist', 'The Example Show' );
		update_post_meta( $post_id, '_pkiw_listen_album', 'The Example Show' );
		update_post_meta( $post_id, '_pkiw_source', 'Snipd' );
		return $post_id;
	}

	private function listen_artwork_post(): int {
		$content = '<!-- wp:post-kinds-indieweb/listen-card {"trackTitle":"One","coverImage":"' . self::COVER . '"} /-->';
		$post_id = self::factory()->post->create( [ 'post_title' => 'One', 'post_content' => $content ] );
		wp_set_object_terms( $post_id, 'listen', Taxonomy::TAXONOMY );
		return $post_id;
	}

	/**
	 * Run a CLI command and return the stub transcript.
	 *
	 * @return array<int, array{0:string,1:mixed}>
	 */
	private function transcript( callable $command ): array {
		WP_CLI::reset();
		try {
			$command();
		} catch ( WP_CLI_Stub_Halt $halt ) {
			unset( $halt );
		}
		return WP_CLI::$calls;
	}

	/**
	 * Keep the identity columns shared by the ability and CLI.
	 *
	 * @param array<string, mixed> $row Venue row.
	 * @return array{id:int,name:string,count:int}
	 */
	public function identity_columns( array $row ): array {
		return [
			'id'    => $row['id'],
			'name'  => $row['name'],
			'count' => $row['count'],
		];
	}

	/**
	 * Assert a WP_Error has one exact code.
	 *
	 * @param string $code   Expected code.
	 * @param mixed  $result Ability result.
	 */
	private function assertErrorCode( string $code, $result ): void {
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
	}
}
