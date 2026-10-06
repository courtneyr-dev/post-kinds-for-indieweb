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
		$this->assertSame( 'Checked in at ' . self::VENUE, get_post_field( 'post_title', $post_id ), 'stored title must stay intact' );
	}

	/**
	 * The importers insert a published post first and write its location,
	 * privacy and title marker afterwards, so the slug exists before the
	 * privacy does.
	 */
	public function test_import_with_a_private_default_writes_no_venue_into_the_slug(): void {
		update_option( 'pkiw_settings', [ 'checkin_default_privacy' => 'private' ] );

		$sync   = ( new ReflectionClass( \PKIW\Sync\Foursquare_Checkin_Sync::class ) )->newInstanceWithoutConstructor();
		$method = new ReflectionMethod( $sync, 'import_checkin' );

		$post_id = $method->invoke(
			$sync,
			[
				'id'        => 'sentinel-4sq-slug',
				'createdAt' => strtotime( '2026-09-12 14:30:00 UTC' ),
				'venue'     => [
					'id'       => 'v1',
					'name'     => self::VENUE,
					'location' => [ 'city' => 'Sentinelville' ],
				],
			]
		);

		$this->assertIsInt( $post_id );
		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$this->assertSame( self::SAFE_SLUG, $this->slug( $post_id ) );
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
}
