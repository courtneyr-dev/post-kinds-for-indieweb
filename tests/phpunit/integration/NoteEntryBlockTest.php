<?php
/**
 * The note entry block (issue 265).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Meta_Fields;
use PKIW\Note_Archive;
use PKIW\Taxonomy;
use PKIW\Title_Privacy;

/**
 * The note entry block prints one note on the week board: a name, a bounded
 * thought, a date. It never prints the kind label, the time of day, the
 * author, a location, or anything a password or a hidden title covers.
 *
 * @group integration
 */
final class NoteEntryBlockTest extends WP_UnitTestCase {

	private const SECRET = 'SECRET-265';

	private const PASSWORD = 'fixture-265';

	/**
	 * Stylesheet active before each test.
	 *
	 * @var string
	 */
	private string $original_stylesheet = '';

	/**
	 * Timezone active before each test.
	 *
	 * @var string
	 */
	private string $original_timezone = '';

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		$this->original_timezone   = (string) get_option( 'timezone_string' );
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'date_format', 'F j, Y' );
		switch_theme( 'twentytwentyfive' );
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
		update_option( 'timezone_string', $this->original_timezone );
		switch_theme( $this->original_stylesheet );
		$this->set_permalink_structure( '' );
		wp_set_current_user( 0 );
		wp_reset_postdata();
		parent::tear_down();
	}

	public function test_registration_puts_the_style_before_the_block_and_the_board_style_on_the_post_template(): void {
		$block = WP_Block_Type_Registry::get_instance()->get_registered( Note_Archive::BLOCK );

		$this->assertSame( 'post-kinds-indieweb/note-entry', Note_Archive::BLOCK );
		$this->assertNotNull( $block );
		$this->assertSame( 3, (int) $block->api_version );
		$this->assertSame( 3, $block->attributes['headingLevel']['default'] );
		$this->assertContains( 'core/post-template', $block->ancestor );
		$this->assertContains( 'pkiw-note-entry-editor', $block->editor_script_handles );
		$this->assertContains( 'pkiw-note-entry', $block->style_handles );
		$this->assertTrue( wp_style_is( 'pkiw-note-entry', 'registered' ) );
		$this->assertTrue( wp_script_is( 'pkiw-note-entry-editor', 'registered' ) );
		$this->assertFileExists( PKIW_PATH . 'styles/note-entry.css' );
		$this->assertFileExists( PKIW_PATH . 'assets/js/note-entry-editor.js' );
		$this->assertSame( PKIW_PATH . 'styles/note-entry.css', wp_styles()->registered['pkiw-note-entry']->extra['path'] ?? '' );

		$styles = WP_Block_Styles_Registry::get_instance()->get_registered_styles_for_block( 'core/post-template' );
		$this->assertArrayHasKey( Note_Archive::STYLE, $styles );
		$this->assertSame( 'pkiw-note-board', Note_Archive::STYLE );
	}

	public function test_the_item_has_one_visible_link_and_the_hidden_author_link_is_not_counted(): void {
		$id = $this->note( 'Garden gate', '2026-08-26 09:15:00', 'The gate latch finally holds.' );

		$item = $this->item( $this->xpath( $this->render( $id ) ) );

		$this->assertSame( 1, $this->visible( './/a[@href]', $item ) );
		$this->assertSame( 0, $this->visible( './/a[@tabindex="-1"]', $item ) );
		$this->assertSame( get_permalink( $id ), $item->getElementsByTagName( 'a' )->item( 0 )->getAttribute( 'href' ) );
		$hidden = ( new DOMXPath( $item->ownerDocument ) )->query( './/span[contains(concat(" ",@class," ")," pk-entry-props ")][@hidden]', $item );
		$this->assertSame( 1, $hidden->length );
	}

	public function test_a_real_title_gets_p_name_and_the_thought_gets_p_summary_once(): void {
		$id = $this->note( 'Garden gate', '2026-08-26 09:15:00', 'Latch repair: the gate finally holds.' );

		$html  = $this->render( $id );
		$xpath = $this->xpath( $html );

		$this->assertSame( 1, $xpath->query( '//h3[contains(concat(" ",@class," ")," pkiw-note-entry__title ")][contains(concat(" ",@class," ")," p-name ")]/a[contains(concat(" ",@class," ")," u-url ")]/span[contains(concat(" ",@class," ")," pkiw-note-entry__name ")]' )->length );
		$this->assertSame( 'Garden gate', trim( $xpath->query( '//h3//span[contains(@class,"pkiw-note-entry__name")]' )->item( 0 )->textContent ) );
		$this->assertSame( 1, $xpath->query( '//p[contains(concat(" ",@class," ")," pkiw-note-entry__thought ")][contains(concat(" ",@class," ")," p-summary ")]' )->length );
		$this->assertSame( 1, substr_count( $html, 'Latch repair: the gate finally holds.' ) );
		$this->assertSame( 0, $xpath->query( '//a//*[contains(@class,"p-summary")]' )->length );
		$this->assertStringContainsString( 'pkiw-note-entry--titled', $html );
	}

	public function test_an_untitled_note_is_named_by_its_thought_with_no_p_name(): void {
		$id = $this->note( '', '2026-08-26 09:15:00', 'Saw the first goldfinch of the year at the feeder.' );

		$html  = $this->render( $id );
		$xpath = $this->xpath( $html );

		$this->assertSame( 0, $xpath->query( '//*[contains(concat(" ",@class," ")," p-name ")]' )->length );
		$this->assertSame( 1, $xpath->query( '//h3/a[contains(concat(" ",@class," ")," u-url ")]/span[contains(concat(" ",@class," ")," pkiw-note-entry__thought ")][contains(concat(" ",@class," ")," p-summary ")]' )->length );
		$this->assertSame( 1, substr_count( $html, 'Saw the first goldfinch of the year at the feeder.' ) );
		$this->assertSame( 0, $xpath->query( '//p[contains(@class,"pkiw-note-entry__thought")]' )->length );
		$this->assertStringContainsString( 'pkiw-note-entry--thought', $html );
	}

	public function test_the_thought_stops_at_twenty_five_words(): void {
		$words = [];
		for ( $i = 1; $i <= 40; $i++ ) {
			$words[] = 'word' . $i;
		}
		$id = $this->note( '', '2026-08-26 09:15:00', implode( ' ', $words ) );

		$thought = Note_Archive::parts( get_post( $id ) )['thought'];

		$this->assertSame( implode( ' ', array_slice( $words, 0, 25 ) ) . '…', $thought );
		$this->assertStringNotContainsString( 'word26', $this->render( $id ) );
	}

	public function test_an_empty_body_and_an_image_only_body_fall_back_to_note_and_the_date(): void {
		$empty = $this->note( '', '2026-06-29 08:00:00', '<!-- wp:paragraph --><p></p><!-- /wp:paragraph -->' );
		$image = $this->note( '', '2026-06-30 08:00:00', '<!-- wp:image --><figure class="wp-block-image"><img src="https://example.test/a.jpg" alt=""/></figure><!-- /wp:image -->' );

		foreach ( [
			$empty => 'Note, June 29, 2026',
			$image => 'Note, June 30, 2026',
		] as $id => $expected ) {
			$html  = $this->render( $id );
			$xpath = $this->xpath( $html );

			$this->assertSame( $expected, trim( $xpath->query( '//h3//span[contains(@class,"pkiw-note-entry__name")]' )->item( 0 )->textContent ) );
			$this->assertSame( 0, $xpath->query( '//*[contains(concat(" ",@class," ")," p-name ")]' )->length );
			$this->assertStringContainsString( 'pkiw-note-entry--fallback', $html );
			$this->assertSame( 0, $xpath->query( '//img' )->length );
		}
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function protected_cases(): array {
		return [
			'protected, titled'   => [ self::SECRET . ' titled' ],
			'protected, untitled' => [ '' ],
		];
	}

	/**
	 * @dataProvider protected_cases
	 */
	public function test_a_protected_note_prints_no_body_with_or_without_the_cookie( string $title ): void {
		$id = $this->note( $title, '2026-08-26 09:15:00', self::SECRET . ' lives only in the body.', [ 'post_password' => self::PASSWORD, 'post_excerpt' => self::SECRET . ' excerpt' ] );
		$this->assertSame( self::PASSWORD, get_post( $id )->post_password, 'The raw column holds the password.' );

		// Without a cookie.
		$this->assertTrue( post_password_required( $id ) );
		$this->assert_no_secret( $this->render( $id ), $title );

		// With a cookie WordPress accepts.
		$_COOKIE[ 'wp-postpass_' . COOKIEHASH ] = $this->valid_cookie();
		$this->assertFalse( post_password_required( $id ), 'The cookie really unlocks the post.' );
		$html = $this->render( $id );
		$this->assert_no_secret( $html, $title );
		$this->assertStringContainsString( 'pkiw-note-entry--protected', $html );
		$this->assertSame( '', Note_Archive::parts( get_post( $id ) )['thought'] );
		$this->assertTrue( Note_Archive::parts( get_post( $id ) )['protected'] );
		if ( '' === $title ) {
			$this->assertStringContainsString( 'Note, August 26, 2026', $html );
		} else {
			$this->assertStringContainsString( 'Protected: ' . self::SECRET . ' titled', $html );
		}
	}

	private function assert_no_secret( string $html, string $title ): void {
		$visible = '' === $title ? $html : str_replace( $title, '', $html );
		$this->assertStringNotContainsString( 'lives only in the body', $html );
		$this->assertStringNotContainsString( self::SECRET . ' excerpt', $html );
		$this->assertStringNotContainsString( 'There is no excerpt because this is a protected post.', $html );
		$this->assertStringNotContainsString( self::SECRET, $visible );
	}

	public function test_a_hidden_location_title_prints_the_safe_title_with_no_p_name(): void {
		$id = $this->hidden_title_note( 'Checked in at Sentinel Venue Zyx9' );

		$this->assertSame( 'Check-in, August 26, 2026', get_the_title( $id ), 'Title_Privacy hides the stored title.' );
		$html  = $this->render( $id );
		$xpath = $this->xpath( $html );

		$this->assertStringNotContainsString( 'Sentinel Venue', $html );
		$this->assertStringNotContainsString( 'Zyx9', $html );
		$this->assertSame( 'Check-in, August 26, 2026', trim( $xpath->query( '//h3//span[contains(@class,"pkiw-note-entry__name")]' )->item( 0 )->textContent ) );
		$this->assertSame( 0, $xpath->query( '//*[contains(concat(" ",@class," ")," p-name ")]' )->length );
		$parts = Note_Archive::parts( get_post( $id ) );
		$this->assertFalse( $parts['name_is_title'] );
		$this->assertSame( 'Check-in, August 26, 2026', $parts['name'] );
	}

	public function test_a_protected_note_with_a_hidden_location_title_keeps_core_prefix_and_prints_no_thought(): void {
		$id = $this->hidden_title_note( 'Checked in at Sentinel Venue Zyx9', [ 'post_password' => self::PASSWORD ] );

		$html = $this->render( $id );

		$this->assertStringContainsString( 'Protected: Check-in, August 26, 2026', $html );
		$this->assertStringNotContainsString( 'Sentinel Venue', $html );
		$this->assertStringNotContainsString( 'Body of the hidden-title note.', $html );
		$this->assertSame( '', Note_Archive::parts( get_post( $id ) )['thought'] );
		$this->assertSame( 0, $this->xpath( $html )->query( '//*[contains(concat(" ",@class," ")," p-name ")]' )->length );
	}

	public function test_a_post_with_a_second_kind_prints_no_thought_and_is_named_by_the_fallback(): void {
		$id = $this->note( '', '2026-08-26 09:15:00', 'Ran 5 km, heart rate 150' );
		wp_set_object_terms( $id, [ 'exercise', 'note' ], Taxonomy::TAXONOMY );

		$parts = Note_Archive::parts( get_post( $id ) );
		$html  = $this->render( $id );

		$this->assertSame( '', $parts['thought'] );
		$this->assertSame( \PKIW\untitled_fallback_name( get_post( $id ) )['name'], $parts['name'] );
		$this->assertSame( 'fallback', $parts['mode'] );
		$this->assertStringNotContainsString( 'heart rate', $html );
		$this->assertStringNotContainsString( 'Ran 5 km', $html );
	}

	public function test_a_titled_post_with_a_second_kind_prints_no_thought_either(): void {
		$id = $this->note( 'Morning loop', '2026-08-26 09:15:00', 'Ran 5 km, heart rate 150' );
		wp_set_object_terms( $id, [ 'sleep', 'note' ], Taxonomy::TAXONOMY );

		$html = $this->render( $id );

		$this->assertStringContainsString( 'Morning loop', $html );
		$this->assertStringNotContainsString( 'heart rate', $html );
	}

	public function test_the_title_goes_through_the_the_title_filters(): void {
		$id     = $this->note( 'Garden gate', '2026-08-26 09:15:00', 'Body words.' );
		$filter = static fn( string $title ): string => $title . ' [filtered]';
		add_filter( 'the_title', $filter );

		$html = $this->render( $id );

		remove_filter( 'the_title', $filter );
		$this->assertStringContainsString( 'Garden gate [filtered]', $html );
	}

	public function test_a_the_content_filter_never_reaches_the_thought(): void {
		$id      = $this->note( '', '2026-08-26 09:15:00', 'Plain note body.' );
		$titled  = $this->note( 'Titled', '2026-08-25 09:15:00', 'Another plain body.' );
		$content = static fn( string $content ): string => $content . ' LOCATIONMARK';
		$excerpt = static fn( string $excerpt ): string => $excerpt . ' EXCERPTMARK';
		add_filter( 'the_content', $content, 12 );
		add_filter( 'get_the_excerpt', $excerpt );
		add_filter( 'the_excerpt', $excerpt );
		add_filter( 'wp_trim_excerpt', $excerpt );

		$html = $this->render( $id ) . $this->render( $titled );
		$both = Note_Archive::parts( get_post( $id ) )['thought'] . Note_Archive::parts( get_post( $titled ) )['thought'];

		remove_filter( 'the_content', $content, 12 );
		remove_filter( 'get_the_excerpt', $excerpt );
		remove_filter( 'the_excerpt', $excerpt );
		remove_filter( 'wp_trim_excerpt', $excerpt );
		$this->assertStringNotContainsString( 'LOCATIONMARK', $html . $both );
		$this->assertStringNotContainsString( 'EXCERPTMARK', $html . $both );
		$this->assertStringContainsString( 'Plain note body.', $html );
	}

	public function test_the_time_has_an_offset_and_the_visible_text_has_no_time_of_day(): void {
		$id = $this->note( 'Garden gate', '2026-08-26 14:45:00', 'Body.' );

		$html = $this->render( $id );
		$time = $this->xpath( $html )->query( '//time[contains(concat(" ",@class," ")," dt-published ")]' )->item( 0 );

		$this->assertInstanceOf( DOMElement::class, $time );
		$this->assertMatchesRegularExpression( '/^2026-08-26T14:45:00-04:00$/', $time->getAttribute( 'datetime' ) );
		$this->assertSame( 'August 26, 2026', trim( $time->textContent ) );
		$this->assertDoesNotMatchRegularExpression( '/\d{1,2}:\d{2}\s?(am|pm)/i', wp_strip_all_tags( $html ) );
	}

	public function test_the_item_prints_none_of_the_things_the_board_leaves_off(): void {
		$author = self::factory()->user->create( [ 'display_name' => 'Authorzyx Sentinel' ] );
		$id     = $this->note( 'Garden gate', '2026-08-26 09:15:00', 'Body words go here.', [ 'post_author' => $author ] );
		update_post_meta( $id, 'geo_address', '12 Example Lane, Sampletown' );
		update_post_meta( $id, 'geo_latitude', '0.5' );
		update_post_meta( $id, 'geo_longitude', '-30.5' );
		wp_set_post_tags( $id, [ 'tagmark' ] );

		$html  = $this->render( $id );
		$xpath = $this->xpath( $html );

		foreach ( [ 'Read note', 'Read more', '12 Example Lane', '0.5', 'tagmark', 'h-geo', 'p-location', 'e-content', 'comment' ] as $absent ) {
			$this->assertStringNotContainsString( $absent, $html, $absent );
		}
		$this->assertSame( 0, $xpath->query( '//img | //video | //iframe | //audio' )->length );
		$this->assertSame( 0, $xpath->query( '//*[not(ancestor-or-self::*[@hidden])][contains(text(),"Authorzyx")]' )->length, 'The author is not visible.' );
		$this->assertSame( 1, $xpath->query( '//span[@hidden]//*[contains(concat(" ",@class," ")," p-author ")]' )->length, 'The author h-card is in the hidden span.' );
		$this->assertSame( 0, $xpath->query( '//*[not(ancestor-or-self::*[@hidden])][contains(text(),"Note")]' )->length, 'The kind label is not visible.' );
	}

	public function test_dup_title_is_set_when_the_thought_repeats_the_title(): void {
		$dup   = $this->note( 'Garden gate', '2026-08-26 09:15:00', 'garden   gate: the latch holds now.' );
		$other = $this->note( 'Garden gate', '2026-08-25 09:15:00', 'Different words entirely.' );
		$curly = $this->note( 'Don\'t forget', '2026-08-24 09:15:00', 'Don\'t forget the seed order.' );

		$this->assertTrue( Note_Archive::parts( get_post( $dup ) )['dup_title'] );
		$this->assertFalse( Note_Archive::parts( get_post( $other ) )['dup_title'] );
		$this->assertTrue( Note_Archive::parts( get_post( $curly ) )['dup_title'], 'Typographic quotes compare equal to plain ones.' );
		$this->assertStringContainsString( 'pkiw-note-entry--dup-title', $this->render( $dup ) );
		$this->assertStringNotContainsString( 'pkiw-note-entry--dup-title', $this->render( $other ) );
	}

	public function test_parts_reports_the_name_mode_flags_and_dates(): void {
		$titled   = $this->note( 'Garden gate', '2026-08-26 09:15:00', 'Body.' );
		$thought  = $this->note( '', '2026-08-25 09:15:00', 'Just a thought.' );
		$fallback = $this->note( '', '2026-08-24 09:15:00', '<!-- wp:paragraph --><p></p><!-- /wp:paragraph -->' );

		$a = Note_Archive::parts( get_post( $titled ) );
		$b = Note_Archive::parts( get_post( $thought ) );
		$c = Note_Archive::parts( get_post( $fallback ) );

		$this->assertSame( [ 'Garden gate', true, 'titled', false ], [ $a['name'], $a['name_is_title'], $a['mode'], $a['protected'] ] );
		$this->assertSame( [ 'Just a thought.', false, 'thought', 'Just a thought.' ], [ $b['name'], $b['name_is_title'], $b['mode'], $b['thought'] ] );
		$this->assertSame( [ 'Note, August 24, 2026', false, 'fallback', '' ], [ $c['name'], $c['name_is_title'], $c['mode'], $c['thought'] ] );
		$this->assertSame( 'August 26, 2026', $a['date'] );
		$this->assertSame( '2026-08-26T09:15:00-04:00', $a['datetime'] );
	}

	public function test_heading_level_follows_the_attribute_and_clamps(): void {
		$id = $this->note( 'Garden gate', '2026-08-26 09:15:00', 'Body.' );

		$this->assertSame( 1, $this->xpath( $this->render( $id, [ 'headingLevel' => 4 ] ) )->query( '//h4[contains(@class,"pkiw-note-entry__title")]' )->length );
		$this->assertSame( 1, $this->xpath( $this->render( $id, [ 'headingLevel' => 9 ] ) )->query( '//h6[contains(@class,"pkiw-note-entry__title")]' )->length );
		$this->assertSame( 1, $this->xpath( $this->render( $id, [ 'headingLevel' => 1 ] ) )->query( '//h2[contains(@class,"pkiw-note-entry__title")]' )->length );
	}

	public function test_the_item_adds_no_root_class_and_never_marks_a_stream_card_root(): void {
		$id = $this->note( 'Garden gate', '2026-08-26 09:15:00', 'Body.' );
		unset( $GLOBALS['pkiw_stream_card_root_seen'] );

		$html = $this->render( $id );

		$this->assertStringNotContainsString( 'h-entry', $html );
		$this->assertStringNotContainsString( 'h-cite', $html );
		$this->assertEmpty( $GLOBALS['pkiw_stream_card_root_seen'][ $id ] ?? null );
	}

	public function test_duplicate_names_on_the_archive_page_get_aria_labelledby_with_the_date(): void {
		$first  = $this->note( 'Garden gate', '2026-08-26 09:15:00', 'First body.' );
		$second = $this->note( 'Garden gate', '2026-08-25 09:15:00', 'Second body.' );
		$unique = $this->note( 'Seed order', '2026-08-24 09:15:00', 'Third body.' );
		$t1     = $this->note( '', '2026-08-23 09:15:00', 'Same thought twice.' );
		$t2     = $this->note( '', '2026-08-22 09:15:00', 'Same thought twice.' );

		$xpath = $this->xpath( $this->serve( $this->archive_url() ) );

		foreach ( [ $first, $second, $t1, $t2 ] as $id ) {
			$link  = $this->link( $xpath, $id );
			$names = explode( ' ', $link->getAttribute( 'aria-labelledby' ) );
			$this->assertCount( 2, $names, "post {$id}" );
			$this->assertSame( 'pkiw-note-n-' . $id, $names[0] );
			$this->assertSame( 'pkiw-note-d-' . $id, $names[1] );
			foreach ( $names as $target ) {
				$this->assertSame( 1, $xpath->query( '//*[@id="' . $target . '"]' )->length, $target );
			}
		}
		$this->assertSame( '', $this->link( $xpath, $unique )->getAttribute( 'aria-labelledby' ) );
	}

	public function test_a_name_alone_on_the_page_has_no_aria_labelledby_when_the_item_renders_by_itself(): void {
		$id = $this->note( 'Garden gate', '2026-08-26 09:15:00', 'Body.' );

		$this->assertStringNotContainsString( 'aria-labelledby', $this->render( $id ) );
	}

	public function test_the_editor_preview_renders_one_note_from_the_block_renderer_route(): void {
		$one = $this->note( 'Garden gate', '2026-08-26 09:15:00', 'First body.' );
		$two = $this->note( 'Garden gate', '2026-08-25 09:15:00', 'Second body.' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/' . Note_Archive::BLOCK );
		$request->set_param( 'context', 'edit' );
		$request->set_param( 'post_id', $one );
		$request->set_param( 'pkiw_kind', 'note' );
		$request->set_param( 'attributes', [ 'headingLevel' => 3 ] );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$html = (string) $response->get_data()['rendered'];
		$this->assertStringContainsString( 'Garden gate', $html );
		$this->assertStringContainsString( 'First body.', $html );
		$this->assertStringNotContainsString( 'Second body.', $html );
		$this->assertStringNotContainsString( 'aria-labelledby', $html, 'The preview renders one note and counts no page.' );
		$this->assertNotSame( $one, $two );
	}

	public function test_the_item_renders_nothing_for_a_missing_post(): void {
		$block = new WP_Block( [ 'blockName' => Note_Archive::BLOCK ], [ 'postId' => 999999, 'postType' => 'post' ] );

		$this->assertSame( '', $block->render() );
	}

	/**
	 * A published note-kind post.
	 *
	 * @param string               $title Title.
	 * @param string               $date  Local post date.
	 * @param string               $body  Body.
	 * @param array<string, mixed> $extra Extra post args.
	 */
	private function note( string $title, string $date, string $body, array $extra = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_content' => $body,
					'post_excerpt' => '',
					'post_date'    => $date,
				],
				$extra
			)
		);
		wp_set_object_terms( $id, 'note', Taxonomy::TAXONOMY );

		return (int) $id;
	}

	/**
	 * A note whose stored title came from a location that isn't public.
	 *
	 * @param string               $title Stored title.
	 * @param array<string, mixed> $extra Extra post args.
	 */
	private function hidden_title_note( string $title, array $extra = [] ): int {
		$id = $this->note( $title, '2026-08-26 09:15:00', 'Body of the hidden-title note.', $extra );
		update_post_meta( $id, Meta_Fields::PREFIX . 'checkin_name', 'Sentinel Venue Zyx9' );
		update_post_meta( $id, Meta_Fields::PREFIX . 'geo_privacy', 'private' );
		Title_Privacy::mark_location_title( $id );

		return $id;
	}

	private function valid_cookie(): string {
		require_once ABSPATH . WPINC . '/class-phpass.php';

		return ( new PasswordHash( 8, true ) )->HashPassword( self::PASSWORD );
	}

	/**
	 * Render the block for one post the way a Post Template item does.
	 *
	 * @param int                  $post_id    Post ID.
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function render( int $post_id, array $attributes = [] ): string {
		$post = get_post( $post_id );
		$this->assertInstanceOf( WP_Post::class, $post );
		setup_postdata( $post );
		$block = new WP_Block(
			[
				'blockName' => Note_Archive::BLOCK,
				'attrs'     => $attributes,
			],
			[
				'postId'   => $post_id,
				'postType' => 'post',
			]
		);
		$html  = $block->render();
		wp_reset_postdata();

		return $html;
	}

	private function archive_url(): string {
		$url = get_term_link( 'note', Taxonomy::TAXONOMY );
		$this->assertSame( 'http://example.org/kind/note/', $url );

		return $url;
	}

	private function serve( string $url ): string {
		$this->go_to( $url );
		$template = resolve_block_template( 'taxonomy', [ 'taxonomy-kind-note.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ], '' );
		$this->assertNotNull( $template );
		$this->assertSame( 'post-kinds-for-indieweb//taxonomy-kind-note', $template->id );

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;

		return get_the_block_template_html();
	}

	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();

		return new DOMXPath( $dom );
	}

	private function item( DOMXPath $xpath ): DOMElement {
		$item = $xpath->query( '//*[contains(concat(" ",@class," ")," pkiw-note-entry ")]' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $item );

		return $item;
	}

	private function link( DOMXPath $xpath, int $post_id ): DOMElement {
		$link = $xpath->query( '//a[contains(concat(" ",@class," ")," u-url ")][@href="' . get_permalink( $post_id ) . '"]' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $link, "link for post {$post_id}" );

		return $link;
	}

	/**
	 * Count a node set with no `[hidden]` on the node or an ancestor.
	 */
	private function visible( string $expression, DOMElement $context ): int {
		$xpath = new DOMXPath( $context->ownerDocument );

		return $xpath->query( $expression . '[not(ancestor-or-self::*[@hidden])]', $context )->length;
	}
}
