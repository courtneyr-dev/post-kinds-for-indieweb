<?php
/**
 * Podcast listen facts (issue 236).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Kind_Facts;
use PKIW\Listen_Archive;
use PKIW\Listen_Media_Type;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class ListenFactsTest extends WP_UnitTestCase {

	private string $original_stylesheet = '';

	private string $original_timezone = '';

	private string $original_date_format = '';

	private string $original_time_format = '';

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet  = get_stylesheet();
		$this->original_timezone    = (string) get_option( 'timezone_string' );
		$this->original_date_format = (string) get_option( 'date_format' );
		$this->original_time_format = (string) get_option( 'time_format' );
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'date_format', 'F j, Y' );
		update_option( 'time_format', 'g:i A' );
		switch_theme( 'twentytwentyfive' );
		( new Meta_Fields() )->register_meta_fields();
		( new Taxonomy() )->create_default_terms();
		( new Listen_Archive() )->register();
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
		wp_set_current_user( 0 );
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
		add_filter( 'pre_oembed_result', [ $this, 'oembed_result' ], 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_oembed_result', [ $this, 'oembed_result' ], 10 );
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
		update_option( 'timezone_string', $this->original_timezone );
		update_option( 'date_format', $this->original_date_format );
		update_option( 'time_format', $this->original_time_format );
		switch_theme( $this->original_stylesheet );
		$this->set_permalink_structure( '' );
		wp_set_current_user( 0 );
		wp_reset_postdata();
		parent::tear_down();
	}

	/**
	 * Return a deterministic player for the fictional provider.
	 *
	 * @param mixed                $result Existing result.
	 * @param string               $url    Requested URL.
	 * @param array<string, mixed> $args   Request arguments.
	 * @return mixed
	 */
	public function oembed_result( $result, string $url, array $args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return str_contains( $url, 'audio.example.org' ) ? '<iframe src="https://audio.example.org/player/episode"></iframe>' : $result;
	}

	/**
	 * A listen fixture.
	 *
	 * @param string               $title Post title.
	 * @param array<string, mixed> $meta  Listen metadata.
	 * @param array<string, mixed> $args  Post overrides.
	 * @param string               $kind  Kind slug.
	 */
	private function listen( string $title = 'Listened to Harbor Signals', array $meta = [], array $args = [], string $kind = 'listen' ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status' => 'publish',
					'post_title'  => $title,
					'post_date'   => '2026-09-26 14:00:00',
				],
				$args
			)
		);
		wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY );
		$defaults = [
			Listen_Media_Type::META => Listen_Media_Type::PODCAST,
			'_pkiw_listen_track'     => 'Harbor Signals',
			'_pkiw_listen_artist'    => 'Avery Stone',
			'_pkiw_listen_album'     => 'Alder Street Radio',
			'_pkiw_listen_url'       => 'https://audio.example.org/episodes/harbor-signals',
			'_pkiw_listen_rating'    => '4',
			'_pkiw_listen_listened_at' => '2026-09-26T02:00:00',
		];
		foreach ( array_merge( $defaults, $meta ) as $key => $value ) {
			if ( null === $value ) {
				delete_post_meta( $id, $key );
			} else {
				update_post_meta( $id, $key, $value );
			}
		}

		return (int) $id;
	}

	public function test_podcast_facts_have_exactly_the_public_contract_keys(): void {
		$keys = array_keys( Listen_Archive::facts( $this->listen() ) );
		$expected = [
			'media_type', 'is_podcast', 'name', 'name_is_title', 'episode', 'episode_text',
			'episode_differs', 'show', 'url', 'rating', 'rating_label', 'listened_value',
			'listened_has_time', 'listened_stored', 'day_iso', 'day_display', 'time_display',
			'picture', 'cover_alt', 'embed_html', 'kind_label',
		];
		sort( $keys );
		sort( $expected );

		$this->assertSame( $expected, $keys );
	}

	public function test_media_type_is_read_from_the_stored_row_only(): void {
		$music = $this->listen( 'Music', [ Listen_Media_Type::META => Listen_Media_Type::MUSIC ] );
		$unset = $this->listen( 'Unset', [ Listen_Media_Type::META => null ] );

		$this->assertSame( 'music', Listen_Archive::facts( $music )['media_type'] );
		$this->assertFalse( Listen_Archive::facts( $music )['is_podcast'] );
		$this->assertSame( '', Listen_Archive::facts( $unset )['media_type'] );
		$this->assertFalse( Listen_Archive::facts( $unset )['is_podcast'] );
	}

	public function test_the_registered_reader_matches_the_archive_reader(): void {
		$id = $this->listen();

		$this->assertNotNull( Kind_Facts::reader( 'listen' ) );
		$this->assertSame( Listen_Archive::facts( $id ), Kind_Facts::get( $id ) );
	}

	public function test_other_kinds_and_unreadable_posts_have_no_facts(): void {
		$note = $this->listen( 'A note', [], [], 'note' );
		$locked = $this->listen( 'Locked episode', [], [ 'post_password' => 'secret' ] );
		$private = $this->listen( 'Private episode', [], [ 'post_status' => 'private' ] );

		$this->assertSame( [], Listen_Archive::facts( $note ) );
		$this->assertSame( [], Listen_Archive::facts( $locked ) );
		$this->assertSame( [], Listen_Archive::facts( $private ) );
	}

	public function test_a_password_holder_can_read_facts_but_an_editor_without_the_password_cannot(): void {
		$id = $this->listen( 'Locked episode', [], [ 'post_password' => 'secret' ] );
		require_once ABSPATH . WPINC . '/class-phpass.php';
		$_COOKIE[ 'wp-postpass_' . COOKIEHASH ] = ( new PasswordHash( 8, true ) )->HashPassword( 'secret' );

		$this->assertFalse( post_password_required( $id ) );
		$this->assertNotSame( [], Listen_Archive::facts( $id ) );

		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertSame( [], Listen_Archive::facts( $id ) );
	}

	public function test_an_editor_can_read_a_private_podcast(): void {
		$id = $this->listen( 'Private episode', [], [ 'post_status' => 'private' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->assertNotSame( [], Listen_Archive::facts( $id ) );
	}

	public function test_episode_name_fallback_and_difference_rules(): void {
		$empty = $this->listen( 'Untitled Track Replacement', [ '_pkiw_listen_track' => '' ] );
		$same = $this->listen( 'Same Words', [ '_pkiw_listen_track' => '  same words ' ] );
		$different = $this->listen( 'Same Words', [ '_pkiw_listen_track' => 'Listened to Same Words' ] );

		$this->assertSame( '', Listen_Archive::facts( $empty )['episode'] );
		$this->assertSame( Listen_Archive::facts( $empty )['name'], Listen_Archive::facts( $empty )['episode_text'] );
		$this->assertFalse( Listen_Archive::facts( $empty )['episode_differs'] );
		$this->assertFalse( Listen_Archive::facts( $same )['episode_differs'] );
		$this->assertTrue( Listen_Archive::facts( $different )['episode_differs'] );
	}

	public function test_titled_and_untitled_names_are_distinguished(): void {
		$titled = $this->listen( 'Named post' );
		$untitled = $this->listen( '' );

		$this->assertTrue( Listen_Archive::facts( $titled )['name_is_title'] );
		$this->assertSame( 'Named post', Listen_Archive::facts( $titled )['name'] );
		$this->assertFalse( Listen_Archive::facts( $untitled )['name_is_title'] );
		$this->assertSame( \PKIW\untitled_name( get_post( $untitled ), false ), Listen_Archive::facts( $untitled )['name'] );
	}

	public function test_text_url_rating_and_kind_labels_are_normalized(): void {
		$valid = $this->listen(
			'Labels',
			[
				'_pkiw_listen_album'  => '  Alder Street Radio  ',
				'_pkiw_listen_url'    => 'https://example.com/episode',
				'_pkiw_listen_rating' => '4',
			]
		);
		$invalid = $this->listen( 'Invalid', [ '_pkiw_listen_url' => 'javascript:alert(1)', '_pkiw_listen_rating' => 'not a number' ] );

		$this->assertSame( 'Alder Street Radio', Listen_Archive::facts( $valid )['show'] );
		$this->assertSame( 'https://example.com/episode', Listen_Archive::facts( $valid )['url'] );
		$this->assertSame( 4.0, Listen_Archive::facts( $valid )['rating'] );
		$this->assertSame( 'Rated 4 of 5', Listen_Archive::facts( $valid )['rating_label'] );
		$this->assertSame( 'Listen', Listen_Archive::facts( $valid )['kind_label'] );
		$this->assertSame( '', Listen_Archive::facts( $invalid )['url'] );
		$this->assertSame( '', Listen_Archive::facts( $invalid )['rating_label'] );
	}

	public function test_featured_artwork_wins_and_cover_alt_follows_the_fallback_order(): void {
		$stored = $this->listen( 'Stored cover', [ '_pkiw_listen_cover' => 'https://img.example.org/stored.jpg' ] );
		$no_artist = $this->listen( 'No artist', [ '_pkiw_listen_artist' => '', '_pkiw_listen_cover' => 'https://img.example.org/no-artist.jpg' ] );
		$featured = $this->listen( 'Featured', [ '_pkiw_listen_cover' => 'https://img.example.org/ignored.jpg' ] );
		$image = (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $featured );
		update_post_meta( $image, '_wp_attachment_image_alt', 'Stored attachment description' );
		set_post_thumbnail( $featured, $image );

		$this->assertSame( 'Harbor Signals — Avery Stone', Listen_Archive::facts( $stored )['cover_alt'] );
		$this->assertSame( 'Harbor Signals', Listen_Archive::facts( $no_artist )['cover_alt'] );
		$this->assertSame( 'featured', Listen_Archive::facts( $featured )['picture']['source'] );
		$this->assertSame( 'Stored attachment description', Listen_Archive::facts( $featured )['cover_alt'] );
	}

	public function test_no_fact_key_names_a_location_or_coordinate(): void {
		foreach ( array_keys( Listen_Archive::facts( $this->listen() ) ) as $key ) {
			foreach ( [ 'lat', 'lon', 'geo', 'location', 'place', 'venue' ] as $banned ) {
				$this->assertStringNotContainsString( $banned, $key, $key );
			}
		}
	}

	public function test_embed_html_is_loaded_only_on_the_posts_own_singular_request(): void {
		$id = $this->listen();
		$this->go_to( get_term_link( 'listen', Taxonomy::TAXONOMY ) );
		$this->assertSame( '', Listen_Archive::facts( $id )['embed_html'] );

		$this->go_to( get_permalink( $id ) );
		$this->assertStringContainsString( '<iframe', Listen_Archive::facts( $id )['embed_html'] );

		update_post_meta( $id, '_pkiw_listen_url', 'https://example.com/no-provider' );
		$this->assertSame( '', Listen_Archive::facts( $id )['embed_html'] );
	}
}
