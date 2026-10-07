<?php
/**
 * A slug WordPress derives from a hidden generated title names no venue.
 *
 * The safe title hid "Checked in at <venue>" on a private check-in, but
 * the slug WordPress built from the stored title kept the venue in every
 * link to the post: archive, feed, REST and oEmbed (issue 224).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Meta_Fields;
use PKIW\Title_Privacy;

/**
 * @group integration
 */
final class TitlePrivacySlugTest extends WP_UnitTestCase {

	private const VENUE = 'Sentinel Venue Zyx9';

	private const VENUE_SLUG = 'checked-in-at-sentinel-venue-zyx9';

	private const SAFE_SLUG = 'check-in-september-12-2026';

	public function set_up(): void {
		parent::set_up();
		( new Meta_Fields() )->register_meta_fields();
		wp_set_current_user( 0 );
	}

	/**
	 * A draft check-in with a generated title, its venue and a privacy tier.
	 *
	 * Drafts get no slug until they publish, so publishing it is the moment
	 * WordPress derives one.
	 *
	 * @param string $privacy _pkiw_geo_privacy value.
	 */
	private function generated_draft( string $privacy ): int {
		$post_id = self::factory()->post->create(
			[
				'post_status'   => 'draft',
				'post_title'    => 'Checked in at ' . self::VENUE,
				'post_date'     => '2026-09-12 14:30:00',
				'post_date_gmt' => '2026-09-12 14:30:00',
			]
		);
		wp_set_object_terms( $post_id, 'checkin', 'kind' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'checkin_name', self::VENUE );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_privacy', $privacy );
		Title_Privacy::mark_location_title( $post_id );

		return $post_id;
	}

	private function publish( int $post_id, array $extra = [] ): void {
		wp_update_post(
			array_merge(
				[
					'ID'          => $post_id,
					'post_status' => 'publish',
				],
				$extra
			)
		);
	}

	private function slug( int $post_id ): string {
		clean_post_cache( $post_id );

		return (string) get_post_field( 'post_name', $post_id );
	}

	public function test_publishing_a_private_generated_check_in_writes_no_venue_into_the_slug(): void {
		$post_id = $this->generated_draft( 'private' );

		$this->publish( $post_id );

		$this->assertSame( self::SAFE_SLUG, $this->slug( $post_id ) );
		$this->assertSame( [], get_post_meta( $post_id, '_wp_old_slug' ), 'The venue slug is never written, so nothing redirects from it.' );
		$this->assertSame( 'Checked in at ' . self::VENUE, get_post_field( 'post_title', $post_id ), 'stored title must stay intact' );
	}

	/**
	 * A Foursquare check-in imported with a private default, the way the
	 * sync does it: insert published, then kind, marker and meta.
	 *
	 * @return mixed The import's return value.
	 */
	private function import_private_checkin() {
		update_option( 'pkiw_settings', [ 'checkin_default_privacy' => 'private' ] );

		$sync   = ( new ReflectionClass( \PKIW\Sync\Foursquare_Checkin_Sync::class ) )->newInstanceWithoutConstructor();
		$method = new ReflectionMethod( $sync, 'import_checkin' );

		return $method->invoke(
			$sync,
			\PKIW\Sync\Foursquare_Checkin_Sync::normalize_checkin(
				[
					'id'        => 'sentinel-4sq-slug',
					'createdAt' => strtotime( '2026-09-12 14:30:00 UTC' ),
					'venue'     => [
						'id'       => 'v1',
						'name'     => self::VENUE,
						'location' => [ 'city' => 'Sentinelville' ],
					],
				]
			)
		);
	}

	/**
	 * A private check-in with a generated title, published in an earlier
	 * request with the venue slug.
	 */
	private function published_earlier_with_venue_slug(): int {
		global $wpdb;

		$post_id = $this->generated_draft( 'private' );
		$wpdb->update(
			$wpdb->posts,
			[
				'post_status' => 'publish',
				'post_name'   => self::VENUE_SLUG,
			],
			[ 'ID' => $post_id ]
		);
		clean_post_cache( $post_id );

		return $post_id;
	}

	/**
	 * The importers insert a published post first and write its location,
	 * privacy and title marker afterwards, so the slug exists before the
	 * privacy does.
	 */
	public function test_import_with_a_private_default_writes_no_venue_into_the_slug(): void {
		$post_id = $this->import_private_checkin();

		$this->assertIsInt( $post_id );
		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$this->assertSame( self::SAFE_SLUG, $this->slug( $post_id ) );
		$this->assertSame( [], get_post_meta( $post_id, '_wp_old_slug' ), 'The venue slug existed only during the insert, so nothing redirects from it.' );
	}

	/**
	 * A redirect from the venue slug would confirm a guessed venue: a
	 * request for /<date>/checked-in-at-<venue>/ would land on the private
	 * check-in.
	 */
	public function test_a_guessed_venue_url_does_not_redirect_to_the_private_check_in(): void {
		$this->set_permalink_structure( '/%year%/%monthnum%/%day%/%postname%/' );
		$this->import_private_checkin();

		$redirect = null;
		add_filter(
			'old_slug_redirect_url',
			static function ( $link ) use ( &$redirect ) {
				$redirect = $link;
				return false;
			}
		);

		$this->go_to( home_url( '/2026/09/12/' . self::VENUE_SLUG . '/' ) );
		$this->assertTrue( is_404() );

		wp_old_slug_redirect();

		$this->assertNull( $redirect );
	}

	/**
	 * The block editor publishes a draft and saves its meta in one REST
	 * request, so the slug is derived while privacy still says public.
	 */
	public function test_rest_publish_that_makes_the_location_private_keeps_no_venue_slug(): void {
		$post_id = $this->generated_draft( 'public' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$request->set_body_params(
			[
				'status' => 'publish',
				'meta'   => [ Meta_Fields::PREFIX . 'geo_privacy' => 'private' ],
			]
		);
		$this->assertSame( 200, rest_do_request( $request )->get_status() );

		$this->assertSame( self::SAFE_SLUG, $this->slug( $post_id ) );
		$this->assertSame( [], get_post_meta( $post_id, '_wp_old_slug' ) );
	}

	/**
	 * WordPress sets a new post's guid to its permalink before the importer
	 * writes privacy, and the RSS guid, Atom id and REST guid print it.
	 * Plain permalinks hide this: their guid is ?p=<id>.
	 */
	public function test_import_with_pretty_permalinks_writes_no_venue_into_the_guid(): void {
		$this->set_permalink_structure( '/%year%/%monthnum%/%day%/%postname%/' );

		$post_id = $this->import_private_checkin();
		clean_post_cache( $post_id );

		$this->assertSame( home_url( '/2026/09/12/' . self::SAFE_SLUG . '/' ), get_the_guid( $post_id ) );
	}

	public function test_marked_before_insert_with_pretty_permalinks_writes_no_venue_into_the_guid(): void {
		$this->set_permalink_structure( '/%year%/%monthnum%/%day%/%postname%/' );

		$post_id = wp_insert_post(
			[
				'post_status'   => 'publish',
				'post_title'    => 'Checked in at ' . self::VENUE,
				'post_date'     => '2026-09-12 14:30:00',
				'post_date_gmt' => '2026-09-12 14:30:00',
				'meta_input'    => [
					Title_Privacy::META_KEY                 => Title_Privacy::SOURCE_LOCATION,
					Meta_Fields::PREFIX . 'checkin_name' => self::VENUE,
					Meta_Fields::PREFIX . 'geo_privacy'  => 'private',
				],
			]
		);
		clean_post_cache( $post_id );

		$this->assertSame( home_url( '/2026/09/12/' . self::SAFE_SLUG . '/' ), get_the_guid( $post_id ) );
	}

	/**
	 * REST writes meta after it inserts the post, as the importers do.
	 */
	public function test_rest_create_published_with_private_meta_writes_no_venue_into_the_guid(): void {
		$this->set_permalink_structure( '/%year%/%monthnum%/%day%/%postname%/' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts' );
		$request->set_body_params(
			[
				'title'  => 'Checked in at ' . self::VENUE,
				'status' => 'publish',
				'date'   => '2026-09-12T14:30:00',
				'meta'   => [
					Meta_Fields::PREFIX . 'checkin_name' => self::VENUE,
					Meta_Fields::PREFIX . 'geo_privacy'  => 'private',
				],
			]
		);
		$response = rest_do_request( $request );
		$this->assertSame( 201, $response->get_status() );

		$post_id = (int) $response->get_data()['id'];
		clean_post_cache( $post_id );

		$this->assertSame( self::SAFE_SLUG, $this->slug( $post_id ) );
		$this->assertStringNotContainsString( 'sentinel-venue-zyx9', get_the_guid( $post_id ) );
	}

	/**
	 * Plugins that store a post's permalink at save time (Yoast SEO's
	 * indexables) need to hear about a slug rewritten after the save.
	 */
	public function test_a_replaced_slug_is_announced(): void {
		$heard = [];
		add_action(
			'pkiw_derived_slug_replaced',
			static function ( ...$args ) use ( &$heard ) {
				$heard[] = $args;
			},
			10,
			3
		);

		$post_id = $this->import_private_checkin();

		$this->assertSame( [ [ $post_id, self::VENUE_SLUG, self::SAFE_SLUG ] ], $heard );
	}

	/**
	 * Marker and privacy written by wp_insert_post() itself through
	 * meta_input, after the row and its derived slug are written.
	 */
	public function test_marked_before_insert_gets_the_safe_slug(): void {
		$post_id = wp_insert_post(
			[
				'post_status'   => 'publish',
				'post_title'    => 'Checked in at ' . self::VENUE,
				'post_date'     => '2026-09-12 14:30:00',
				'post_date_gmt' => '2026-09-12 14:30:00',
				'meta_input'    => [
					Title_Privacy::META_KEY                 => Title_Privacy::SOURCE_LOCATION,
					Meta_Fields::PREFIX . 'checkin_name' => self::VENUE,
					Meta_Fields::PREFIX . 'geo_privacy'  => 'private',
				],
			]
		);

		$this->assertSame( self::SAFE_SLUG, $this->slug( $post_id ) );
		$this->assertSame( [], get_post_meta( $post_id, '_wp_old_slug' ), 'The row is written with the venue slug before meta_input runs, but nothing redirects from it.' );
	}

	/**
	 * Inserted published, then privacy, then the marker: the order an
	 * importer that marks last would use.
	 */
	public function test_marked_after_insert_gets_the_safe_slug_and_no_redirect(): void {
		$post_id = self::factory()->post->create(
			[
				'post_status'   => 'publish',
				'post_title'    => 'Checked in at ' . self::VENUE,
				'post_date'     => '2026-09-12 14:30:00',
				'post_date_gmt' => '2026-09-12 14:30:00',
			]
		);
		$this->assertSame( self::VENUE_SLUG, $this->slug( $post_id ), 'WordPress derives the venue slug at insert.' );

		update_post_meta( $post_id, Meta_Fields::PREFIX . 'checkin_name', self::VENUE );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_privacy', 'private' );
		Title_Privacy::mark_location_title( $post_id );

		$this->assertSame( self::SAFE_SLUG, $this->slug( $post_id ) );
		$this->assertSame( [], get_post_meta( $post_id, '_wp_old_slug' ) );
	}

	public function test_two_private_check_ins_on_one_day_get_distinct_slugs(): void {
		$first  = $this->generated_draft( 'private' );
		$second = $this->generated_draft( 'private' );

		$this->publish( $first );
		$this->publish( $second );

		$this->assertSame( self::SAFE_SLUG, $this->slug( $first ) );
		$this->assertSame( self::SAFE_SLUG . '-2', $this->slug( $second ) );
	}

	/**
	 * Approximate keeps the venue name visible, so the safe title is the
	 * stored title and so is the slug.
	 *
	 * @dataProvider visible_tiers
	 */
	public function test_a_visible_venue_name_keeps_the_title_slug( string $privacy ): void {
		$post_id = $this->generated_draft( $privacy );

		$this->publish( $post_id );

		$this->assertSame( self::VENUE_SLUG, $this->slug( $post_id ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function visible_tiers(): array {
		return [
			'public'      => [ 'public' ],
			'approximate' => [ 'approximate' ],
		];
	}

	public function test_an_author_set_slug_stays_when_publishing(): void {
		$post_id = $this->generated_draft( 'private' );

		$this->publish( $post_id, [ 'post_name' => 'my-own-slug' ] );

		$this->assertSame( 'my-own-slug', $this->slug( $post_id ) );
	}

	public function test_an_author_set_slug_stays_when_privacy_arrives_after_insert(): void {
		$post_id = self::factory()->post->create(
			[
				'post_status'   => 'publish',
				'post_name'     => 'my-own-slug',
				'post_title'    => 'Checked in at ' . self::VENUE,
				'post_date'     => '2026-09-12 14:30:00',
				'post_date_gmt' => '2026-09-12 14:30:00',
			]
		);
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'checkin_name', self::VENUE );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_privacy', 'private' );
		Title_Privacy::mark_location_title( $post_id );

		$this->assertSame( 'my-own-slug', $this->slug( $post_id ) );
	}

	/**
	 * A slug that is already public stays put: links to it exist. This
	 * covers posts published before this fix and a check-in made private
	 * after it was published.
	 */
	public function test_a_published_slug_survives_later_saves(): void {
		global $wpdb;

		$post_id = $this->generated_draft( 'public' );
		// Published in an earlier request, slug and all.
		$wpdb->update(
			$wpdb->posts,
			[
				'post_status' => 'publish',
				'post_name'   => self::VENUE_SLUG,
			],
			[ 'ID' => $post_id ]
		);
		clean_post_cache( $post_id );

		update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_privacy', 'private' );
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'A note.',
			]
		);

		$this->assertSame( self::VENUE_SLUG, $this->slug( $post_id ) );
	}

	/**
	 * wp_update_post() passes the stored slug along; wp_insert_post() with
	 * an ID and no post_name keeps the stored slug without passing it.
	 */
	public function test_a_published_slug_survives_a_direct_insert_update(): void {
		$post_id = $this->published_earlier_with_venue_slug();

		wp_insert_post(
			[
				'ID'           => $post_id,
				'post_title'   => 'Checked in at ' . self::VENUE,
				'post_content' => 'A note.',
				'post_status'  => 'publish',
				'post_date'    => '2026-09-12 14:30:00',
			]
		);

		$this->assertSame( self::VENUE_SLUG, $this->slug( $post_id ) );
		$this->assertSame( [], get_post_meta( $post_id, '_wp_old_slug' ) );
	}

	/**
	 * WordPress treats a post_name of "0" as missing and derives the slug
	 * from the title, so the rule does too.
	 */
	public function test_a_zero_post_name_gets_the_safe_slug(): void {
		$post_id = wp_insert_post(
			[
				'post_status'   => 'publish',
				'post_name'     => '0',
				'post_title'    => 'Checked in at ' . self::VENUE,
				'post_date'     => '2026-09-12 14:30:00',
				'post_date_gmt' => '2026-09-12 14:30:00',
				'meta_input'    => [
					Title_Privacy::META_KEY                 => Title_Privacy::SOURCE_LOCATION,
					Meta_Fields::PREFIX . 'checkin_name' => self::VENUE,
					Meta_Fields::PREFIX . 'geo_privacy'  => 'private',
				],
			]
		);

		$this->assertSame( self::SAFE_SLUG, $this->slug( $post_id ) );
	}

	/**
	 * Two saves in one request (a script, a batch endpoint): the slug
	 * WordPress derived is replaced by the author's before privacy changes.
	 */
	public function test_an_author_slug_set_later_in_the_same_request_stays(): void {
		$post_id = $this->generated_draft( 'public' );
		$this->publish( $post_id );
		$this->assertSame( self::VENUE_SLUG, $this->slug( $post_id ), 'precondition: public keeps the venue slug' );

		wp_update_post(
			[
				'ID'        => $post_id,
				'post_name' => 'my-own-slug',
			]
		);
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_privacy', 'private' );

		$this->assertSame( 'my-own-slug', $this->slug( $post_id ) );
	}

	/**
	 * An insert that fails at the database never reaches the wp_insert_post
	 * action, so the slug it derived is never claimed. A later insert whose
	 * author set that same slug isn't derived.
	 */
	public function test_an_author_slug_after_a_failed_insert_stays(): void {
		global $wpdb;

		$fail_next_insert = static function ( $query ) use ( $wpdb ) {
			if ( 0 === strpos( (string) $query, "INSERT INTO `{$wpdb->posts}`" ) ) {
				return 'INSERT INTO `pkiw_missing_table` (x) VALUES (1)';
			}
			return $query;
		};
		add_filter( 'query', $fail_next_insert );
		$suppress = $wpdb->suppress_errors( true );
		$failed   = wp_insert_post(
			[
				'post_status' => 'publish',
				'post_title'  => 'Checked in at ' . self::VENUE,
			]
		);
		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $fail_next_insert );
		$this->assertSame( 0, $failed, 'precondition: the first insert fails' );

		$post_id = wp_insert_post(
			[
				'post_status'   => 'publish',
				'post_name'     => self::VENUE_SLUG,
				'post_title'    => 'Checked in at ' . self::VENUE,
				'post_date'     => '2026-09-12 14:30:00',
				'post_date_gmt' => '2026-09-12 14:30:00',
			]
		);
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'checkin_name', self::VENUE );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_privacy', 'private' );
		Title_Privacy::mark_location_title( $post_id );

		$this->assertSame( self::VENUE_SLUG, $this->slug( $post_id ) );
	}

	/**
	 * When the slug can't be written, nothing says it was replaced.
	 */
	public function test_a_failed_slug_write_announces_nothing(): void {
		global $wpdb;

		$heard = [];
		add_action(
			'pkiw_derived_slug_replaced',
			static function ( ...$args ) use ( &$heard ) {
				$heard[] = $args;
			},
			10,
			3
		);

		$post_id = self::factory()->post->create(
			[
				'post_status'   => 'publish',
				'post_title'    => 'Checked in at ' . self::VENUE,
				'post_date'     => '2026-09-12 14:30:00',
				'post_date_gmt' => '2026-09-12 14:30:00',
			]
		);
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'checkin_name', self::VENUE );

		$fail_slug_update = static function ( $query ) use ( $wpdb ) {
			if ( 0 === strpos( (string) $query, "UPDATE `{$wpdb->posts}` SET `post_name`" ) ) {
				return 'UPDATE `pkiw_missing_table` SET x = 1';
			}
			return $query;
		};
		add_filter( 'query', $fail_slug_update );
		$suppress = $wpdb->suppress_errors( true );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_privacy', 'private' );
		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $fail_slug_update );

		$this->assertSame( self::VENUE_SLUG, $this->slug( $post_id ), 'precondition: the write failed' );
		$this->assertSame( [], $heard );
		$this->assertSame( [], get_post_meta( $post_id, '_wp_old_slug' ) );
	}

	public function test_a_trashed_post_keeps_its_trash_suffix(): void {
		$post_id = $this->generated_draft( 'public' );
		$this->publish( $post_id );
		wp_trash_post( $post_id );

		update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_privacy', 'private' );

		$this->assertSame( self::VENUE_SLUG . '__trashed', $this->slug( $post_id ) );
	}
}
