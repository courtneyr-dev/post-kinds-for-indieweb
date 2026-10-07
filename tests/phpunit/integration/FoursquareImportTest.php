<?php
/**
 * Foursquare check-ins keep their venue, date and coordinates on every path.
 *
 * Import_Manager's `foursquare` source passed raw users/self/checkins items
 * to build_checkin_payload(), which reads normalized keys, so scheduled
 * imports were titled "Checked in at Unknown Venue", dated at import time
 * and had no location. The sync class wrote the venue ID to
 * `_pkiw_checkin_foursquare_id`, where the base class then wrote the
 * check-in ID over it. These tests run as user 0 outside wp-admin, on an
 * America/New_York site (#284).
 *
 * @package PKIW\Tests
 */

namespace PKIW\Tests\Integration;

use PKIW\Admin\Import_Page;
use PKIW\APIs\API_Base;
use PKIW\Import_Manager;
use PKIW\Plugin;
use PKIW\Sync\Foursquare_Checkin_Sync;
use PKIW\Tests\ApiTestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Foursquare import through Import_Manager, the sync class and the preview.
 */
final class FoursquareImportTest extends ApiTestCase {

	/**
	 * The fixture check-in's createdAt.
	 */
	private const CREATED_AT = 1789243200;

	/**
	 * Check-in sync services the plugin booted with.
	 *
	 * @var array<string, mixed>
	 */
	private array $booted_services = [];

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( 0 );
		update_option( 'timezone_string', 'America/New_York' );

		update_option(
			'pkiw_api_credentials',
			[
				'foursquare' => [ 'access_token' => 'fake-foursquare-token' ],
			]
		);

		$this->mock_http_response(
			'api.foursquare.com/v2/users/self/checkins',
			[
				'response' => [
					'checkins' => [
						'items' => [ $this->foursquare_checkin() ],
					],
				],
			]
		);

		$services              = new ReflectionProperty( Plugin::class, 'checkin_sync_services' );
		$this->booted_services = $services->getValue( Plugin::get_instance() );
	}

	public function tear_down(): void {
		( new ReflectionProperty( Plugin::class, 'checkin_sync_services' ) )->setValue( Plugin::get_instance(), $this->booted_services );
		parent::tear_down();
	}

	public function test_import_manager_keeps_the_venue_and_its_coordinates(): void {
		$post = $this->import_manager_post();

		$this->assertSame( 'Checked in at Blue Bottle Coffee', $post->post_title );
		$this->assertSame( 'Blue Bottle Coffee', get_post_meta( $post->ID, '_pkiw_checkin_name', true ) );
		$this->assertSame( '1 Main St', get_post_meta( $post->ID, '_pkiw_checkin_address', true ) );
		$this->assertSame( 'Oakland', get_post_meta( $post->ID, '_pkiw_checkin_locality', true ) );
		$this->assertSame( 'CA', get_post_meta( $post->ID, '_pkiw_checkin_region', true ) );
		$this->assertSame( 'United States', get_post_meta( $post->ID, '_pkiw_checkin_country', true ) );
		$this->assertEqualsWithDelta( 37.8, (float) get_post_meta( $post->ID, '_pkiw_geo_latitude', true ), 0.0001 );
		$this->assertEqualsWithDelta( -122.27, (float) get_post_meta( $post->ID, '_pkiw_geo_longitude', true ), 0.0001 );
		$this->assertSame( 'venue-fsq-checkin-1', get_post_meta( $post->ID, '_pkiw_checkin_venue_id', true ) );
	}

	public function test_import_manager_dates_the_post_from_the_checkin_in_the_site_timezone(): void {
		$post = $this->import_manager_post();

		$this->assertSame( gmdate( 'Y-m-d H:i:s', self::CREATED_AT ), $post->post_date_gmt );
		$this->assertSame( get_date_from_gmt( $post->post_date_gmt ), $post->post_date );
		$this->assertNotSame( $post->post_date_gmt, $post->post_date, 'post_date holds the GMT time on an America/New_York site.' );
	}

	/**
	 * The importer applies the check-in privacy default, as the sync does.
	 *
	 * @dataProvider privacy_default_provider
	 *
	 * @param array<string, mixed> $settings pkiw_settings.
	 * @param string               $expected Stored _pkiw_geo_privacy.
	 */
	public function test_import_manager_applies_the_default_privacy( array $settings, string $expected ): void {
		update_option( 'pkiw_settings', $settings );

		$post = $this->import_manager_post();

		$this->assertSame( $expected, get_post_meta( $post->ID, '_pkiw_geo_privacy', true ) );
	}

	/**
	 * The body is the shout, so a venue the privacy setting hides doesn't
	 * sit in post_content where Title_Privacy can't reach it.
	 *
	 * @dataProvider privacy_default_provider
	 *
	 * @param array<string, mixed> $settings pkiw_settings.
	 */
	public function test_import_manager_body_never_names_the_venue( array $settings ): void {
		update_option( 'pkiw_settings', $settings );

		$post = $this->import_manager_post();

		$this->assertStringContainsString( 'Coffee.', $post->post_content );
		$this->assertStringNotContainsString( 'Blue Bottle', $post->post_content );
	}

	/**
	 * Publishing an imported check-in, or saving it again once published,
	 * must not post it back to Foursquare as a new check-in.
	 *
	 * @dataProvider publish_flow_provider
	 *
	 * @param string $flow How the import gets published.
	 */
	public function test_an_import_manager_checkin_is_not_syndicated_back( string $flow ): void {
		$this->enable_syndication();

		if ( 'draft published later' === $flow ) {
			$post = $this->import_manager_post();
			$this->hook_a_connected_sync();
			wp_publish_post( $post->ID );
		} else {
			$post = $this->import_manager_post( 'publish' );
			$this->hook_a_connected_sync();
			wp_update_post(
				[
					'ID'           => $post->ID,
					'post_content' => '<!-- wp:paragraph --><p>Edited.</p><!-- /wp:paragraph -->',
				]
			);
		}

		$this->assertSame( 'publish', get_post_status( $post->ID ) );
		$this->assert_no_api_request( 'checkins/add' );
		$this->assert_no_api_request( 'venues/search' );
	}

	/**
	 * Control for the test above: a check-in written on the site still goes out.
	 */
	public function test_a_site_checkin_still_syndicates(): void {
		$this->enable_syndication();
		$this->hook_a_connected_sync();

		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_object_terms( $post_id, 'checkin', 'kind' );
		update_post_meta( $post_id, '_pkiw_checkin_name', 'Blue Bottle Coffee' );
		update_post_meta( $post_id, '_pkiw_checkin_venue_id', 'venue-fsq-checkin-1' );
		update_post_meta( $post_id, '_pkiw_geo_latitude', '37.8' );
		update_post_meta( $post_id, '_pkiw_geo_longitude', '-122.27' );

		wp_publish_post( $post_id );

		$this->assert_api_request_made( 'checkins/add' );
		$this->assert_no_api_request( 'venues/search' );
	}

	public function test_sync_import_keeps_the_venue_id_apart_from_the_checkin_id(): void {
		$post = $this->sync_post();

		$this->assertSame( 'fsq-checkin-1', get_post_meta( $post->ID, '_pkiw_checkin_foursquare_id', true ) );
		$this->assertSame( 'venue-fsq-checkin-1', get_post_meta( $post->ID, '_pkiw_checkin_venue_id', true ) );
	}

	public function test_sync_import_dates_the_post_from_the_checkin_in_the_site_timezone(): void {
		$post = $this->sync_post();

		$this->assertSame( gmdate( 'Y-m-d H:i:s', self::CREATED_AT ), $post->post_date_gmt );
		$this->assertSame( get_date_from_gmt( $post->post_date_gmt ), $post->post_date );
	}

	public function test_import_page_preview_shows_the_venue_and_date(): void {
		( new ReflectionProperty( Plugin::class, 'checkin_sync_services' ) )->setValue(
			Plugin::get_instance(),
			[ 'foursquare' => new Foursquare_Checkin_Sync() ] + $this->booted_services
		);

		$page = ( new ReflectionClass( Import_Page::class ) )->newInstanceWithoutConstructor();
		( new ReflectionProperty( Import_Page::class, 'import_sources' ) )->setValue(
			$page,
			( new ReflectionMethod( Import_Page::class, 'get_import_sources' ) )->invoke( $page )
		);

		$preview = ( new ReflectionMethod( Import_Page::class, 'get_import_preview' ) )->invoke( $page, 'foursquare', [] );

		$this->assertIsArray( $preview );
		$this->assertSame(
			[
				'title'   => 'Blue Bottle Coffee',
				'address' => '1 Main St',
				'date'    => wp_date( 'M j, Y g:i a', self::CREATED_AT ),
			],
			$preview['sample'][0]
		);
	}

	/**
	 * Settings and the privacy each gives an imported check-in.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public function privacy_default_provider(): array {
		return [
			'setting unset' => [ [], 'approximate' ],
			'private'       => [ [ 'checkin_default_privacy' => 'private' ], 'private' ],
			'public'        => [ [ 'checkin_default_privacy' => 'public' ], 'public' ],
		];
	}

	/**
	 * Ways an imported check-in reaches a publish transition.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function publish_flow_provider(): array {
		return [
			'draft published later'   => [ 'draft published later' ],
			'published import resaved' => [ 'published import resaved' ],
		];
	}

	/**
	 * Run a Foursquare job the way Scheduled_Sync does and return its post.
	 *
	 * @param string $post_status Status the job creates posts with.
	 * @return \WP_Post
	 */
	private function import_manager_post( string $post_status = 'draft' ): \WP_Post {
		( new ReflectionProperty( API_Base::class, 'last_request_times' ) )->setValue( null, [] );

		$manager = new Import_Manager();
		$started = $manager->start_import(
			'foursquare',
			[
				'skip_existing'   => true,
				'update_existing' => false,
				'create_posts'    => true,
				'limit'           => 50,
				'post_status'     => $post_status,
			]
		);
		$this->assertTrue( $started['success'], $started['error'] ?? '' );

		$manager->process_import_batch( $started['job_id'], 'foursquare' );

		$job = $manager->get_job( $started['job_id'] );
		$this->assertSame( 1, $job['imported'], implode( '; ', $job['errors'] ) );

		return $this->only_post_with( '_pkiw_import_source_id', 'foursquare:fsq-checkin-1' );
	}

	/**
	 * Run the sync class's own import and return its post.
	 *
	 * @return \WP_Post
	 */
	private function sync_post(): \WP_Post {
		$result = ( new Foursquare_Checkin_Sync() )->import_checkins( 50 );
		$this->assertSame( 1, $result['imported'] );

		return $this->only_post_with( '_pkiw_imported_from', 'foursquare' );
	}

	/**
	 * Turn on POSSE to Foursquare and answer its API calls.
	 */
	private function enable_syndication(): void {
		update_option( 'pkiw_settings', [ 'checkin_sync_to_foursquare' => true ] );

		$this->mock_http_response(
			'api.foursquare.com/v2/venues/search',
			[ 'response' => [ 'venues' => [ [ 'id' => 'venue-fsq-checkin-1', 'name' => 'Blue Bottle Coffee' ] ] ] ]
		);
		$this->mock_http_response(
			'api.foursquare.com/v2/checkins/add',
			[
				'response' => [
					'checkin' => [
						'id'   => 'fsq-duplicate',
						'user' => [ 'id' => 'u1' ],
					],
				],
			]
		);
	}

	/**
	 * Hook a sync instance that read the connected credentials.
	 *
	 * The plugin's own instance read credentials at boot, before this test
	 * set them, so it reports disconnected and never syndicates.
	 */
	private function hook_a_connected_sync(): void {
		$sync = new Foursquare_Checkin_Sync();
		$this->assertTrue( $sync->is_connected() );
		$sync->init();
	}

	/**
	 * The single post, any status, with a meta value.
	 *
	 * @param string $key   Meta key.
	 * @param string $value Meta value.
	 * @return \WP_Post
	 */
	private function only_post_with( string $key, string $value ): \WP_Post {
		$ids = get_posts(
			[
				'post_type'   => 'any',
				'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future' ],
				'meta_key'    => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'numberposts' => -1,
				'fields'      => 'ids',
			]
		);
		$this->assertCount( 1, $ids );

		return get_post( $ids[0] );
	}

	/**
	 * A Foursquare check-in as users/self/checkins returns it.
	 *
	 * @return array<string, mixed>
	 */
	private function foursquare_checkin(): array {
		return [
			'id'        => 'fsq-checkin-1',
			'createdAt' => self::CREATED_AT,
			'shout'     => 'Coffee.',
			'venue'     => [
				'id'       => 'venue-fsq-checkin-1',
				'name'     => 'Blue Bottle Coffee',
				'location' => [
					'address'    => '1 Main St',
					'city'       => 'Oakland',
					'state'      => 'CA',
					'country'    => 'United States',
					'postalCode' => '94607',
					'lat'        => 37.8,
					'lng'        => -122.27,
				],
			],
		];
	}
}
