<?php
/**
 * The exercise medal row (issue 245).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Exercise_Medals;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * post-kinds-indieweb/medal-row lists the newest personal bests of published,
 * unprotected exercise posts that share their details. A post qualifies only
 * when its post row AND its card's own toggle both say so, and the row prints
 * nothing past page one or when nothing qualifies.
 *
 * @group integration
 */
final class ExerciseMedalRowTest extends WP_UnitTestCase {

	private const PUBLIC_KEY = '_pkiw_exercise_details_public';

	private string $original_stylesheet = '';

	private string $original_timezone = '';

	public function set_up(): void {
		parent::set_up();
		( new Meta_Fields() )->register_meta_fields();
		$this->original_stylesheet = get_stylesheet();
		$this->original_timezone   = (string) get_option( 'timezone_string' );
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'date_format', 'F j, Y' );
		switch_theme( 'twentytwentyfive' );
		( new Taxonomy() )->create_default_terms();
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		update_option( 'timezone_string', $this->original_timezone );
		switch_theme( $this->original_stylesheet );
		$this->set_permalink_structure( '' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * A published exercise post with a personal best.
	 *
	 * @param string               $title Title.
	 * @param string               $date  Local date.
	 * @param array<string, mixed> $attrs Card attributes, over a 5K at 29:12 that shares its details.
	 * @param array<string, mixed> $args  Post args.
	 */
	private function pb( string $title, string $date, array $attrs = [], array $args = [] ): int {
		$attrs = array_merge(
			[
				'activity'            => 'Run',
				'distance'            => 5.0,
				'duration'            => 'PT29M12S',
				'personalBest'        => true,
				'pbCategory'          => '5k',
				'showDetailsPublicly' => true,
			],
			$attrs
		);
		$id    = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_date'    => $date,
					'post_content' => '<!-- wp:post-kinds-indieweb/exercise-card ' . serialize_block_attributes( $attrs ) . ' /-->',
				],
				$args
			)
		);
		wp_set_object_terms( $id, 'exercise', Taxonomy::TAXONOMY );

		return (int) $id;
	}

	/**
	 * The row as the page prints it: the block, rendered with its block context.
	 *
	 * @param array<string, mixed> $attrs Block attributes.
	 */
	private function row( array $attrs = [] ): string {
		return do_blocks( '<!-- wp:post-kinds-indieweb/medal-row ' . ( [] === $attrs ? '' : (string) wp_json_encode( $attrs ) . ' ' ) . '/-->' );
	}

	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();

		return new DOMXPath( $dom );
	}

	/**
	 * Visible medal texts, in order.
	 *
	 * @param string $html Rendered row.
	 * @return string[]
	 */
	private function medals( string $html ): array {
		$xpath = $this->xpath( $html );
		$out   = [];
		foreach ( $xpath->query( '//li[contains(@class,"pkiw-medal")]' ) as $item ) {
			$out[] = trim( (string) preg_replace( '/\s+/', ' ', $item->textContent ) );
		}

		return $out;
	}

	public function test_page_one_lists_the_newest_bests_first(): void {
		$this->pb( 'Lakeside Loop', '2026-08-23 07:10:00' );
		$this->pb( 'Tempo Run', '2026-08-22 08:00:00', [ 'distance' => 10.0, 'duration' => 'PT52M03S', 'pbCategory' => '10k' ] );

		$html = $this->row();

		$this->assertSame( [ '5K 29:12, Lakeside Loop', '10K 52:03, Tempo Run' ], $this->medals( $html ) );
		$this->assertSame( 'Personal bests', trim( $this->xpath( $html )->query( '//h2' )->item( 0 )->textContent ) );
	}

	public function test_private_protected_unshared_and_non_published_bests_are_absent(): void {
		$this->pb( 'Shared best', '2026-08-23 07:10:00' );
		$this->pb( 'Track Night', '2026-08-21 19:00:00', [ 'showDetailsPublicly' => false ] );
		$this->pb( 'Members Only Ride', '2026-08-20 18:00:00', [ 'pbCategory' => '10k' ], [ 'post_password' => 'secret' ] );
		$this->pb( 'Quiet Best', '2026-08-18 09:00:00', [], [ 'post_status' => 'private' ] );
		$this->pb( 'Draft Best', '2026-08-17 07:00:00', [], [ 'post_status' => 'draft' ] );
		$this->pb( 'Spaces Password', '2026-08-16 07:00:00', [], [ 'post_password' => '   ' ] );

		$medals = $this->medals( $this->row( [ 'count' => 6 ] ) );

		$this->assertSame( [ '5K 29:12, Shared best' ], $medals );
	}

	public function test_an_administrator_still_gets_no_private_draft_scheduled_or_protected_best(): void {
		$this->pb( 'Shared best', '2026-08-23 07:10:00' );
		$this->pb( 'Quiet Best', '2026-08-18 09:00:00', [], [ 'post_status' => 'private' ] );
		$this->pb( 'Draft Best', '2026-08-17 07:00:00', [], [ 'post_status' => 'draft' ] );
		$this->pb( 'Members Only Ride', '2026-08-20 18:00:00', [ 'pbCategory' => '10k' ], [ 'post_password' => 'secret' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->assertSame( [ '5K 29:12, Shared best' ], $this->medals( $this->row( [ 'count' => 6 ] ) ), 'The row is published, unprotected posts only, whoever reads it.' );
	}

	public function test_a_stale_post_row_does_not_open_a_card_whose_toggle_is_off(): void {
		$stale = $this->pb( 'Stale row', '2026-08-23 07:10:00', [ 'showDetailsPublicly' => false ] );
		update_post_meta( $stale, self::PUBLIC_KEY, '1' );
		$this->assertSame( '1', get_post_meta( $stale, '_pkiw_exercise_pb', true ), 'The personal best row is there too: only the card toggle keeps it out.' );
		$this->assertTrue( Meta_Fields::health_details_visible( $stale ), 'The row alone says visible.' );

		$this->assertSame( '', $this->row(), 'The card says hidden, so there is no medal and no heading.' );
	}

	public function test_a_card_toggle_that_is_on_does_not_open_a_post_whose_row_is_gone(): void {
		$gone = $this->pb( 'Row gone', '2026-08-23 07:10:00' );
		$this->assertNotSame( '', $this->row(), 'Both agree: a medal.' );

		delete_post_meta( $gone, self::PUBLIC_KEY );
		$this->assertSame( '', $this->row() );
	}

	public function test_an_administrator_sees_the_same_row_as_a_visitor(): void {
		$this->pb( 'Visible best', '2026-08-23 07:10:00' );
		$this->pb( 'Hidden best', '2026-08-22 07:10:00', [ 'showDetailsPublicly' => false ] );
		$visitor = $this->row( [ 'count' => 6 ] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( $this->medals( $visitor ), $this->medals( $this->row( [ 'count' => 6 ] ) ) );
		$this->assertSame( [ '5K 29:12, Visible best' ], $this->medals( $visitor ) );
	}

	public function test_a_best_with_no_category_is_not_a_medal(): void {
		$this->pb( 'No category', '2026-08-23 07:10:00', [ 'pbCategory' => '' ] );

		$this->assertSame( '', $this->row() );
	}

	public function test_posts_of_other_kinds_are_never_medals(): void {
		$id = $this->pb( 'Reclassified', '2026-08-23 07:10:00' );
		wp_set_object_terms( $id, 'note', Taxonomy::TAXONOMY );
		$this->assertSame( '1', get_post_meta( $id, '_pkiw_exercise_pb', true ), 'The rows are still on the post.' );

		$this->assertSame( '', $this->row() );
	}

	public function test_past_the_first_page_the_row_prints_nothing(): void {
		$this->pb( 'Lakeside Loop', '2026-08-23 07:10:00' );
		$this->go_to( get_term_link( 'exercise', Taxonomy::TAXONOMY ) );
		$this->assertNotSame( '', $this->row(), 'Page one prints the row.' );

		// A page past the first, as core flags it (a 404 page two would reset the flag).
		$GLOBALS['wp_query']->is_paged = true;
		$this->assertTrue( is_paged() );
		$this->assertSame( '', $this->row() );
		$this->assertSame( '', do_blocks( '<!-- wp:post-kinds-indieweb/medal-row /-->' ) );
	}

	public function test_no_qualifying_post_prints_nothing_not_even_the_heading(): void {
		$this->assertSame( '', $this->row() );
		$this->pb( 'Hidden', '2026-08-23 07:10:00', [ 'showDetailsPublicly' => false ] );
		$this->assertSame( '', $this->row() );
		$this->assertSame( '', do_blocks( '<!-- wp:post-kinds-indieweb/medal-row /-->' ) );
	}

	public function test_a_custom_heading_level_and_count_are_used_and_clamped(): void {
		foreach ( range( 1, 8 ) as $day ) {
			$this->pb( 'Best ' . $day, sprintf( '2026-08-%02d 07:10:00', $day ) );
		}

		$default = $this->xpath( $this->row() );
		$this->assertSame( 3, $default->query( '//li[contains(@class,"pkiw-medal")]' )->length, 'Three by default.' );

		$custom = $this->xpath( $this->row( [ 'count' => 2, 'headingLevel' => 3, 'heading' => 'Hall <b>of</b> fame' ] ) );
		$this->assertSame( 2, $custom->query( '//li[contains(@class,"pkiw-medal")]' )->length );
		$this->assertSame( 'Hall of fame', trim( $custom->query( '//h3' )->item( 0 )->textContent ), 'Verbatim, markup stripped.' );

		$this->assertSame( 6, $this->xpath( $this->row( [ 'count' => 99 ] ) )->query( '//li[contains(@class,"pkiw-medal")]' )->length, 'No more than six.' );
		$this->assertSame( 1, $this->xpath( $this->row( [ 'count' => 0 ] ) )->query( '//li[contains(@class,"pkiw-medal")]' )->length, 'At least one.' );
		$this->assertSame( 1, $this->xpath( $this->row( [ 'headingLevel' => 1 ] ) )->query( '//h2' )->length, 'No H1.' );
		$this->assertSame( 1, $this->xpath( $this->row( [ 'headingLevel' => 9 ] ) )->query( '//h5' )->length, 'No deeper than H5.' );
	}

	public function test_the_section_is_labelled_by_its_heading_with_a_unique_id(): void {
		$this->pb( 'Lakeside Loop', '2026-08-23 07:10:00' );
		$first  = $this->xpath( do_blocks( '<!-- wp:post-kinds-indieweb/medal-row /-->' ) );
		$second = $this->xpath( do_blocks( '<!-- wp:post-kinds-indieweb/medal-row /-->' ) );

		$section = $first->query( '//section' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $section );
		$id = $first->query( '//h2' )->item( 0 )->getAttribute( 'id' );
		$this->assertSame( $id, $section->getAttribute( 'aria-labelledby' ) );
		$this->assertNotSame( $id, $second->query( '//h2' )->item( 0 )->getAttribute( 'id' ) );
		$this->assertStringContainsString( 'pkiw-medal-row', $section->getAttribute( 'class' ) );
	}

	public function test_each_medal_has_one_rendered_link_and_no_date_year_image_or_marker(): void {
		$this->pb( 'Lakeside Loop', '2026-08-23 07:10:00' );
		$this->pb( '', '2026-08-22 08:00:00', [ 'pbCategory' => '10k', 'duration' => 'PT52M03S' ] );
		$html  = $this->row();
		$xpath = $this->xpath( $html );

		foreach ( $xpath->query( '//li[contains(@class,"pkiw-medal")]' ) as $item ) {
			$this->assertSame( 1, $xpath->query( './/a[@href][not(ancestor-or-self::*[@hidden])]', $item )->length );
			$this->assertSame( 0, $xpath->query( './/a[@tabindex="-1"][not(ancestor-or-self::*[@hidden])]', $item )->length );
		}
		$this->assertSame( 0, $xpath->query( '//img' )->length );
		$this->assertDoesNotMatchRegularExpression( '/\b\d{4}\b/', wp_strip_all_tags( $html ), 'No four-digit year, so an untitled workout names no date.' );
		$this->assertStringContainsString( '10K 52:03, Exercise', implode( '|', $this->medals( $html ) ) );
		foreach ( [ 'Route', 'Public', 'public', 'Private', 'private', 'Protected' ] as $word ) {
			$this->assertStringNotContainsString( $word, $html, $word );
		}
		$this->assertDoesNotMatchRegularExpression( '/\block\b|\bglobe\b|[\x{1F512}\x{1F513}\x{1F510}\x{1F30D}-\x{1F310}]/iu', $html );
	}

	public function test_a_medal_carries_no_microformats_root_or_property(): void {
		$this->pb( 'Lakeside Loop', '2026-08-23 07:10:00' );
		$html = $this->row();

		$this->assertDoesNotMatchRegularExpression( '/\b(?:h-[a-z]+|p-[a-z-]+|u-[a-z-]+|dt-[a-z-]+|e-[a-z-]+)\b/', $html );
	}

	public function test_a_time_is_an_iso_duration(): void {
		$this->pb( 'Timed', '2026-08-23 07:10:00' );
		$html  = $this->row();
		$xpath = $this->xpath( $html );
		$this->assertSame( 'PT29M12S', $xpath->query( '//time' )->item( 0 )->getAttribute( 'datetime' ) );
	}

	public function test_the_read_continues_past_cards_that_hide_so_an_older_medal_is_found(): void {
		$old = $this->pb( 'The one medal', '2026-01-02 07:10:00' );
		foreach ( range( 1, 30 ) as $n ) {
			$id = $this->pb( 'Stale ' . $n, sprintf( '2026-03-%02d 07:10:00', $n ), [ 'showDetailsPublicly' => false ] );
			update_post_meta( $id, self::PUBLIC_KEY, '1' );
		}

		$this->assertSame( [ '5K 29:12, The one medal' ], $this->medals( $this->row( [ 'count' => 1 ] ) ) );
		$this->assertGreaterThan( 0, $old );
	}

	public function test_the_block_renders_through_do_blocks_with_its_attributes(): void {
		$this->pb( 'Lakeside Loop', '2026-08-23 07:10:00' );
		$html = do_blocks( '<!-- wp:post-kinds-indieweb/medal-row {"heading":"Medals","headingLevel":4} /-->' );

		$this->assertSame( 'Medals', trim( $this->xpath( $html )->query( '//h4' )->item( 0 )->textContent ) );
		$this->assertStringContainsString( 'wp-block-post-kinds-indieweb-medal-row', $html );
	}

	public function test_the_block_is_registered_in_php_with_a_plain_editor_script(): void {
		$block = WP_Block_Type_Registry::get_instance()->get_registered( Exercise_Medals::BLOCK );

		$this->assertNotNull( $block );
		$this->assertSame( 3, $block->api_version );
		$this->assertSame( 3, $block->attributes['count']['default'] );
		$this->assertSame( 2, $block->attributes['headingLevel']['default'] );
		$this->assertSame( '', $block->attributes['heading']['default'] );
		$this->assertContains( 'pkiw-medal-row-editor', $block->editor_script_handles );
		$this->assertContains( 'pkiw-medal-row', $block->style_handles );
		$this->assertFileExists( PKIW_PATH . 'assets/js/medal-row-editor.js' );
		$this->assertFileExists( PKIW_PATH . 'styles/medal-row.css' );
		$this->assertFileDoesNotExist( PKIW_PATH . 'src/blocks/medal-row/block.json', 'No block.json: PBUNDLE has no medal-row work.' );
	}
}
