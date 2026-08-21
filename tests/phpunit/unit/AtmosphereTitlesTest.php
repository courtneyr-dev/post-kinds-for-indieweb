<?php
/**
 * Tests for the ATmosphere integration's derived document titles.
 *
 * Standard.site requires a document title; several Post Kinds are
 * intentionally untitled. These tests pin the derivation rules for every
 * kind, the no-override rule for real titles, the privacy tiers, and the
 * grapheme cap.
 *
 * @package PKIW
 * @group   atmosphere
 */

namespace PKIW\Tests\Unit;

use PKIW\Integrations\Atmosphere_Titles;
use PKIW\Meta_Fields;

/**
 * Atmosphere_Titles tests.
 */
class AtmosphereTitlesTest extends \WP_UnitTestCase {

	/**
	 * Create an untitled post of a given kind with meta.
	 *
	 * @param string                $kind Kind slug.
	 * @param array<string, mixed>  $meta Meta suffix => value (prefix added).
	 * @param array<string, mixed>  $args Extra post args.
	 * @return \WP_Post
	 */
	private function make_kind_post( string $kind, array $meta = [], array $args = [] ): \WP_Post {
		$post_id = self::factory()->post->create(
			array_merge(
				[
					'post_title'   => '',
					'post_content' => '',
				],
				$args
			)
		);

		// Seed the term with a real human label so label-fallback tests
		// assert "term name, not slug" rather than ad-hoc term casing.
		if ( ! term_exists( $kind, 'kind' ) ) {
			wp_insert_term( ucfirst( $kind ), 'kind', [ 'slug' => $kind ] );
		}
		wp_set_object_terms( $post_id, $kind, 'kind' );

		foreach ( $meta as $suffix => $value ) {
			update_post_meta( $post_id, Meta_Fields::PREFIX . $suffix, $value );
		}

		return get_post( $post_id );
	}

	public function test_real_post_title_is_never_replaced() {
		$post = $this->make_kind_post(
			'listen',
			[
				'listen_track'  => 'Song',
				'listen_artist' => 'Artist',
			],
			[ 'post_title' => 'My own words' ]
		);

		$this->assertSame( '', Atmosphere_Titles::derive( $post ) );
	}

	public function test_listen_with_track_and_artist() {
		$post = $this->make_kind_post(
			'listen',
			[
				'listen_track'  => 'Range Life',
				'listen_artist' => 'Pavement',
			]
		);

		$this->assertSame( 'Listened to Range Life by Pavement', Atmosphere_Titles::derive( $post ) );
	}

	public function test_listen_with_track_only() {
		$post = $this->make_kind_post( 'listen', [ 'listen_track' => 'Range Life' ] );

		$this->assertSame( 'Listened to Range Life', Atmosphere_Titles::derive( $post ) );
	}

	public function test_jam() {
		$post = $this->make_kind_post(
			'jam',
			[
				'jam_track'  => 'Cut Your Hair',
				'jam_artist' => 'Pavement',
			]
		);

		$this->assertSame( 'Jam: Cut Your Hair by Pavement', Atmosphere_Titles::derive( $post ) );
	}

	public function test_watch_movie() {
		$post = $this->make_kind_post( 'watch', [ 'watch_title' => 'Stalker' ] );

		$this->assertSame( 'Watched Stalker', Atmosphere_Titles::derive( $post ) );
	}

	public function test_watch_tv_episode() {
		$post = $this->make_kind_post(
			'watch',
			[
				'watch_media_type'    => 'tv',
				'watch_show_title'    => 'Severance',
				'watch_season'        => '2',
				'watch_episode'       => '4',
				'watch_episode_title' => 'Woe\'s Hollow',
			]
		);

		$this->assertSame( 'Watched Severance S2E4: Woe\'s Hollow', Atmosphere_Titles::derive( $post ) );
	}

	public function test_read_with_author() {
		$post = $this->make_kind_post(
			'read',
			[
				'read_title'  => 'Middlemarch',
				'read_author' => 'George Eliot',
			]
		);

		$this->assertSame( 'Read Middlemarch by George Eliot', Atmosphere_Titles::derive( $post ) );
	}

	public function test_checkin_names_venue_when_not_private() {
		$post = $this->make_kind_post(
			'checkin',
			[
				'checkin_name' => 'Powell\'s Books',
				'geo_privacy'  => 'approximate',
			]
		);

		$this->assertSame( 'Checked in at Powell\'s Books', Atmosphere_Titles::derive( $post ) );
	}

	public function test_private_checkin_omits_venue() {
		$post = $this->make_kind_post(
			'checkin',
			[
				'checkin_name' => 'Powell\'s Books',
				'geo_privacy'  => 'private',
			]
		);

		$this->assertSame( 'Checked in', Atmosphere_Titles::derive( $post ) );
	}

	public function test_play() {
		$post = $this->make_kind_post( 'play', [ 'play_title' => 'Outer Wilds' ] );

		$this->assertSame( 'Played Outer Wilds', Atmosphere_Titles::derive( $post ) );
	}

	public function test_eat_and_drink() {
		$eat   = $this->make_kind_post( 'eat', [ 'eat_name' => 'Khao soi' ] );
		$drink = $this->make_kind_post( 'drink', [ 'drink_name' => 'Flat white' ] );

		$this->assertSame( 'Ate Khao soi', Atmosphere_Titles::derive( $eat ) );
		$this->assertSame( 'Drank Flat white', Atmosphere_Titles::derive( $drink ) );
	}

	public function test_rsvp_uses_cited_event_name() {
		$post = $this->make_kind_post(
			'rsvp',
			[
				'cite_name'   => 'WordCamp US',
				'rsvp_status' => 'yes',
			]
		);

		$this->assertSame( 'RSVP: WordCamp US', Atmosphere_Titles::derive( $post ) );
	}

	public function test_reaction_kinds_use_cite_name() {
		$expected = [
			'like'     => 'Liked A Great Post',
			'reply'    => 'Replied to A Great Post',
			'repost'   => 'Reposted A Great Post',
			'bookmark' => 'Bookmarked A Great Post',
		];

		foreach ( $expected as $kind => $title ) {
			$post = $this->make_kind_post( $kind, [ 'cite_name' => 'A Great Post' ] );

			$this->assertSame( $title, Atmosphere_Titles::derive( $post ), "kind: $kind" );
		}
	}

	public function test_reaction_falls_back_to_cited_host() {
		$post = $this->make_kind_post( 'like', [ 'cite_url' => 'https://www.example.com/a-post' ] );

		$this->assertSame( 'Liked example.com', Atmosphere_Titles::derive( $post ) );
	}

	public function test_favorite_prefers_favorite_name() {
		$post = $this->make_kind_post(
			'favorite',
			[
				'favorite_name' => 'The Thing',
				'cite_name'     => 'Other',
			]
		);

		$this->assertSame( 'Favorited The Thing', Atmosphere_Titles::derive( $post ) );
	}

	public function test_wish_mood_acquisition() {
		$wish = $this->make_kind_post( 'wish', [ 'wish_name' => 'A greenhouse' ] );
		$mood = $this->make_kind_post(
			'mood',
			[
				'mood_emoji' => '🌤️',
				'mood_label' => 'Hopeful',
			]
		);
		$acq  = $this->make_kind_post( 'acquisition', [ 'acquisition_name' => 'Field recorder' ] );

		$this->assertSame( 'Wish: A greenhouse', Atmosphere_Titles::derive( $wish ) );
		$this->assertSame( 'Mood: 🌤️ Hopeful', Atmosphere_Titles::derive( $mood ) );
		$this->assertSame( 'Acquired Field recorder', Atmosphere_Titles::derive( $acq ) );
	}

	public function test_untitled_note_derives_from_content() {
		$post = $this->make_kind_post(
			'note',
			[],
			[ 'post_content' => '<p>Walked the long way home and the light was doing that thing again over the river.</p>' ]
		);

		$this->assertSame(
			'Walked the long way home and the light was doing…',
			Atmosphere_Titles::derive( $post )
		);
	}

	public function test_empty_everything_falls_back_to_kind_label() {
		$post = $this->make_kind_post( 'checkin', [ 'geo_privacy' => 'private' ] );

		$this->assertSame( 'Checked in', Atmosphere_Titles::derive( $post ) );

		$bare = $this->make_kind_post( 'listen' );

		// No track, no content: the kind term's human label.
		$this->assertSame( 'Listen', Atmosphere_Titles::derive( $bare ) );
	}

	public function test_derived_title_is_capped_at_500_graphemes() {
		$post = $this->make_kind_post(
			'listen',
			[ 'listen_track' => str_repeat( 'à', 600 ) ]
		);

		$title = Atmosphere_Titles::derive( $post );

		$this->assertLessThanOrEqual( 500, function_exists( 'grapheme_strlen' ) ? grapheme_strlen( $title ) : mb_strlen( $title ) );
	}

	public function test_derivation_is_filterable() {
		$post = $this->make_kind_post( 'play', [ 'play_title' => 'Outer Wilds' ] );

		add_filter(
			'pkiw_atmosphere_document_title',
			static function ( $title, $filtered_post, $kind ) {
				return 'Now playing: Outer Wilds';
			},
			10,
			3
		);

		$this->assertSame( 'Now playing: Outer Wilds', Atmosphere_Titles::derive( $post ) );

		remove_all_filters( 'pkiw_atmosphere_document_title' );
	}

	public function test_registered_meta_defaults_do_not_leak_between_kinds() {
		// A note post must not pick up watch/read/play registered defaults.
		$post = $this->make_kind_post(
			'note',
			[],
			[ 'post_content' => 'Short note.' ]
		);

		$this->assertSame( 'Short note.', Atmosphere_Titles::derive( $post ) );
	}
}
