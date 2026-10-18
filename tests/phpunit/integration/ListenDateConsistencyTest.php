<?php
/**
 * Listen date consistency across facts, cards, and lineup lines (issue 236).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Listen_Archive;
use PKIW\Listen_Media_Type;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class ListenDateConsistencyTest extends WP_UnitTestCase {

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
	}

	public function tear_down(): void {
		update_option( 'timezone_string', $this->original_timezone );
		update_option( 'date_format', $this->original_date_format );
		update_option( 'time_format', $this->original_time_format );
		switch_theme( $this->original_stylesheet );
		$this->set_permalink_structure( '' );
		wp_set_current_user( 0 );
		wp_reset_postdata();
		parent::tear_down();
	}

	private function listen( string $stored ): int {
		$id = self::factory()->post->create(
			[
				'post_status' => 'publish',
				'post_title'  => 'Listened to Time Signals',
				'post_date'   => '2026-08-14 10:15:00',
			]
		);
		wp_set_object_terms( $id, 'listen', Taxonomy::TAXONOMY );
		update_post_meta( $id, Listen_Media_Type::META, Listen_Media_Type::PODCAST );
		update_post_meta( $id, '_pkiw_listen_track', 'Time Signals' );
		update_post_meta( $id, '_pkiw_listen_album', 'Clockwork Radio' );
		update_post_meta( $id, '_pkiw_listen_listened_at', $stored );

		return (int) $id;
	}

	/**
	 * @dataProvider date_cases
	 */
	public function test_facts_use_one_wall_clock_rule( string $timezone, string $raw, string $day, string $time, bool $stored, bool $has_time ): void {
		update_option( 'timezone_string', $timezone );
		$facts = Listen_Archive::facts( $this->listen( $raw ) );

		$this->assertSame( $day, $facts['day_iso'] );
		$this->assertSame( $time, $facts['time_display'] );
		$this->assertSame( $stored, $facts['listened_stored'] );
		$this->assertSame( $has_time, $facts['listened_has_time'] );
		$this->assertSame( $stored ? trim( $raw ) : '', $facts['listened_value'] );
	}

	/**
	 * @return array<string, array{string, string, string, string, bool, bool}>
	 */
	public function date_cases(): array {
		return [
			'New York local time' => [ 'America/New_York', '2026-09-26T02:00:00', '2026-09-26', '2:00 AM', true, true ],
			'Chicago local time'  => [ 'America/Chicago', '2026-09-26T02:00:00', '2026-09-26', '2:00 AM', true, true ],
			'New York offset'     => [ 'America/New_York', '2026-09-26T23:30:00-05:00', '2026-09-27', '12:30 AM', true, true ],
			'Chicago offset'      => [ 'America/Chicago', '2026-09-26T23:30:00-05:00', '2026-09-26', '11:30 PM', true, true ],
			'date only'           => [ 'America/New_York', '2026-09-26', '2026-09-26', '', true, false ],
			'empty fallback'      => [ 'America/New_York', '', '2026-08-14', '', false, false ],
			'garbage fallback'    => [ 'America/New_York', 'not a date', '2026-08-14', '', false, false ],
		];
	}

	/**
	 * @dataProvider offset_zones
	 */
	public function test_offset_dates_match_the_listen_card_and_lineup( string $timezone ): void {
		update_option( 'timezone_string', $timezone );
		$raw = '2026-09-26T23:30:00-05:00';
		$id = $this->listen( $raw );
		$post = get_post( $id );
		$this->assertInstanceOf( WP_Post::class, $post );
		$GLOBALS['post'] = $post;
		setup_postdata( $post );
		$facts = Listen_Archive::facts( $id );

		$card = do_blocks( '<!-- wp:post-kinds-indieweb/listen-card {"trackTitle":"Time Signals","listenedAt":"' . $raw . '"} /-->' );
		$xpath = $this->xpath( $card );
		$time = $xpath->query( '//time[contains(concat(" ",@class," ")," dt-published ")]' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $time );
		$this->assertSame( $facts['day_iso'], substr( $time->getAttribute( 'datetime' ), 0, 10 ) );
		$this->assertSame( $facts['day_display'], trim( $time->textContent ) );

		$block = new WP_Block(
			[
				'blockName'    => Listen_Archive::EPISODE_BLOCK,
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			],
			[ 'postId' => $id, 'postType' => 'post' ]
		);
		$line = Listen_Archive::render( [], '', $block );
		$this->assertMatchesRegularExpression( '/<time[^>]+datetime="' . preg_quote( $facts['day_iso'], '/' ) . '"/', $line );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function offset_zones(): array {
		return [
			'New York' => [ 'America/New_York' ],
			'Chicago'  => [ 'America/Chicago' ],
		];
	}

	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();

		return new DOMXPath( $dom );
	}
}
