<?php
/**
 * Reading time calculations for article archives (issue 263).
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * @group integration
 */
final class ReadingTimeTest extends WP_UnitTestCase {

	/**
	 * Posts created by this test.
	 *
	 * @var int[]
	 */
	private array $posts = [];

	public function tear_down(): void {
		remove_all_filters( 'pkiw_reading_words_per_minute' );
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
		foreach ( $this->posts as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		$this->posts = [];
		parent::tear_down();
	}

	public function test_one_thousand_words_give_five_minutes_and_a_label(): void {
		$this->assert_reading_time_class_exists();
		$post_id = $this->post_with_words( 1000 );

		$this->assertSame( 5, \PKIW\Reading_Time::minutes( $post_id ) );
		$this->assertSame( '5 min read', \PKIW\Reading_Time::label( 5 ) );
	}

	public function test_one_word_gives_one_minute(): void {
		$this->assert_reading_time_class_exists();

		$this->assertSame( 1, \PKIW\Reading_Time::minutes( $this->post_with_words( 1 ) ) );
	}

	public function test_an_empty_body_gives_one_minute(): void {
		$this->assert_reading_time_class_exists();

		$this->assertSame( 1, \PKIW\Reading_Time::minutes( $this->post_with_content( '' ) ) );
	}

	public function test_two_hundred_words_give_one_minute(): void {
		$this->assert_reading_time_class_exists();

		$this->assertSame( 1, \PKIW\Reading_Time::minutes( $this->post_with_words( 200 ) ) );
	}

	public function test_two_hundred_and_one_words_give_two_minutes(): void {
		$this->assert_reading_time_class_exists();

		$this->assertSame( 2, \PKIW\Reading_Time::minutes( $this->post_with_words( 201 ) ) );
	}

	public function test_a_words_per_minute_filter_at_one_hundred_gives_ten_minutes(): void {
		$this->assert_reading_time_class_exists();
		add_filter( 'pkiw_reading_words_per_minute', static fn(): int => 100 );

		$this->assertSame( 10, \PKIW\Reading_Time::minutes( $this->post_with_words( 1000 ) ) );
	}

	public function test_a_zero_words_per_minute_filter_falls_back_to_two_hundred(): void {
		$this->assert_reading_time_class_exists();
		add_filter( 'pkiw_reading_words_per_minute', static fn(): int => 0 );

		$this->assertSame( 5, \PKIW\Reading_Time::minutes( $this->post_with_words( 1000 ) ) );
	}

	public function test_a_negative_words_per_minute_filter_falls_back_to_two_hundred(): void {
		$this->assert_reading_time_class_exists();
		add_filter( 'pkiw_reading_words_per_minute', static fn(): int => -5 );

		$this->assertSame( 5, \PKIW\Reading_Time::minutes( $this->post_with_words( 1000 ) ) );
	}

	public function test_tags_block_comments_and_shortcodes_are_not_words(): void {
		$this->assert_reading_time_class_exists();
		add_shortcode( 'pkiwrt', static fn(): string => 'shortcode words ignored' );
		$content = '<!-- wp:paragraph --><p>One <strong>two</strong> [pkiwrt] three.</p><!-- /wp:paragraph -->';

		$this->assertSame( 3, \PKIW\Reading_Time::word_count( $content ) );
		remove_shortcode( 'pkiwrt' );
	}

	public function test_character_word_count_type_counts_characters_without_spaces(): void {
		$this->assert_reading_time_class_exists();
		$original = $GLOBALS['wp_locale']->word_count_type;
		$GLOBALS['wp_locale']->word_count_type = 'characters_excluding_spaces';

		try {
			$this->assertSame( 4, \PKIW\Reading_Time::word_count( 'ab cd' ) );
		} finally {
			$GLOBALS['wp_locale']->word_count_type = $original;
		}
	}

	public function test_a_protected_post_gives_no_reading_time(): void {
		$this->assert_reading_time_class_exists();
		$post_id = $this->post_with_words( 1000, [ 'post_password' => 'secret' ] );

		$this->assertNull( \PKIW\Reading_Time::minutes( $post_id ) );
	}

	public function test_a_protected_post_with_its_cookie_gives_reading_time(): void {
		$this->assert_reading_time_class_exists();
		$post_id = $this->post_with_words( 1000, [ 'post_password' => 'secret' ] );
		$_COOKIE[ 'wp-postpass_' . COOKIEHASH ] = wp_hash_password( 'secret' );

		$this->assertSame( 5, \PKIW\Reading_Time::minutes( $post_id ) );
	}

	public function test_minutes_does_not_write_postmeta(): void {
		global $wpdb;

		$this->assert_reading_time_class_exists();
		$post_id = $this->post_with_words( 1000 );
		$before  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d", $post_id ) );

		\PKIW\Reading_Time::minutes( $post_id );

		$after = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d", $post_id ) );
		$this->assertSame( $before, $after );
	}

	private function assert_reading_time_class_exists(): void {
		$this->assertTrue( class_exists( 'PKIW\\Reading_Time' ), 'PKIW\\Reading_Time must exist for issue 263.' );
	}

	/**
	 * @param array<string, mixed> $args Extra post args.
	 */
	private function post_with_words( int $words, array $args = [] ): int {
		return $this->post_with_content( str_repeat( 'word ', $words ), $args );
	}

	/**
	 * @param array<string, mixed> $args Extra post args.
	 */
	private function post_with_content( string $content, array $args = [] ): int {
		$post_id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Fictional Article',
					'post_content' => $content,
				],
				$args
			)
		);
		$this->posts[] = $post_id;

		return $post_id;
	}
}
