<?php
/**
 * Test the Checkin Sync Base class.
 *
 * @package PKIW
 */

namespace PKIW\Tests\Unit;

use PKIW\Sync\Checkin_Sync_Base;
use WP_UnitTestCase;

/**
 * Concrete test stub for the abstract Checkin_Sync_Base.
 */
class Test_Checkin_Sync extends Checkin_Sync_Base {

	/**
	 * Whether the service reports as connected.
	 *
	 * @var bool
	 */
	public bool $connected = true;

	/**
	 * Syndication result to return.
	 *
	 * @var array|false
	 */
	public $syndication_result = false;

	/**
	 * Import result to return.
	 *
	 * @var int|false
	 */
	public $import_result = false;

	/**
	 * Recent checkins to return.
	 *
	 * @var array
	 */
	public array $recent_checkins = [];

	/**
	 * Recorded syndicate calls.
	 *
	 * @var array
	 */
	public array $syndicate_calls = [];

	/**
	 * Recorded import calls.
	 *
	 * @var array
	 */
	public array $import_calls = [];

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->service_id   = 'testservice';
		$this->service_name = 'Test Service';
		parent::__construct();
	}

	public function register_routes(): void {}

	public function is_connected(): bool {
		return $this->connected;
	}

	public function get_auth_url(): string {
		return 'https://testservice.com/auth';
	}

	public function handle_oauth_callback( string $code ): bool {
		return true;
	}

	protected function syndicate_checkin( int $post_id, array $checkin_data ) {
		$this->syndicate_calls[] = [ 'post_id' => $post_id, 'data' => $checkin_data ];
		return $this->syndication_result;
	}

	protected function import_checkin( array $external_checkin ) {
		$this->import_calls[] = $external_checkin;
		return $this->import_result;
	}

	public function fetch_recent_checkins( int $limit = 50 ): array {
		return $this->recent_checkins;
	}

	// Expose protected methods for testing.
	public function test_is_checkin_post( int $post_id ): bool {
		return $this->is_checkin_post( $post_id );
	}

	public function test_is_syndication_enabled( int $post_id ): bool {
		return $this->is_syndication_enabled( $post_id );
	}

	public function test_is_already_syndicated( int $post_id ): bool {
		return $this->is_already_syndicated( $post_id );
	}

	public function test_was_imported_from_service( int $post_id ): bool {
		return $this->was_imported_from_service( $post_id );
	}

	public function test_checkin_exists( array $data ): bool {
		return $this->checkin_exists( $data );
	}

	public function test_get_checkin_data_from_post( int $post_id ): array {
		return $this->get_checkin_data_from_post( $post_id );
	}

	public function test_add_syndication_link( int $post_id, string $url ): void {
		$this->add_syndication_link( $post_id, $url );
	}
}

/**
 * Test the Checkin Sync Base class.
 *
 * @covers \PKIW\Sync\Checkin_Sync_Base
 */
class CheckinSyncBaseTest extends WP_UnitTestCase {

	/**
	 * Test sync instance.
	 *
	 * @var Test_Checkin_Sync
	 */
	private Test_Checkin_Sync $sync;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->sync = new Test_Checkin_Sync();
	}

	/**
	 * Test constructor sets meta keys.
	 */
	public function test_constructor_sets_meta_keys(): void {
		$this->assertSame( 'testservice', $this->sync->get_service_id() );
		$this->assertSame( 'Test Service', $this->sync->get_service_name() );
	}

	/**
	 * Create a post with the checkin kind taxonomy.
	 *
	 * @return int Post ID.
	 */
	private function create_checkin_post(): int {
		$post_id = self::factory()->post->create( [ 'post_type' => 'post' ] );

		// Register taxonomy if not already.
		if ( ! taxonomy_exists( 'kind' ) ) {
			register_taxonomy( 'kind', 'post' );
		}

		// Ensure the 'checkin' term exists.
		if ( ! term_exists( 'checkin', 'kind' ) ) {
			wp_insert_term( 'checkin', 'kind' );
		}

		wp_set_post_terms( $post_id, [ 'checkin' ], 'kind' );

		return $post_id;
	}

	/**
	 * Test is_checkin_post returns true for checkin posts.
	 */
	public function test_is_checkin_post_returns_true(): void {
		$post_id = $this->create_checkin_post();

		$this->assertTrue( $this->sync->test_is_checkin_post( $post_id ) );
	}

	/**
	 * Test is_checkin_post returns false for non-checkin posts.
	 */
	public function test_is_checkin_post_returns_false_for_other(): void {
		$post_id = self::factory()->post->create();

		$this->assertFalse( $this->sync->test_is_checkin_post( $post_id ) );
	}

	/**
	 * Test is_syndication_enabled returns false without settings.
	 */
	public function test_is_syndication_enabled_false_without_settings(): void {
		$post_id = self::factory()->post->create();

		$this->assertFalse( $this->sync->test_is_syndication_enabled( $post_id ) );
	}

	/**
	 * Test is_syndication_enabled returns true with proper config.
	 */
	public function test_is_syndication_enabled_true_with_config(): void {
		$post_id = self::factory()->post->create();

		update_option( 'pkiw_settings', [
			'checkin_sync_to_testservice' => true,
		] );

		$this->assertTrue( $this->sync->test_is_syndication_enabled( $post_id ) );
	}

	/**
	 * Test is_syndication_enabled returns false when post opts out.
	 */
	public function test_is_syndication_enabled_false_on_post_opt_out(): void {
		$post_id = self::factory()->post->create();

		update_option( 'pkiw_settings', [
			'checkin_sync_to_testservice' => true,
		] );

		update_post_meta( $post_id, '_pkiw_syndicate_testservice', '0' );

		$this->assertFalse( $this->sync->test_is_syndication_enabled( $post_id ) );
	}

	/**
	 * Test is_syndication_enabled returns false when not connected.
	 */
	public function test_is_syndication_enabled_false_when_disconnected(): void {
		$post_id = self::factory()->post->create();

		update_option( 'pkiw_settings', [
			'checkin_sync_to_testservice' => true,
		] );

		$this->sync->connected = false;

		$this->assertFalse( $this->sync->test_is_syndication_enabled( $post_id ) );
	}

	/**
	 * Test is_already_syndicated returns false for new post.
	 */
	public function test_is_already_syndicated_false_for_new(): void {
		$post_id = self::factory()->post->create();

		$this->assertFalse( $this->sync->test_is_already_syndicated( $post_id ) );
	}

	/**
	 * Test is_already_syndicated returns true with external ID.
	 */
	public function test_is_already_syndicated_true_with_id(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_pkiw_checkin_testservice_id', 'ext-123' );

		$this->assertTrue( $this->sync->test_is_already_syndicated( $post_id ) );
	}

	/**
	 * Test was_imported_from_service with matching service.
	 */
	public function test_was_imported_from_service_true(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_pkiw_imported_from', 'testservice' );

		$this->assertTrue( $this->sync->test_was_imported_from_service( $post_id ) );
	}

	/**
	 * Test was_imported_from_service with different service.
	 */
	public function test_was_imported_from_service_false_different(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_pkiw_imported_from', 'otherservice' );

		$this->assertFalse( $this->sync->test_was_imported_from_service( $post_id ) );
	}

	/**
	 * Test was_imported_from_service false when no meta.
	 */
	public function test_was_imported_from_service_false_no_meta(): void {
		$post_id = self::factory()->post->create();

		$this->assertFalse( $this->sync->test_was_imported_from_service( $post_id ) );
	}

	/**
	 * Import_Manager marks its imports with "{source}:{id}" and a source
	 * name, not the service ID, in `_pkiw_imported_from`.
	 *
	 * @dataProvider import_identity_provider
	 *
	 * @param string $identity `_pkiw_import_source_id` value.
	 * @param bool   $expected Whether the post came from this service.
	 */
	public function test_was_imported_from_service_reads_the_import_manager_identity( string $identity, bool $expected ): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_pkiw_imported_from', 'Test Service / App' );
		update_post_meta( $post_id, '_pkiw_import_source_id', $identity );

		$this->assertSame( $expected, $this->sync->test_was_imported_from_service( $post_id ) );
	}

	/**
	 * Identities and whether they belong to the `testservice` stub.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function import_identity_provider(): array {
		return [
			'this service'            => [ 'testservice:x', true ],
			'another service'         => [ 'otherservice:x', false ],
			'a longer service prefix' => [ 'testservicefoo:x', false ],
			'no separator'            => [ 'testservice', false ],
		];
	}

	/**
	 * The loop guard stops an Import_Manager check-in at any publish.
	 */
	public function test_maybe_syndicate_skips_an_import_manager_checkin(): void {
		$post_id = $this->create_checkin_post();
		$post    = get_post( $post_id );

		update_option( 'pkiw_settings', [
			'checkin_sync_to_testservice' => true,
		] );
		update_post_meta( $post_id, '_pkiw_imported_from', 'Test Service / App' );
		update_post_meta( $post_id, '_pkiw_import_source_id', 'testservice:x' );

		$this->sync->syndication_result = [ 'id' => 'ext-1' ];
		$this->sync->maybe_syndicate_checkin( 'publish', 'draft', $post );
		$this->sync->maybe_syndicate_checkin( 'publish', 'publish', $post );

		$this->assertEmpty( $this->sync->syndicate_calls );
	}

	/**
	 * A check-in written on the site within five minutes at the same venue
	 * is the same check-in, matched on the normalized `timestamp` and
	 * `venue_name` keys and the post's GMT date.
	 */
	public function test_checkin_exists_matches_a_site_checkin_at_the_same_venue_and_time(): void {
		update_option( 'timezone_string', 'America/New_York' );
		$this->create_site_checkin( 1789243200 + 120, 'Blue Bottle Coffee' );

		$this->assertTrue(
			$this->sync->test_checkin_exists(
				[
					'id'         => 'new-id',
					'timestamp'  => 1789243200,
					'venue_name' => 'Blue Bottle Coffee',
				]
			)
		);
	}

	/**
	 * A post more than five minutes away isn't a match.
	 */
	public function test_checkin_exists_ignores_a_site_checkin_outside_the_window(): void {
		$this->create_site_checkin( 1789243200 + 900, 'Blue Bottle Coffee' );

		$this->assertFalse(
			$this->sync->test_checkin_exists(
				[
					'id'         => 'new-id',
					'timestamp'  => 1789243200,
					'venue_name' => 'Blue Bottle Coffee',
				]
			)
		);
	}

	/**
	 * An earlier import of a different check-in at the same venue, such as
	 * a second beer at the same bar, isn't a duplicate of this one.
	 *
	 * @dataProvider other_checkin_meta_provider
	 *
	 * @param string $key   Meta key the earlier import carries.
	 * @param string $value Its value.
	 */
	public function test_checkin_exists_ignores_another_known_checkin_at_the_same_venue( string $key, string $value ): void {
		$post_id = $this->create_site_checkin( 1789243200 + 120, 'Blue Bottle Coffee' );
		update_post_meta( $post_id, $key, $value );

		$this->assertFalse(
			$this->sync->test_checkin_exists(
				[
					'id'         => 'new-id',
					'timestamp'  => 1789243200,
					'venue_name' => 'Blue Bottle Coffee',
				]
			)
		);
	}

	/**
	 * Meta that names an earlier post's own check-in.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function other_checkin_meta_provider(): array {
		return [
			'sync check-in ID'        => [ '_pkiw_checkin_testservice_id', 'other-id' ],
			'Import_Manager identity' => [ '_pkiw_import_source_id', 'testservice:other-id' ],
		];
	}

	/**
	 * A check-in post written on the site at a GMT time.
	 *
	 * @param int    $timestamp Unix time.
	 * @param string $venue     Venue name.
	 * @return int Post ID.
	 */
	private function create_site_checkin( int $timestamp, string $venue ): int {
		$gmt     = gmdate( 'Y-m-d H:i:s', $timestamp );
		$post_id = self::factory()->post->create(
			[
				'post_date'     => get_date_from_gmt( $gmt ),
				'post_date_gmt' => $gmt,
			]
		);
		update_post_meta( $post_id, '_pkiw_checkin_name', $venue );

		return $post_id;
	}

	/**
	 * Test checkin_exists returns false with empty ID.
	 */
	public function test_checkin_exists_false_empty_id(): void {
		$this->assertFalse( $this->sync->test_checkin_exists( [] ) );
	}

	/**
	 * Test checkin_exists detects by external ID.
	 */
	public function test_checkin_exists_by_external_id(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_pkiw_checkin_testservice_id', 'ext-456' );

		$this->assertTrue( $this->sync->test_checkin_exists( [ 'id' => 'ext-456' ] ) );
	}

	/**
	 * Test checkin_exists returns false when no match.
	 */
	public function test_checkin_exists_false_no_match(): void {
		$this->assertFalse( $this->sync->test_checkin_exists( [ 'id' => 'no-such-id' ] ) );
	}

	/**
	 * Test get_checkin_data_from_post extracts meta.
	 */
	public function test_get_checkin_data_from_post(): void {
		$post_id = self::factory()->post->create();

		update_post_meta( $post_id, '_pkiw_checkin_name', 'Coffee Shop' );
		update_post_meta( $post_id, '_pkiw_checkin_locality', 'Portland' );
		update_post_meta( $post_id, '_pkiw_checkin_region', 'OR' );
		update_post_meta( $post_id, '_pkiw_geo_latitude', '45.5' );
		update_post_meta( $post_id, '_pkiw_geo_longitude', '-122.6' );

		$data = $this->sync->test_get_checkin_data_from_post( $post_id );

		$this->assertSame( 'Coffee Shop', $data['venue_name'] );
		$this->assertSame( 'Portland', $data['locality'] );
		$this->assertSame( 'OR', $data['region'] );
		$this->assertSame( '45.5', $data['latitude'] );
		$this->assertSame( '-122.6', $data['longitude'] );
	}

	/**
	 * The Foursquare venue ID for POSSE comes from `_pkiw_checkin_venue_id`.
	 */
	public function test_get_checkin_data_reads_the_venue_id(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_pkiw_checkin_venue_id', 'v1' );

		$data = $this->sync->test_get_checkin_data_from_post( $post_id );

		$this->assertSame( 'v1', $data['foursquare_id'] );
	}

	/**
	 * `_pkiw_checkin_foursquare_id` holds a check-in ID, never sent as a venue.
	 */
	public function test_get_checkin_data_does_not_send_the_checkin_id_as_a_venue(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_pkiw_checkin_foursquare_id', 'fsq-checkin-789' );

		$data = $this->sync->test_get_checkin_data_from_post( $post_id );

		$this->assertArrayNotHasKey( 'foursquare_id', $data );
	}

	/**
	 * Test add_syndication_link stores meta.
	 */
	public function test_add_syndication_link_stores_meta(): void {
		$post_id = self::factory()->post->create();

		$this->sync->test_add_syndication_link( $post_id, 'https://testservice.com/checkin/123' );

		$this->assertSame(
			'https://testservice.com/checkin/123',
			get_post_meta( $post_id, '_pkiw_syndication_testservice', true )
		);
	}

	/**
	 * Test maybe_syndicate_checkin skips non-publish status.
	 */
	public function test_maybe_syndicate_skips_non_publish(): void {
		$post = self::factory()->post->create_and_get();

		$this->sync->syndication_result = [ 'id' => 'ext-1', 'url' => 'https://test.com/1' ];
		$this->sync->maybe_syndicate_checkin( 'draft', 'draft', $post );

		$this->assertEmpty( $this->sync->syndicate_calls );
	}

	/**
	 * Test maybe_syndicate_checkin skips non-post types.
	 */
	public function test_maybe_syndicate_skips_non_post_type(): void {
		$post = self::factory()->post->create_and_get( [ 'post_type' => 'page' ] );

		$this->sync->syndication_result = [ 'id' => 'ext-1' ];
		$this->sync->maybe_syndicate_checkin( 'publish', 'draft', $post );

		$this->assertEmpty( $this->sync->syndicate_calls );
	}

	/**
	 * Test maybe_syndicate_checkin skips already syndicated.
	 */
	public function test_maybe_syndicate_skips_already_syndicated(): void {
		$post_id = $this->create_checkin_post();
		$post    = get_post( $post_id );

		update_option( 'pkiw_settings', [
			'checkin_sync_to_testservice' => true,
		] );
		update_post_meta( $post_id, '_pkiw_checkin_testservice_id', 'already-done' );

		$this->sync->syndication_result = [ 'id' => 'ext-1' ];
		$this->sync->maybe_syndicate_checkin( 'publish', 'draft', $post );

		$this->assertEmpty( $this->sync->syndicate_calls );
	}

	/**
	 * Test maybe_syndicate_checkin skips imported posts.
	 */
	public function test_maybe_syndicate_skips_imported(): void {
		$post_id = $this->create_checkin_post();
		$post    = get_post( $post_id );

		update_option( 'pkiw_settings', [
			'checkin_sync_to_testservice' => true,
		] );
		update_post_meta( $post_id, '_pkiw_imported_from', 'testservice' );

		$this->sync->syndication_result = [ 'id' => 'ext-1' ];
		$this->sync->maybe_syndicate_checkin( 'publish', 'draft', $post );

		$this->assertEmpty( $this->sync->syndicate_calls );
	}

	/**
	 * Test maybe_syndicate_checkin performs syndication.
	 */
	public function test_maybe_syndicate_performs_syndication(): void {
		$post_id = $this->create_checkin_post();
		$post    = get_post( $post_id );

		update_option( 'pkiw_settings', [
			'checkin_sync_to_testservice' => true,
		] );
		update_post_meta( $post_id, '_pkiw_checkin_name', 'Test Venue' );

		$this->sync->syndication_result = [ 'id' => 'ext-99', 'url' => 'https://test.com/99' ];
		$this->sync->maybe_syndicate_checkin( 'publish', 'draft', $post );

		$this->assertCount( 1, $this->sync->syndicate_calls );
		$this->assertSame(
			'ext-99',
			get_post_meta( $post_id, '_pkiw_checkin_testservice_id', true )
		);
		$this->assertSame(
			'https://test.com/99',
			get_post_meta( $post_id, '_pkiw_syndication_testservice', true )
		);
	}

	/**
	 * Test import_checkins batch flow.
	 */
	public function test_import_checkins_batch(): void {
		$this->sync->recent_checkins = [
			[ 'id' => 'new-1', 'venue' => [ 'name' => 'Place A' ] ],
			[ 'id' => 'new-2', 'venue' => [ 'name' => 'Place B' ] ],
		];

		// First import succeeds, second fails.
		$call_count          = 0;
		$this->sync->import_result = false;

		// Override to track per-call results.
		$results = $this->sync->import_checkins( 50 );

		$this->assertSame( 2, $results['total'] );
		$this->assertArrayHasKey( 'imported', $results );
		$this->assertArrayHasKey( 'skipped', $results );
		$this->assertArrayHasKey( 'errors', $results );
	}

	/**
	 * Test import_checkins skips duplicates.
	 */
	public function test_import_checkins_skips_duplicates(): void {
		// Create a post with existing external ID.
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_pkiw_checkin_testservice_id', 'dup-1' );

		$this->sync->recent_checkins = [
			[ 'id' => 'dup-1' ],
		];

		$results = $this->sync->import_checkins( 50 );

		$this->assertSame( 1, $results['skipped'] );
		$this->assertSame( 0, $results['imported'] );
	}

	/**
	 * Test add_syndication_target when connected.
	 */
	public function test_add_syndication_target_connected(): void {
		$targets = $this->sync->add_syndication_target( [] );

		$this->assertArrayHasKey( 'testservice', $targets );
		$this->assertSame( 'Test Service', $targets['testservice']['name'] );
	}

	/**
	 * Test add_syndication_target when disconnected.
	 */
	public function test_add_syndication_target_disconnected(): void {
		$this->sync->connected = false;

		$targets = $this->sync->add_syndication_target( [] );

		$this->assertArrayNotHasKey( 'testservice', $targets );
	}
}
