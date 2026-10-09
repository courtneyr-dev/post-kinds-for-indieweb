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

	private function reset_slug_pass_options(): void {
		delete_option( 'pkiw_title_slug_pass' );
		delete_option( 'pkiw_title_slug_pass_cursor' );
		delete_option( 'pkiw_title_slug_pass_lock' );
	}

	private function stored_with_slug( string $title, string $slug, string $status = 'publish', string $privacy = 'private', array $meta = [], bool $marked = true ): int {
		global $wpdb;

		$post_id = self::factory()->post->create(
			[
				'post_status'   => 'draft',
				'post_title'    => $title,
				'post_date'     => '2026-09-12 14:30:00',
				'post_date_gmt' => '2026-09-12 14:30:00',
			]
		);

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
		if ( '' !== $privacy ) {
			update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_privacy', $privacy );
		}
		if ( $marked ) {
			Title_Privacy::mark_location_title( $post_id );
		}

		$wpdb->update(
			$wpdb->posts,
			[
				'post_status' => $status,
				'post_name'   => $slug,
			],
			[ 'ID' => $post_id ]
		);
		clean_post_cache( $post_id );

		return $post_id;
	}

	private function insert_batch_candidate( int $offset ): int {
		global $wpdb;

		$date  = gmdate( 'Y-m-d 14:30:00', strtotime( '2026-01-01 14:30:00 UTC' ) + ( DAY_IN_SECONDS * $offset ) );
		$title = 'Checked in at ' . self::VENUE . ' batch ' . $offset;
		$wpdb->insert(
			$wpdb->posts,
			[
				'post_title'            => $title,
				'post_name'             => sanitize_title( $title ),
				'post_status'           => 'publish',
				'post_type'             => 'post',
				'post_date'             => $date,
				'post_date_gmt'         => $date,
				'post_content'          => '',
				'post_excerpt'          => '',
				'to_ping'               => '',
				'pinged'                => '',
				'post_content_filtered' => '',
				'guid'                  => '',
			]
		);
		$post_id = (int) $wpdb->insert_id;
		$wpdb->insert( $wpdb->postmeta, [ 'post_id' => $post_id, 'meta_key' => Title_Privacy::META_KEY, 'meta_value' => Title_Privacy::SOURCE_LOCATION ] );
		$wpdb->insert( $wpdb->postmeta, [ 'post_id' => $post_id, 'meta_key' => Meta_Fields::PREFIX . 'geo_privacy', 'meta_value' => 'private' ] );
		clean_post_cache( $post_id );

		return $post_id;
	}

	private function count_batch_venue_slugs(): int {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title LIKE 'Checked in at Sentinel Venue Zyx9 batch %' AND post_name LIKE 'checked-in-at-%'" );
	}

	private function venue_old_slugs( int $post_id ): array {
		return get_post_meta( $post_id, '_wp_old_slug' );
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
	 * A check-in published with its venue slug, then made private: the slug
	 * names the hidden venue in every link, so it goes (issue 379).
	 */
	public function test_a_published_venue_slug_is_replaced_when_the_location_turns_private(): void {
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

		$this->assertSame( self::SAFE_SLUG, $this->slug( $post_id ) );
		$this->assertSame( [], get_post_meta( $post_id, '_wp_old_slug' ), 'A redirect from the venue slug would confirm the venue.' );
	}

	/**
	 * A published venue slug on a public check-in is a link people use; a
	 * later save keeps it.
	 */
	public function test_a_public_venue_slug_survives_later_saves(): void {
		global $wpdb;

		$post_id = $this->generated_draft( 'public' );
		$wpdb->update(
			$wpdb->posts,
			[
				'post_status' => 'publish',
				'post_name'   => self::VENUE_SLUG,
			],
			[ 'ID' => $post_id ]
		);
		clean_post_cache( $post_id );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'A note.',
			]
		);

		$this->assertSame( self::VENUE_SLUG, $this->slug( $post_id ) );
	}

	/**
	 * wp_insert_post() with an ID and no post_name keeps the stored slug
	 * without passing it; a private check-in saved that way still loses
	 * its venue slug.
	 */
	public function test_a_private_venue_slug_saved_earlier_is_replaced_on_the_next_save(): void {
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

		$this->assertSame( self::SAFE_SLUG, $this->slug( $post_id ) );
		$this->assertSame( [], get_post_meta( $post_id, '_wp_old_slug' ) );
	}

	/**
	 * Private check-ins saved with their venue slug before issue 379 get the
	 * safe slug and guid once, without a save, and the old URL confirms
	 * nothing.
	 */
	public function test_the_stored_slug_pass_replaces_a_venue_slug_saved_earlier(): void {
		global $wpdb;

		$this->set_permalink_structure( '/%year%/%monthnum%/%day%/%postname%/' );
		$post_id = $this->published_earlier_with_venue_slug();
		$wpdb->update( $wpdb->posts, [ 'guid' => home_url( '/2026/09/12/' . self::VENUE_SLUG . '/' ) ], [ 'ID' => $post_id ] );
		clean_post_cache( $post_id );
		delete_option( 'pkiw_title_slug_pass' );

		$heard = [];
		add_action(
			'pkiw_derived_slug_replaced',
			static function ( ...$args ) use ( &$heard ) {
				$heard[] = $args;
			},
			10,
			3
		);

		Title_Privacy::maybe_replace_stored_slugs();

		$this->assertSame( self::SAFE_SLUG, $this->slug( $post_id ) );
		$this->assertSame( home_url( '/2026/09/12/' . self::SAFE_SLUG . '/' ), get_the_guid( $post_id ) );
		$this->assertSame( [], get_post_meta( $post_id, '_wp_old_slug' ) );
		$this->assertSame( [ [ $post_id, self::VENUE_SLUG, self::SAFE_SLUG ] ], $heard );

		$this->go_to( home_url( '/2026/09/12/' . self::VENUE_SLUG . '/' ) );
		$this->assertTrue( is_404() );
		$this->assertFalse( redirect_guess_404_permalink(), 'No guessed redirect lands on the private check-in.' );
	}

	/**
	 * An author slug stays, but a guid segment WordPress derived from the
	 * hidden generated title must be scrubbed for feeds and REST.
	 */
	public function test_the_stored_slug_pass_scrubs_guid_after_author_rename_then_private(): void {
		global $wpdb;

		$this->set_permalink_structure( '/%year%/%monthnum%/%day%/%postname%/' );
		$post_id = $this->generated_draft( 'public' );
		$this->publish( $post_id );
		$this->assertSame( self::VENUE_SLUG, $this->slug( $post_id ), 'precondition: public publish got the venue slug' );

		wp_update_post(
			[
				'ID'        => $post_id,
				'post_name' => 'my-own-slug',
			]
		);
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_privacy', 'private' );
		$wpdb->update( $wpdb->posts, [ 'guid' => home_url( '/2026/09/12/' . self::VENUE_SLUG . '/' ) ], [ 'ID' => $post_id ] );
		clean_post_cache( $post_id );
		$this->reset_slug_pass_options();

		Title_Privacy::maybe_replace_stored_slugs();

		$this->assertSame( 'my-own-slug', $this->slug( $post_id ) );
		$this->assertStringNotContainsString( 'sentinel', get_the_guid( $post_id ) );
		$this->assertSame( home_url( '/2026/09/12/my-own-slug/' ), get_the_guid( $post_id ) );
		$this->assertSame( '2', get_option( 'pkiw_title_slug_pass' ) );
	}

	/**
	 * A number-only title slug leaves date segments and post IDs in the guid alone.
	 */
	public function test_the_stored_slug_pass_leaves_numeric_guid_segments_alone(): void {
		global $wpdb;

		$dated   = $this->stored_with_slug( '2026', 'my-own-slug', 'publish', 'private', [ Meta_Fields::PREFIX . 'checkin_name' => '2026' ], false );
		$plain   = $this->stored_with_slug( '123', 'my-other-slug', 'publish', 'private', [ Meta_Fields::PREFIX . 'checkin_name' => '123' ], false );
		$guids   = [
			$dated => home_url( '/2026/09/12/my-own-slug/' ),
			$plain => home_url( '/?p=123' ),
		];
		foreach ( $guids as $id => $guid ) {
			$wpdb->update( $wpdb->posts, [ 'guid' => $guid ], [ 'ID' => $id ] );
			clean_post_cache( $id );
		}
		$this->reset_slug_pass_options();

		Title_Privacy::maybe_replace_stored_slugs();

		foreach ( $guids as $id => $guid ) {
			$this->assertSame( $guid, get_post( $id )->guid );
		}
		$this->assertSame( '2', get_option( 'pkiw_title_slug_pass' ) );
	}

	/**
	 * Guid rewrites are segment-bounded; unrelated guid values are not
	 * normalized just because the post has a hidden-location title.
	 */
	public function test_the_stored_slug_pass_leaves_guid_without_venue_segment_unchanged(): void {
		global $wpdb;

		$post_id = $this->generated_draft( 'public' );
		$this->publish( $post_id );
		wp_update_post(
			[
				'ID'        => $post_id,
				'post_name' => 'my-own-slug',
			]
		);
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_privacy', 'private' );
		$guid = home_url( '/?p=' . $post_id . '&source=archive' );
		$wpdb->update( $wpdb->posts, [ 'guid' => $guid ], [ 'ID' => $post_id ] );
		clean_post_cache( $post_id );
		$this->reset_slug_pass_options();

		Title_Privacy::maybe_replace_stored_slugs();

		$this->assertSame( 'my-own-slug', $this->slug( $post_id ) );
		$this->assertSame( $guid, get_the_guid( $post_id ) );
		$this->assertSame( '2', get_option( 'pkiw_title_slug_pass' ) );
	}

	/**
	 * A guid-only write failure is still a failed pass run, so the retry
	 * option must stay unset.
	 */
	public function test_the_stored_slug_pass_retries_after_a_failed_guid_write(): void {
		global $wpdb;

		$this->set_permalink_structure( '/%year%/%monthnum%/%day%/%postname%/' );
		$post_id = $this->generated_draft( 'public' );
		$this->publish( $post_id );
		wp_update_post(
			[
				'ID'        => $post_id,
				'post_name' => 'my-own-slug',
			]
		);
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_privacy', 'private' );
		$wpdb->update( $wpdb->posts, [ 'guid' => home_url( '/2026/09/12/' . self::VENUE_SLUG . '/' ) ], [ 'ID' => $post_id ] );
		clean_post_cache( $post_id );
		$this->reset_slug_pass_options();

		$fail_guid_update = static function ( $query ) use ( $wpdb, $post_id ) {
			$query = (string) $query;
			if ( 0 === strpos( $query, "UPDATE `{$wpdb->posts}` SET `guid`" ) && false !== strpos( $query, "`ID` = {$post_id}" ) ) {
				return 'UPDATE `pkiw_missing_table` SET x = 1';
			}
			return $query;
		};
		add_filter( 'query', $fail_guid_update );
		$suppress = $wpdb->suppress_errors( true );
		Title_Privacy::maybe_replace_stored_slugs();
		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $fail_guid_update );

		$this->assertFalse( get_option( 'pkiw_title_slug_pass' ) );
		$this->assertSame( 'my-own-slug', $this->slug( $post_id ) );
		$this->assertStringContainsString( 'sentinel', get_the_guid( $post_id ) );

		Title_Privacy::maybe_replace_stored_slugs();

		$this->assertStringNotContainsString( 'sentinel', get_the_guid( $post_id ) );
		$this->assertSame( '2', get_option( 'pkiw_title_slug_pass' ) );
	}

	/**
	 * The pass leaves public venue slugs and author slugs alone, and runs
	 * once per version.
	 */
	public function test_the_stored_slug_pass_keeps_public_and_author_slugs_and_runs_once(): void {
		global $wpdb;

		$public = $this->generated_draft( 'public' );
		$author = $this->generated_draft( 'private' );
		$wpdb->update(
			$wpdb->posts,
			[
				'post_status' => 'publish',
				'post_name'   => self::VENUE_SLUG,
			],
			[ 'ID' => $public ]
		);
		$wpdb->update(
			$wpdb->posts,
			[
				'post_status' => 'publish',
				'post_name'   => 'my-own-slug',
			],
			[ 'ID' => $author ]
		);
		clean_post_cache( $public );
		clean_post_cache( $author );
		delete_option( 'pkiw_title_slug_pass' );

		Title_Privacy::maybe_replace_stored_slugs();

		$this->assertSame( self::VENUE_SLUG, $this->slug( $public ) );
		$this->assertSame( 'my-own-slug', $this->slug( $author ) );

		$later = $this->published_earlier_with_venue_slug();
		Title_Privacy::maybe_replace_stored_slugs();

		$this->assertSame( self::VENUE_SLUG, $this->slug( $later ), 'The pass already ran for this version.' );
	}

	/**
	 * A site that finished version 1 of the pass, which skipped unmarked check-ins, runs it again.
	 */
	public function test_a_site_that_completed_version_one_runs_the_pass_again(): void {
		$this->reset_slug_pass_options();
		update_option( 'pkiw_title_slug_pass', '1' );
		$post_id = $this->stored_with_slug( 'Checked in at ' . self::VENUE, self::VENUE_SLUG, 'publish', 'private', [ Meta_Fields::PREFIX . 'checkin_name' => self::VENUE ], false );

		Title_Privacy::maybe_replace_stored_slugs();

		$this->assertSame( self::SAFE_SLUG, $this->slug( $post_id ) );
		$this->assertSame( '2', get_option( 'pkiw_title_slug_pass' ) );
	}

	/**
	 * The pass selects every frozen legacy candidate and lets privacy decide.
	 */
	public function test_the_stored_slug_pass_selects_legacy_candidates_and_respects_controls(): void {
		$checkin_name = [ Meta_Fields::PREFIX . 'checkin_name' => self::VENUE ];
		$checked_at   = $this->stored_with_slug( 'Checked in at ' . self::VENUE, self::VENUE_SLUG, 'publish', 'private', $checkin_name, false );
		$bare_venue   = $this->stored_with_slug( self::VENUE, 'sentinel-venue-zyx9', 'publish', 'private', $checkin_name, false );
		$venue_meta   = $this->stored_with_slug( 'Checked in at ' . self::VENUE, self::VENUE_SLUG . '-2', 'publish', 'private', [ Meta_Fields::PREFIX . 'checkin_venue' => self::VENUE ], false );
		$private      = $this->stored_with_slug( 'Checked in at ' . self::VENUE . ' private', sanitize_title( 'Checked in at ' . self::VENUE . ' private' ), 'private', 'private', $checkin_name );
		$future       = $this->stored_with_slug( 'Checked in at ' . self::VENUE . ' future', sanitize_title( 'Checked in at ' . self::VENUE . ' future' ), 'future', 'private', $checkin_name );
		$pending      = $this->stored_with_slug( 'Checked in at ' . self::VENUE . ' pending', sanitize_title( 'Checked in at ' . self::VENUE . ' pending' ), 'pending', 'private', $checkin_name );
		$draft        = $this->stored_with_slug( 'Checked in at ' . self::VENUE . ' draft', sanitize_title( 'Checked in at ' . self::VENUE . ' draft' ), 'draft', 'private', $checkin_name );
		$public       = $this->stored_with_slug( 'Checked in at ' . self::VENUE, self::VENUE_SLUG . '-3', 'publish', 'public', $checkin_name, false );
		$trashed      = $this->stored_with_slug( 'Checked in at ' . self::VENUE . ' trash', sanitize_title( 'Checked in at ' . self::VENUE . ' trash' ), 'publish', 'private', $checkin_name );
		$ordinary     = $this->stored_with_slug( 'Ordinary Title', 'ordinary-title', 'publish', 'private', [], false );
		$author       = $this->stored_with_slug( 'Checked in at ' . self::VENUE, 'my-own-slug', 'publish', 'private', $checkin_name );
		wp_trash_post( $trashed );
		clean_post_cache( $trashed );
		$this->reset_slug_pass_options();

		Title_Privacy::maybe_replace_stored_slugs();

		$this->assertSame( self::SAFE_SLUG, $this->slug( $checked_at ) );
		$this->assertSame( self::SAFE_SLUG . '-2', $this->slug( $bare_venue ) );
		$this->assertStringStartsWith( self::SAFE_SLUG, $this->slug( $venue_meta ) );
		$this->assertStringStartsWith( 'check-in-', $this->slug( $private ) );
		$this->assertStringStartsWith( 'check-in-', $this->slug( $future ) );
		$this->assertStringStartsWith( 'check-in-', $this->slug( $pending ) );
		$this->assertStringStartsWith( 'check-in-', $this->slug( $draft ) );
		$this->assertSame( self::VENUE_SLUG . '-3', $this->slug( $public ) );
		$this->assertSame( sanitize_title( 'Checked in at ' . self::VENUE . ' trash' ) . '__trashed', $this->slug( $trashed ) );
		$this->assertSame( 'ordinary-title', $this->slug( $ordinary ) );
		$this->assertSame( 'my-own-slug', $this->slug( $author ) );
	}

	/**
	 * A failed candidate UPDATE leaves the pass incomplete for retry.
	 */
	public function test_the_stored_slug_pass_retries_after_a_failed_write(): void {
		global $wpdb;

		$first  = $this->stored_with_slug( 'Checked in at ' . self::VENUE, self::VENUE_SLUG, 'publish', 'private', [ Meta_Fields::PREFIX . 'checkin_name' => self::VENUE ] );
		$second = $this->stored_with_slug( 'Checked in at ' . self::VENUE . ' B', sanitize_title( 'Checked in at ' . self::VENUE . ' B' ), 'publish', 'private', [ Meta_Fields::PREFIX . 'checkin_name' => self::VENUE ] );
		$this->reset_slug_pass_options();

		$fail_first_update = static function ( $query ) use ( $wpdb, $first ) {
			$query = (string) $query;
			if ( 0 === strpos( $query, "UPDATE `{$wpdb->posts}` SET `post_name`" ) && false !== strpos( $query, "`ID` = {$first}" ) ) {
				return 'UPDATE `pkiw_missing_table` SET x = 1';
			}
			return $query;
		};
		add_filter( 'query', $fail_first_update );
		$suppress = $wpdb->suppress_errors( true );
		Title_Privacy::maybe_replace_stored_slugs();
		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $fail_first_update );

		$this->assertFalse( get_option( 'pkiw_title_slug_pass' ) );
		$this->assertSame( self::VENUE_SLUG, $this->slug( $first ) );
		$this->assertStringStartsWith( 'check-in-', $this->slug( $second ) );

		Title_Privacy::maybe_replace_stored_slugs();

		$this->assertStringStartsWith( 'check-in-', $this->slug( $first ) );
		$this->assertSame( '2', get_option( 'pkiw_title_slug_pass' ) );
	}

	/**
	 * A successful slug replacement does not make the pass successful when
	 * cleaning the redirect row failed afterward.
	 */
	public function test_the_stored_slug_pass_retries_when_old_slug_delete_fails_after_slug_replace(): void {
		global $wpdb;

		$post_id = $this->stored_with_slug( 'Checked in at ' . self::VENUE, self::VENUE_SLUG, 'publish', 'private', [ Meta_Fields::PREFIX . 'checkin_name' => self::VENUE ] );
		add_post_meta( $post_id, '_wp_old_slug', self::VENUE_SLUG );
		$this->reset_slug_pass_options();

		$fail_delete = static function ( $query ) use ( $wpdb ) {
			$query = (string) $query;
			if ( 0 === strpos( $query, 'DELETE FROM ' ) && false !== strpos( $query, $wpdb->postmeta ) ) {
				return 'DELETE FROM `pkiw_missing_table` WHERE 1 = 1';
			}
			return $query;
		};
		add_filter( 'query', $fail_delete );
		$suppress = $wpdb->suppress_errors( true );
		Title_Privacy::maybe_replace_stored_slugs();
		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $fail_delete );

		$this->assertFalse( get_option( 'pkiw_title_slug_pass' ) );
		$this->assertSame( self::SAFE_SLUG, $this->slug( $post_id ) );
		$this->assertContains( self::VENUE_SLUG, $this->venue_old_slugs( $post_id ) );

		Title_Privacy::maybe_replace_stored_slugs();

		$this->assertNotContains( self::VENUE_SLUG, $this->venue_old_slugs( $post_id ) );
		$this->assertSame( '2', get_option( 'pkiw_title_slug_pass' ) );
	}

	/**
	 * A failed candidate SELECT leaves every slug and the pass incomplete.
	 */
	public function test_the_stored_slug_pass_retries_after_a_failed_candidate_select(): void {
		global $wpdb;

		$post_id = $this->stored_with_slug( 'Checked in at ' . self::VENUE, self::VENUE_SLUG, 'publish', 'private', [ Meta_Fields::PREFIX . 'checkin_name' => self::VENUE ] );
		$this->reset_slug_pass_options();

		$fail_candidate_select = static function ( $query ) use ( $wpdb ) {
			$query = (string) $query;
			if ( 0 === stripos( ltrim( $query ), 'SELECT' ) && false !== strpos( $query, $wpdb->posts ) && ( false !== strpos( $query, Title_Privacy::META_KEY ) || false !== strpos( $query, Meta_Fields::PREFIX . 'checkin_name' ) ) ) {
				return 'SELECT ID FROM `pkiw_missing_table`';
			}
			return $query;
		};
		add_filter( 'query', $fail_candidate_select );
		$suppress = $wpdb->suppress_errors( true );
		Title_Privacy::maybe_replace_stored_slugs();
		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $fail_candidate_select );

		$this->assertFalse( get_option( 'pkiw_title_slug_pass' ) );
		$this->assertSame( self::VENUE_SLUG, $this->slug( $post_id ) );

		Title_Privacy::maybe_replace_stored_slugs();

		$this->assertSame( self::SAFE_SLUG, $this->slug( $post_id ) );
		$this->assertSame( '2', get_option( 'pkiw_title_slug_pass' ) );
	}

	/**
	 * One stored-slug pass run handles 100 candidates and advances a cursor.
	 */
	public function test_the_stored_slug_pass_batches_candidates_and_completes_after_the_last_batch(): void {
		for ( $i = 0; $i < 250; ++$i ) {
			$this->insert_batch_candidate( $i );
		}
		wp_cache_flush();
		$this->reset_slug_pass_options();

		Title_Privacy::maybe_replace_stored_slugs();

		$this->assertSame( 150, $this->count_batch_venue_slugs() );
		$this->assertFalse( get_option( 'pkiw_title_slug_pass' ) );

		Title_Privacy::maybe_replace_stored_slugs();

		$this->assertSame( 50, $this->count_batch_venue_slugs() );
		$this->assertFalse( get_option( 'pkiw_title_slug_pass' ) );

		Title_Privacy::maybe_replace_stored_slugs();

		$this->assertSame( 0, $this->count_batch_venue_slugs() );
		$this->assertSame( '2', get_option( 'pkiw_title_slug_pass' ) );
	}

	/**
	 * A fresh stored-slug pass lock makes another request leave work alone.
	 */
	public function test_a_fresh_stored_slug_pass_lock_defers_the_run(): void {
		$post_id = $this->stored_with_slug( 'Checked in at ' . self::VENUE, self::VENUE_SLUG, 'publish', 'private', [ Meta_Fields::PREFIX . 'checkin_name' => self::VENUE ] );
		$this->reset_slug_pass_options();
		$lock = time();
		update_option( 'pkiw_title_slug_pass_lock', $lock );

		Title_Privacy::maybe_replace_stored_slugs();

		$this->assertSame( self::VENUE_SLUG, $this->slug( $post_id ) );
		$this->assertSame( $lock, (int) get_option( 'pkiw_title_slug_pass_lock' ) );
	}

	/**
	 * A stale stored-slug pass lock is taken over and released.
	 */
	public function test_a_stale_stored_slug_pass_lock_is_released_after_success(): void {
		$post_id = $this->stored_with_slug( 'Checked in at ' . self::VENUE, self::VENUE_SLUG, 'publish', 'private', [ Meta_Fields::PREFIX . 'checkin_name' => self::VENUE ] );
		$this->reset_slug_pass_options();
		update_option( 'pkiw_title_slug_pass_lock', time() - 400 );

		Title_Privacy::maybe_replace_stored_slugs();

		$this->assertSame( self::SAFE_SLUG, $this->slug( $post_id ) );
		$this->assertFalse( get_option( 'pkiw_title_slug_pass_lock' ) );
	}

	/**
	 * A stored-slug pass lock is released even when a write fails.
	 */
	public function test_the_stored_slug_pass_lock_is_released_after_a_failed_write(): void {
		global $wpdb;

		$post_id = $this->stored_with_slug( 'Checked in at ' . self::VENUE, self::VENUE_SLUG, 'publish', 'private', [ Meta_Fields::PREFIX . 'checkin_name' => self::VENUE ] );
		$this->reset_slug_pass_options();
		update_option( 'pkiw_title_slug_pass_lock', time() - 400 );

		$fail_slug_update = static function ( $query ) use ( $wpdb, $post_id ) {
			$query = (string) $query;
			if ( 0 === strpos( $query, "UPDATE `{$wpdb->posts}` SET `post_name`" ) && false !== strpos( $query, "`ID` = {$post_id}" ) ) {
				return 'UPDATE `pkiw_missing_table` SET x = 1';
			}
			return $query;
		};
		add_filter( 'query', $fail_slug_update );
		$suppress = $wpdb->suppress_errors( true );
		Title_Privacy::maybe_replace_stored_slugs();
		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $fail_slug_update );

		$this->assertFalse( get_option( 'pkiw_title_slug_pass_lock' ) );
		$this->assertFalse( get_option( 'pkiw_title_slug_pass' ) );
		$this->assertSame( self::VENUE_SLUG, $this->slug( $post_id ) );
	}

	/**
	 * If another request takes the lock during the batch, a stale finally
	 * block must not delete the other request's lock.
	 */
	public function test_the_stored_slug_pass_releases_only_the_lock_it_acquired(): void {
		$post_id = $this->stored_with_slug( 'Checked in at ' . self::VENUE, self::VENUE_SLUG, 'publish', 'private', [ Meta_Fields::PREFIX . 'checkin_name' => self::VENUE ] );
		$this->reset_slug_pass_options();
		$other_lock = (string) ( time() + 1 );

		add_action(
			'pkiw_derived_slug_replaced',
			static function () use ( $other_lock ) {
				update_option( 'pkiw_title_slug_pass_lock', $other_lock );
			}
		);

		Title_Privacy::maybe_replace_stored_slugs();

		$this->assertSame( self::SAFE_SLUG, $this->slug( $post_id ) );
		$this->assertSame( $other_lock, get_option( 'pkiw_title_slug_pass_lock' ) );
	}

	/**
	 * Another request's lock written in the same second still survives this request's release.
	 */
	public function test_the_stored_slug_pass_keeps_a_same_second_lock_it_did_not_write(): void {
		global $wpdb;

		$this->stored_with_slug( 'Checked in at ' . self::VENUE, self::VENUE_SLUG, 'publish', 'private', [ Meta_Fields::PREFIX . 'checkin_name' => self::VENUE ] );
		$this->reset_slug_pass_options();
		$other_lock = '';
		$mine       = '';

		add_action(
			'pkiw_derived_slug_replaced',
			static function () use ( $wpdb, &$other_lock, &$mine ) {
				$mine       = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'pkiw_title_slug_pass_lock' ) );
				$other_lock = strtok( $mine, ':' ) . ':another-request';
				$wpdb->update( $wpdb->options, [ 'option_value' => $other_lock ], [ 'option_name' => 'pkiw_title_slug_pass_lock' ] );
			}
		);

		Title_Privacy::maybe_replace_stored_slugs();

		$this->assertMatchesRegularExpression( '/^\d+:[A-Za-z0-9]{12}$/', $mine, 'The lock token carries a suffix only this request holds.' );
		$this->assertSame( $other_lock, (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'pkiw_title_slug_pass_lock' ) ) );
	}

	/**
	 * A completed stored-slug pass costs no database queries after cache warmup.
	 */
	public function test_a_completed_stored_slug_pass_costs_no_queries_after_the_option_is_cached(): void {
		global $wpdb;

		update_option( 'pkiw_title_slug_pass', '2' );
		get_option( 'pkiw_title_slug_pass' );
		$queries = $wpdb->num_queries;

		Title_Privacy::maybe_replace_stored_slugs();

		$this->assertSame( 0, $wpdb->num_queries - $queries );
	}

	/**
	 * Hidden-location old slugs are removed after an author rename.
	 */
	public function test_a_private_author_slug_does_not_keep_a_venue_old_slug_redirect(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$post_id = $this->generated_draft( 'public' );
		$this->publish( $post_id );
		$this->assertSame( self::VENUE_SLUG, $this->slug( $post_id ) );

		wp_update_post(
			[
				'ID'        => $post_id,
				'post_name' => 'my-own-slug',
			]
		);
		$this->assertContains( self::VENUE_SLUG, $this->venue_old_slugs( $post_id ), 'precondition: core stored the venue slug as an old slug.' );

		update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_privacy', 'private' );

		$this->assertSame( 'my-own-slug', $this->slug( $post_id ) );
		$this->assertNotContains( self::VENUE_SLUG, $this->venue_old_slugs( $post_id ) );
		$this->go_to( home_url( '/' . self::VENUE_SLUG . '/' ) );
		$this->assertEmpty( _find_post_by_old_slug( 'post' ) );
	}

	/**
	 * The pass removes hidden-location old slugs without changing an author slug.
	 */
	public function test_the_stored_slug_pass_removes_venue_old_slugs_from_an_author_slug(): void {
		$post_id = $this->generated_draft( 'public' );
		$this->publish( $post_id );
		wp_update_post(
			[
				'ID'        => $post_id,
				'post_name' => 'my-own-slug',
			]
		);
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_privacy', 'private' );
		add_post_meta( $post_id, '_wp_old_slug', self::VENUE_SLUG );
		add_post_meta( $post_id, '_wp_old_slug', self::VENUE_SLUG );
		add_post_meta( $post_id, '_wp_old_slug', self::VENUE_SLUG . '-2' );
		add_post_meta( $post_id, '_wp_old_slug', 'an-earlier-author-slug' );
		$this->reset_slug_pass_options();

		Title_Privacy::maybe_replace_stored_slugs();

		$old_slugs = $this->venue_old_slugs( $post_id );
		$this->assertSame( 'my-own-slug', $this->slug( $post_id ) );
		$this->assertNotContains( self::VENUE_SLUG, $old_slugs );
		$this->assertNotContains( self::VENUE_SLUG . '-2', $old_slugs );
		$this->assertContains( 'an-earlier-author-slug', $old_slugs );
	}

	/**
	 * Even when the stored slug is already safe, redirect rows from the
	 * hidden venue still confirm a guessed location.
	 */
	public function test_the_stored_slug_pass_removes_venue_old_slug_when_slug_is_already_safe(): void {
		$post_id = $this->generated_draft( 'private' );
		$this->publish( $post_id );
		$this->assertSame( self::SAFE_SLUG, $this->slug( $post_id ), 'precondition: publish already wrote the safe slug' );
		add_post_meta( $post_id, '_wp_old_slug', self::VENUE_SLUG );
		$this->reset_slug_pass_options();

		Title_Privacy::maybe_replace_stored_slugs();

		$this->assertSame( self::SAFE_SLUG, $this->slug( $post_id ) );
		$this->assertNotContains( self::VENUE_SLUG, $this->venue_old_slugs( $post_id ) );
		$this->assertSame( '2', get_option( 'pkiw_title_slug_pass' ) );
	}

	/**
	 * A failed old-slug delete counts as a failed write: the pass stays
	 * incomplete and the next run removes the venue old slug.
	 */
	public function test_the_stored_slug_pass_retries_when_an_old_slug_delete_fails(): void {
		global $wpdb;

		$post_id = $this->generated_draft( 'public' );
		$this->publish( $post_id );
		wp_update_post(
			[
				'ID'        => $post_id,
				'post_name' => 'my-own-slug',
			]
		);
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_privacy', 'private' );
		add_post_meta( $post_id, '_wp_old_slug', self::VENUE_SLUG );
		$this->reset_slug_pass_options();

		$fail_delete = static function ( $query ) use ( $wpdb ) {
			$query = (string) $query;
			if ( 0 === strpos( $query, 'DELETE FROM ' ) && false !== strpos( $query, $wpdb->postmeta ) ) {
				return 'DELETE FROM `pkiw_missing_table` WHERE 1 = 1';
			}
			return $query;
		};
		add_filter( 'query', $fail_delete );
		$suppress = $wpdb->suppress_errors( true );
		Title_Privacy::maybe_replace_stored_slugs();
		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $fail_delete );

		$this->assertFalse( get_option( 'pkiw_title_slug_pass' ) );
		$this->assertContains( self::VENUE_SLUG, $this->venue_old_slugs( $post_id ) );

		Title_Privacy::maybe_replace_stored_slugs();

		$this->assertNotContains( self::VENUE_SLUG, $this->venue_old_slugs( $post_id ) );
		$this->assertSame( '2', get_option( 'pkiw_title_slug_pass' ) );
	}

	/**
	 * Public-tier check-ins keep old slugs because the venue is visible.
	 */
	public function test_a_public_check_in_keeps_its_venue_old_slug(): void {
		$post_id = $this->generated_draft( 'public' );
		$this->publish( $post_id );
		add_post_meta( $post_id, '_wp_old_slug', self::VENUE_SLUG );
		$this->reset_slug_pass_options();

		Title_Privacy::maybe_replace_stored_slugs();

		$this->assertContains( self::VENUE_SLUG, $this->venue_old_slugs( $post_id ) );
	}

	/**
	 * Ruling: privacy wins when an author slug spells the hidden title.
	 */
	public function test_an_author_slug_that_spells_the_hidden_title_is_replaced_by_ruling(): void {
		$post_id = wp_insert_post(
			[
				'post_status'   => 'publish',
				'post_name'     => self::VENUE_SLUG,
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
	 * Uninstall removes every stored-slug pass option.
	 */
	public function test_uninstall_deletes_stored_slug_pass_options(): void {
		$uninstall = (string) file_get_contents( dirname( __DIR__, 3 ) . '/uninstall.php' );

		$this->assertStringContainsString( "delete_option( 'pkiw_title_slug_pass' )", $uninstall );
		$this->assertStringContainsString( "delete_option( 'pkiw_title_slug_pass_cursor' )", $uninstall );
		$this->assertStringContainsString( "delete_option( 'pkiw_title_slug_pass_lock' )", $uninstall );
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
	 * author set that same slug isn't derived. Its title differs from the
	 * failed one, so the stored-slug rule (a slug equal to the title's slug)
	 * cannot be what keeps the slug; only a leaked pending key from the
	 * failed insert could make it look derived. The same-title variant is
	 * the ruling test above: privacy wins there.
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
				'post_title'    => 'Checked in at ' . self::VENUE . ' again',
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
