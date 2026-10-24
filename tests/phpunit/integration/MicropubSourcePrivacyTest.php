<?php
/**
 * Micropub q=source hides what a visitor can't see from tokens that can't
 * edit the post (issue 351).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Meta_Fields;
use PKIW\Title_Privacy;

/**
 * The Micropub plugin answers q=source with a post's raw post_content and
 * every mf2_* meta row the request stored, after checking only that the
 * token is valid (Micropub 2.5.3, trait-micropub.php:59 and
 * functions.php:319-360). So a token for a user who can't edit the post read
 * a private RSVP's eventLocation, a private check-in's coordinates, a hidden
 * acquisition cost, sleep times and a location title. These tests run the
 * response the Micropub plugin builds through the `micropub_query` filter.
 *
 * @group integration
 */
final class MicropubSourcePrivacyTest extends WP_UnitTestCase {

	private const TITLE = 'Checked in at Sentinel Venue M351';

	public function set_up(): void {
		parent::set_up();
		// The test framework wipes registered meta between tests.
		( new Meta_Fields() )->register_meta_fields();
		foreach ( [ 'rsvp', 'checkin', 'acquisition', 'sleep' ] as $slug ) {
			if ( ! term_exists( $slug, 'kind' ) ) {
				wp_insert_term( ucfirst( $slug ), 'kind', [ 'slug' => $slug ] );
			}
		}
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * A post with content, a kind and meta.
	 *
	 * @param string               $content Post content.
	 * @param string               $kind    Kind slug.
	 * @param array<string, mixed> $meta    Meta stored after the save.
	 * @param array<string, mixed> $post    Extra post fields.
	 * @return int Post ID.
	 */
	private function post( string $content, string $kind, array $meta = [], array $post = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Source fixture',
					'post_content' => $content,
					'post_author'  => self::factory()->user->create( [ 'role' => 'author' ] ),
				],
				$post
			)
		);
		wp_set_object_terms( $id, $kind, 'kind' );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
		clean_post_cache( $id );

		return $id;
	}

	/**
	 * The item the Micropub plugin builds for a post (micropub_get_mf2()).
	 *
	 * @param int $id Post ID.
	 * @return array<string, mixed>
	 */
	private function mf2( int $id ): array {
		$post = get_post( $id );
		$item = [ 'properties' => [] ];
		foreach ( (array) get_post_meta( $id ) as $field => $value ) {
			if ( str_starts_with( (string) $field, 'mf2_' ) ) {
				$item['properties'][ substr( (string) $field, 4 ) ] = (array) maybe_unserialize( $value[0] );
			}
		}
		$item['properties']['published'] = [ get_post_time( DATE_W3C, true, $post ) ];
		$item['properties']['name']      = [ $post->post_title ];
		$item['properties']['content']   = [ $post->post_content ];

		return $item;
	}

	/**
	 * A q=source&url= answer as the filter leaves it.
	 *
	 * @param int $id Post ID.
	 * @return mixed
	 */
	private function source( int $id ) {
		return apply_filters(
			'micropub_query',
			$this->mf2( $id ),
			[
				'q'   => 'source',
				'url' => get_permalink( $id ),
			]
		);
	}

	/**
	 * The answer's content as one string.
	 *
	 * @param mixed $item Filtered item.
	 */
	private function content( $item ): string {
		return (string) ( $item['properties']['content'][0] ?? '' );
	}

	public function test_a_private_rsvp_location_is_hidden_from_a_token_that_cannot_edit(): void {
		$id   = $this->post( '<!-- wp:post-kinds-indieweb/rsvp-card {"eventName":"Quill Meetup","eventLocation":"9 Quill Lane"} /-->', 'rsvp' );
		$item = $this->source( $id );

		$this->assertStringNotContainsString( '9 Quill Lane', $this->content( $item ) );
		$this->assertStringContainsString( 'Quill Meetup', $this->content( $item ), 'The event name stays.' );
	}

	public function test_a_private_check_in_loses_its_card_location_and_its_stored_mf2_location(): void {
		$id   = $this->post(
			'<!-- wp:post-kinds-indieweb/checkin-card {"venueName":"Zephyr Tea Room","latitude":41.351351,"longitude":-73.351351,"locationPrivacy":"private"} /-->',
			'checkin',
			[
				'_pkiw_geo_privacy' => 'private',
				'mf2_location'      => [ 'geo:41.351351,-73.351351' ],
				'mf2_checkin'       => [ [ 'type' => [ 'h-card' ], 'properties' => [ 'name' => [ 'Zephyr Tea Room' ] ] ] ],
			]
		);
		$item = $this->source( $id );

		$this->assertStringNotContainsString( '41.351351', (string) wp_json_encode( $item ) );
		$this->assertStringNotContainsString( 'Zephyr', (string) wp_json_encode( $item ) );
	}

	public function test_a_private_acquisition_cost_is_hidden(): void {
		$id   = $this->post( '<!-- wp:post-kinds-indieweb/acquisition-card {"title":"Walnut lamp","cost":"$149.99"} /-->', 'acquisition' );
		$item = $this->source( $id );

		$this->assertStringNotContainsString( '149.99', $this->content( $item ) );
		$this->assertStringContainsString( 'Walnut lamp', $this->content( $item ) );
	}

	public function test_hidden_sleep_times_and_their_mf2_rows_are_hidden(): void {
		$id   = $this->post(
			'<!-- wp:post-kinds-indieweb/sleep-card {"durationMinutes":462,"bedtime":"2026-08-19T23:10"} /-->',
			'sleep',
			[
				'mf2_start' => [ '2026-08-19T23:10:00-04:00' ],
				'mf2_end'   => [ '2026-08-20T06:52:00-04:00' ],
			]
		);
		$item = $this->source( $id );

		$this->assertStringNotContainsString( '23:10', (string) wp_json_encode( $item ) );
		$this->assertArrayNotHasKey( 'end', $item['properties'] );
		$this->assertStringContainsString( '462', $this->content( $item ), 'The public duration stays.' );
	}

	public function test_a_hidden_location_title_reads_as_the_safe_title(): void {
		$id = $this->post(
			'',
			'checkin',
			[
				'_pkiw_geo_privacy'  => 'private',
				'_pkiw_checkin_name' => 'Sentinel Venue M351',
			],
			[ 'post_title' => self::TITLE ]
		);
		Title_Privacy::mark_location_title( $id );
		$item = $this->source( $id );

		$this->assertNotSame( self::TITLE, $item['properties']['name'][0] );
		$this->assertStringNotContainsString( 'Sentinel', (string) wp_json_encode( $item ) );
	}

	public function test_a_draft_another_user_wrote_is_refused(): void {
		$id = $this->post( 'Draft body M351', 'rsvp', [], [ 'post_status' => 'draft' ] );

		$this->assertWPError( $this->source( $id ) );
	}

	public function test_a_password_protected_post_is_refused(): void {
		$id = $this->post( 'Protected body M351', 'rsvp', [], [ 'post_password' => 'pw' ] );

		$this->assertWPError( $this->source( $id ) );
	}

	public function test_the_author_keeps_the_full_source(): void {
		$id = $this->post( '<!-- wp:post-kinds-indieweb/rsvp-card {"eventName":"Quill Meetup","eventLocation":"9 Quill Lane"} /-->', 'rsvp' );
		wp_set_current_user( (int) get_post_field( 'post_author', $id ) );

		$this->assertStringContainsString( '9 Quill Lane', $this->content( $this->source( $id ) ) );
	}

	public function test_a_list_query_maps_each_item_to_its_post(): void {
		$public  = $this->post( '<!-- wp:post-kinds-indieweb/rsvp-card {"eventName":"Open Meetup","eventLocation":"1 Open Road","locationVisibility":"public"} /-->', 'rsvp', [ '_pkiw_rsvp_location_privacy' => 'public' ], [ 'post_date' => '2026-01-01 10:00:00' ] );
		$private = $this->post( '<!-- wp:post-kinds-indieweb/rsvp-card {"eventName":"Quill Meetup","eventLocation":"9 Quill Lane"} /-->', 'rsvp', [], [ 'post_date' => '2026-01-02 10:00:00' ] );
		$items   = [ $this->mf2( $private ), $this->mf2( $public ) ];

		$resp = apply_filters(
			'micropub_query',
			[ 'items' => $items ],
			[
				'q'     => 'source',
				'limit' => 2,
			]
		);

		$this->assertCount( 2, $resp['items'] );
		$this->assertStringNotContainsString( '9 Quill Lane', $this->content( $resp['items'][0] ) );
		$this->assertStringContainsString( '1 Open Road', $this->content( $resp['items'][1] ), 'A public location stays.' );
	}

	public function test_other_queries_pass_through(): void {
		$config = [ 'q' => [ 'config', 'source' ] ];

		$this->assertSame( $config, apply_filters( 'micropub_query', $config, [ 'q' => 'config' ] ) );
	}
}
