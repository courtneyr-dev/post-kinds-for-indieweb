<?php
/**
 * The sleep night entry block: one cell of the month sheet (issue 247).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Grouped_Archive;
use PKIW\Grouping\Archive_Group;
use PKIW\Meta_Fields;
use PKIW\Sleep_Archive;

/**
 * A cell is the inside of the engine's list item: the stored title if there
 * is one, the date, the duration, a bar, the moon and the phase, with one
 * link named by those parts. It never prints a bedtime or a wake time, even
 * for a post that opts in.
 *
 * @group integration
 */
final class SleepNightBlockTest extends WP_UnitTestCase {

	private string $original_timezone = '';

	private int $author = 0;

	public function set_up(): void {
		parent::set_up();
		$this->original_timezone = (string) get_option( 'timezone_string' );
		update_option( 'timezone_string', 'America/New_York' );
		( new Meta_Fields() )->register_meta_fields();
		foreach ( [ 'sleep', 'note' ] as $slug ) {
			if ( ! term_exists( $slug, 'kind' ) ) {
				wp_insert_term( ucfirst( $slug ), 'kind', [ 'slug' => $slug ] );
			}
		}
		$this->author = (int) self::factory()->user->create( [ 'display_name' => 'Example Author' ] );
		$this->set_permalink_structure( '/%postname%/' );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		set_current_screen( 'front' );
		remove_all_filters( 'pkiw_sleep_moon_html' );
		update_option( 'timezone_string', $this->original_timezone );
		$this->set_permalink_structure( '' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * @param array<string, mixed> $attrs Card attributes.
	 * @param array<string, mixed> $post  Post overrides.
	 */
	private function sleep( string $night, array $attrs, array $post = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_author'  => $this->author,
					'post_title'   => '',
					'post_date'    => $night . ' 12:00:00',
					'post_content' => [] === $attrs ? '<!-- wp:post-kinds-indieweb/sleep-card /-->' : '<!-- wp:post-kinds-indieweb/sleep-card ' . serialize_block_attributes( $attrs ) . ' /-->',
				],
				$post
			)
		);
		wp_set_object_terms( $id, 'sleep', 'kind' );

		return (int) $id;
	}

	/**
	 * The cell the entry block prints for a post.
	 *
	 * @param array<string, mixed> $attrs Block attributes.
	 */
	private function cell( int $id, array $attrs = [] ): string {
		$block = new WP_Block( [ 'blockName' => Sleep_Archive::ENTRY, 'attrs' => $attrs ], [ 'postId' => $id ] );

		return Sleep_Archive::render( $attrs, '', $block );
	}

	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();

		return new DOMXPath( $dom );
	}

	public function test_the_block_registers_with_the_engine_defaults_and_a_31_line_page(): void {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( Sleep_Archive::ENTRY );

		$this->assertNotNull( $type );
		$this->assertSame( 31, $type->attributes['linesPerPage']['default'] );
		$this->assertSame( 2, $type->attributes['headingLevel']['default'] );
		$this->assertTrue( $type->attributes['showSections']['default'] );
		$this->assertSame( [ 'core/post-template' ], $type->ancestor );
		$this->assertTrue( Grouped_Archive::is_entry_block( Sleep_Archive::ENTRY ) );
	}

	public function test_an_untitled_night_links_its_date_and_names_the_link_by_the_date_the_duration_and_the_phase(): void {
		$id   = $this->sleep( '2026-08-20', [ 'durationMinutes' => 462 ] );
		$html = $this->cell( $id );

		$this->assertSame(
			'<a class="u-url" href="http://example.org/' . get_post_field( 'post_name', $id ) . '/" aria-labelledby="pkiw-sleep-d-' . $id . ' pkiw-sleep-h-' . $id . ' pkiw-sleep-p-' . $id . '">'
			. '<time class="dt-published pkiw-sleep-night__date" id="pkiw-sleep-d-' . $id . '" datetime="2026-08-20">Aug 20<span class="pk-sr-only">, </span></time></a>'
			. '<span class="pkiw-sleep-night__duration" id="pkiw-sleep-h-' . $id . '"><span class="p-sleep"><time datetime="PT7H42M">7 hr 42 min</time></span><span class="pk-sr-only">, </span></span>'
			. '<span class="pkiw-sleep-night__bar" aria-hidden="true" style="--pkiw-sleep-ratio:0.6417"></span>'
			. '<span class="pkiw-sleep-night__moon" aria-hidden="true"></span>'
			. '<span class="pkiw-sleep-night__phase" id="pkiw-sleep-p-' . $id . '">Waxing gibbous</span>'
			. '<span hidden><span class="p-author h-card"><span class="p-name">Example Author</span><data class="u-url" value="' . esc_url( get_author_posts_url( $this->author ) ) . '"></data>'
			. '<data class="u-photo" value="' . esc_url( get_avatar_url( $this->author, [ 'size' => 96 ] ) ) . '"></data></span></span>',
			$html
		);
	}

	public function test_a_titled_night_prints_a_p_name_heading_that_holds_the_link(): void {
		$id    = $this->sleep( '2026-08-11', [ 'durationMinutes' => 510 ], [ 'post_title' => 'Early night' ] );
		$xpath = $this->xpath( $this->cell( $id ) );

		$heading = $xpath->query( '//*[@id="pkiw-sleep-t-' . $id . '"]' )->item( 0 );
		$this->assertNotNull( $heading );
		$this->assertSame( 'h2', $heading->nodeName, 'Outside a section the title keeps the block level; inside one it sits a level under the month.' );
		$this->assertSame( 'pkiw-sleep-night__title p-name', $heading->getAttribute( 'class' ) );
		$link = $xpath->query( './a[@class="u-url"]', $heading )->item( 0 );
		$this->assertSame( 'Early night', trim( $link->textContent ) );
		$this->assertSame(
			'pkiw-sleep-t-' . $id . ' pkiw-sleep-d-' . $id . ' pkiw-sleep-h-' . $id . ' pkiw-sleep-p-' . $id,
			$link->getAttribute( 'aria-labelledby' )
		);
		$this->assertSame( 1, $xpath->query( '//a[@href]' )->length );
	}

	public function test_heading_level_follows_the_section_level_plus_one(): void {
		$id = $this->sleep( '2026-08-11', [ 'durationMinutes' => 510 ], [ 'post_title' => 'Early night' ] );

		$this->assertStringContainsString( '<h2 class="pkiw-sleep-night__title', $this->cell( $id, [ 'headingLevel' => 2 ] ), 'Outside a section it keeps the block level.' );
		$this->assertStringContainsString( '<h5 class="pkiw-sleep-night__title', $this->cell( $id, [ 'headingLevel' => 5 ] ) );
	}

	public function test_a_night_with_no_duration_prints_date_moon_and_phase_only(): void {
		$id   = $this->sleep( '2026-08-03', [] );
		$html = $this->cell( $id );

		$this->assertStringNotContainsString( 'p-sleep', $html );
		$this->assertStringNotContainsString( '__bar', $html );
		$this->assertStringNotContainsString( '__duration', $html );
		$this->assertStringContainsString( 'Waning gibbous', $html );
		$this->assertStringContainsString( 'aria-labelledby="pkiw-sleep-d-' . $id . ' pkiw-sleep-p-' . $id . '"', $html );
	}

	public function test_an_opted_in_night_still_prints_no_bedtime_or_wake_time_anywhere(): void {
		$id   = $this->sleep(
			'2026-08-11',
			[
				'durationMinutes'   => 510,
				'bedtime'           => '2026-08-11T21:45',
				'wakeTime'          => '2026-08-12T06:15',
				'showTimesPublicly' => true,
			],
			[ 'post_title' => 'Early night' ]
		);
		$html = $this->cell( $id );

		$this->assertTrue( Meta_Fields::health_details_visible( $id ) );
		$this->assertNotSame( '', Sleep_Archive::facts( $id )['bedtime_iso'], 'The facts do carry the time.' );
		foreach ( [ '21:45', '06:15', '9:45', '6:15', 'T21', 'bedtime', 'wake', 'Bedtime' ] as $needle ) {
			$this->assertStringNotContainsStringIgnoringCase( $needle, $html, $needle );
		}
	}

	public function test_class_names_ids_and_data_attributes_never_contain_bedtime_or_wake(): void {
		$id   = $this->sleep( '2026-08-11', [ 'durationMinutes' => 510, 'bedtime' => '2026-08-11T21:45', 'wakeTime' => '2026-08-12T06:15', 'showTimesPublicly' => true ] );
		$html = $this->cell( $id );

		$this->assertSame( 0, preg_match( '/\b(?:class|id|data-[a-z-]+|aria-[a-z]+|style|for)="[^"]*(?:bedtime|wake)/i', $html ) );
	}

	public function test_the_cell_carries_a_date_only_published_and_no_hidden_full_timestamp(): void {
		$id   = $this->sleep( '2026-08-20', [ 'durationMinutes' => 462 ] );
		$html = $this->cell( $id );

		$this->assertSame( 1, substr_count( $html, 'dt-published' ) );
		$this->assertStringContainsString( 'datetime="2026-08-20"', $html );
		$this->assertDoesNotMatchRegularExpression( '/(?:datetime|value)="[^"]*T\d\d:/', $html );
		$this->assertStringNotContainsString( '12:00', $html, 'Not even the noon the post is filed under.' );
	}

	public function test_the_moon_slot_takes_the_themes_drawing_once_and_names_the_phase_and_post(): void {
		$id   = $this->sleep( '2026-08-20', [ 'durationMinutes' => 462 ] );
		$seen = [];
		add_filter(
			'pkiw_sleep_moon_html',
			static function ( $drawing, $phase, $post_id ) use ( &$seen ) {
				$seen[] = [ $phase, $post_id ];
				return '<svg class="theme-moon"></svg>';
			},
			10,
			3
		);
		$html = $this->cell( $id );

		$this->assertSame( [ [ 'waxing-gibbous', $id ] ], $seen );
		$this->assertSame( 1, substr_count( $html, 'theme-moon' ) );
		$this->assertStringContainsString( '<span class="pkiw-sleep-night__moon" aria-hidden="true"><svg class="theme-moon"></svg></span>', $html );
	}

	public function test_a_protected_night_prints_the_frame_only_and_a_link_to_the_password_form(): void {
		$id   = $this->sleep(
			'2026-08-08',
			[ 'durationMinutes' => 430, 'bedtime' => '2026-08-08T23:40', 'wakeTime' => '2026-08-09T06:30', 'showTimesPublicly' => true ],
			[ 'post_password' => 'example-pass', 'post_title' => 'Secret night' ]
		);
		$html = $this->cell( $id );

		$this->assertSame( [], Sleep_Archive::facts( $id ) );
		$this->assertStringNotContainsString( 'Secret night', $html, 'Not even the stored title.' );
		$this->assertStringNotContainsString( 'Protected', $html );
		$this->assertStringContainsString( 'Waning crescent', $html );
		$this->assertStringContainsString( 'aria-labelledby="pkiw-sleep-d-' . $id . ' pkiw-sleep-p-' . $id . '"', $html );
		$this->assertStringContainsString( 'datetime="2026-08-08"', $html );
		foreach ( [ 'p-sleep', '__bar', '7 hr', '23:40', '06:30', '430' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $html, $needle );
		}
	}

	public function test_the_section_body_adds_a_class_to_the_engines_item_and_prints_no_item_of_its_own(): void {
		$one = '<li class="wp-block-post post-5 h-entry">X</li>';
		$two = '<li data-wp-key="post-template-item-6" class="wp-block-post post-6 h-entry">Y</li>';

		$html = Sleep_Archive::sheet( new Archive_Group( '2026-08', 'August 2026' ), [ 5 => $one, 6 => $two ], [] );

		$this->assertSame(
			'<ol class="pkiw-sleep-sheet"><li class="wp-block-post post-5 h-entry pkiw-sleep-night">X</li><li data-wp-key="post-template-item-6" class="wp-block-post post-6 h-entry pkiw-sleep-night">Y</li></ol>',
			$html
		);
		$this->assertSame( 2, substr_count( $html, '<li' ), 'No list item of its own.' );
	}

	public function test_the_sheet_keeps_the_items_in_the_order_it_receives(): void {
		$items = [ 9 => '<li>B</li>', 3 => '<li>A</li>', 7 => '<li>C</li>' ];

		$html = Sleep_Archive::sheet( new Archive_Group( 'k', 'K' ), $items, [] );
		preg_match_all( '#<li class="pkiw-sleep-night">(.)</li>#', $html, $found );

		$this->assertSame( [ 'B', 'A', 'C' ], $found[1] );
	}

	public function test_a_non_sleep_post_prints_an_empty_cell_not_a_fatal(): void {
		$id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Plain', 'post_date' => '2026-08-20 12:00:00' ] );
		wp_set_object_terms( $id, 'note', 'kind' );

		$this->assertIsString( $this->cell( (int) $id ) );
		$this->assertStringNotContainsString( 'p-sleep', $this->cell( (int) $id ) );
	}

	public function test_a_hidden_location_title_never_heads_a_cell_even_on_an_admin_screen(): void {
		$id = $this->sleep( '2026-08-23', [ 'durationMinutes' => 420 ], [ 'post_title' => 'Maplewood Home' ] );
		update_post_meta( $id, '_pkiw_title_source', 'location' );
		update_post_meta( $id, '_pkiw_geo_privacy', 'private' );
		$visitor = $this->cell( $id );
		$this->assertStringContainsString( 'Check-in, August 23, 2026', $visitor, 'Precondition: the front end prints the safe title.' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		set_current_screen( 'edit-post' );
		$this->assertTrue( is_admin() );
		$admin = $this->cell( $id );

		$this->assertSame( $visitor, $admin );
		$this->assertStringNotContainsString( 'Maplewood', $admin );
	}
}
