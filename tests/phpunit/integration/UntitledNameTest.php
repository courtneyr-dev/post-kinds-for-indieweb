<?php
/**
 * Untitled post naming coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * @group integration
 */
final class UntitledNameTest extends WP_UnitTestCase {

	public function test_like_uses_card_title(): void {
		$post = $this->make_post( 'like', '<!-- wp:post-kinds-indieweb/like-card {"title":"A fictional essay","url":"https://example.com/a"} /-->' );
		$this->assertSame( 'A fictional essay', \PKIW\untitled_name( $post ) );
	}

	public function test_like_falls_back_to_card_url_host(): void {
		$post = $this->make_post( 'like', '<!-- wp:post-kinds-indieweb/like-card {"url":"https://www.example.com/a"} /-->' );
		$this->assertSame( 'example.com', \PKIW\untitled_name( $post ) );
	}

	public function test_reply_uses_cite_name_meta(): void {
		$post = $this->make_post( 'reply' );
		update_post_meta( $post->ID, '_pkiw_cite_name', 'Fictional reply target' );
		$this->assertSame( 'Fictional reply target', \PKIW\untitled_name( $post ) );
	}

	public function test_favorite_prefers_favorite_name_meta(): void {
		$post = $this->make_post( 'favorite' );
		update_post_meta( $post->ID, '_pkiw_favorite_name', 'Favorite fictional page' );
		update_post_meta( $post->ID, '_pkiw_cite_name', 'Generic citation' );
		$this->assertSame( 'Favorite fictional page', \PKIW\untitled_name( $post ) );
	}

	public function test_follow_uses_cite_url_host(): void {
		$post = $this->make_post( 'follow' );
		update_post_meta( $post->ID, '_pkiw_cite_url', 'https://www.example.org/person' );
		$this->assertSame( 'example.org', \PKIW\untitled_name( $post ) );
	}

	public function test_note_trims_plain_text_to_twenty_five_words(): void {
		$words = implode( ' ', array_map( static fn( int $number ): string => 'word' . $number, range( 1, 40 ) ) );
		$post  = $this->make_post( 'note', '<!-- wp:paragraph --><p>' . $words . '</p><!-- /wp:paragraph -->' );

		$this->assertSame( implode( ' ', array_map( static fn( int $number ): string => 'word' . $number, range( 1, 25 ) ) ) . '…', \PKIW\untitled_name( $post ) );
	}

	public function test_note_prefers_excerpt(): void {
		$post = $this->make_post( 'note', '<!-- wp:paragraph --><p>Body thought.</p><!-- /wp:paragraph -->', 'Excerpt thought.' );
		$this->assertSame( 'Excerpt thought.', \PKIW\untitled_name( $post ) );
	}

	public function test_question_uses_plain_text_content(): void {
		$post = $this->make_post( 'question', '<!-- wp:paragraph --><p>What should Fictional Person read next?</p><!-- /wp:paragraph -->' );
		$this->assertSame( 'What should Fictional Person read next?', \PKIW\untitled_name( $post ) );
	}

	public function test_content_can_be_disabled(): void {
		$post = $this->make_post( 'note', '<!-- wp:paragraph --><p>A thought that must not become the title.</p><!-- /wp:paragraph -->' );
		$this->assertSame( 'Note, September 12, 2026', \PKIW\untitled_name( $post, false ) );
	}

	public function test_weather_uses_visible_observation(): void {
		require_once dirname( __DIR__ ) . '/fixtures/simple-location/weather-stub.php';
		update_option( 'sloc_measurements', 'metric' );
		$post = $this->make_post( 'weather' );
		add_post_meta( $post->ID, 'weather_temperature', 26.8 );
		add_post_meta( $post->ID, 'weather_code', 800 );
		add_post_meta( $post->ID, 'geo_public', '1' );

		$this->assertSame( 'Clear Sky, 27 °C', \PKIW\untitled_name( $post ) );
	}

	/**
	 * Reading the name prints nothing, so it must not mark the post's
	 * weather as shown: that flag turns off Simple Location's own weather
	 * line in the post's later the_content pass.
	 */
	public function test_weather_name_leaves_weather_unmarked(): void {
		require_once dirname( __DIR__ ) . '/fixtures/simple-location/weather-stub.php';
		update_option( 'sloc_measurements', 'metric' );
		$post = $this->make_post( 'weather' );
		add_post_meta( $post->ID, 'weather_summary', 'Sun & "cloud"' );
		add_post_meta( $post->ID, 'weather_temperature', 26.8 );
		add_post_meta( $post->ID, 'geo_public', '1' );

		$bound = new ReflectionProperty( \PKIW\Integrations\Simple_Location_Weather::class, 'bound' );
		$bound->setValue( null, [] );

		$this->assertSame( 'Sun & "cloud", 27 °C', \PKIW\untitled_name( $post ) );
		$this->assertArrayNotHasKey( $post->ID, $bound->getValue() );
	}

	/**
	 * @dataProvider fallback_posts
	 */
	public function test_fallback_names( string $kind, string $expected ): void {
		$post = $this->make_post( $kind );
		$this->assertSame( $expected, \PKIW\untitled_name( $post ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function fallback_posts(): array {
		return [
			'weather' => [ 'weather', 'Weather, September 12, 2026' ],
			'checkin' => [ 'checkin', 'Check-in, September 12, 2026' ],
		];
	}

	public function test_password_protected_note_skips_content(): void {
		$post = $this->make_post( 'note', '<!-- wp:paragraph --><p>Private thought.</p><!-- /wp:paragraph -->', '', 'secret' );
		$this->assertSame( 'Note, September 12, 2026', \PKIW\untitled_name( $post ) );
	}

	public function test_unkinded_post_falls_back_to_note(): void {
		$post_id = wp_insert_post( [ 'post_title' => '', 'post_content' => '<!-- wp:paragraph --><p></p><!-- /wp:paragraph -->', 'post_date' => '2026-09-12 12:00:00', 'post_status' => 'publish' ], true );
		$this->assertNotWPError( $post_id );
		$this->assertSame( 'Note, September 12, 2026', \PKIW\untitled_name( get_post( $post_id ) ) );
	}

	private function make_post( string $kind, string $content = '', string $excerpt = '', string $password = '' ): WP_Post {
		if ( ! term_exists( $kind, 'kind' ) ) {
			$name = 'checkin' === $kind ? 'Check-in' : ucfirst( $kind );
			$this->assertNotWPError( wp_insert_term( $name, 'kind', [ 'slug' => $kind ] ) );
		}
		if ( '' === $content ) {
			$content = '<!-- wp:paragraph --><p></p><!-- /wp:paragraph -->';
		}
		$post_id = wp_insert_post(
			[
				'post_title'    => '',
				'post_content'  => $content,
				'post_excerpt'  => $excerpt,
				'post_password' => $password,
				'post_date'     => '2026-09-12 12:00:00',
				'post_status'   => 'publish',
			],
			true
		);
		$this->assertNotWPError( $post_id );
		$this->assertNotWPError( wp_set_object_terms( $post_id, $kind, 'kind' ) );

		return get_post( $post_id );
	}
}
