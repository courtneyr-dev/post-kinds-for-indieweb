<?php
/**
 * The exercise entry block (issue 245).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Exercise_Archive;
use PKIW\Grouped_Archive;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * post-kinds-indieweb/exercise-entry is registered in PHP (no block.json, the
 * Staff Picks shape), fixes the week source and prints each week through its
 * own section body. Its block-renderer preview prints the same week body as
 * the front end.
 *
 * @group integration
 */
final class ExerciseEntryBlockTest extends WP_UnitTestCase {

	private string $original_stylesheet = '';

	private string $original_timezone = '';

	public function set_up(): void {
		parent::set_up();
		( new Meta_Fields() )->register_meta_fields();
		$this->original_stylesheet = get_stylesheet();
		$this->original_timezone   = (string) get_option( 'timezone_string' );
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'start_of_week', 0 );
		delete_option( 'sloc_measurements' );
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
		set_current_screen( 'front' );
		wp_set_current_user( 0 );
		wp_reset_postdata();
		parent::tear_down();
	}

	/**
	 * A published exercise post.
	 *
	 * @param string               $title Title.
	 * @param string               $date  Local date.
	 * @param array<string, mixed> $attrs Card attributes.
	 * @param array<string, mixed> $args  Post args.
	 */
	private function exercise( string $title, string $date, array $attrs = [ 'activity' => 'Run' ], array $args = [] ): int {
		$id = self::factory()->post->create(
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

	private function archive_url(): string {
		return (string) get_term_link( 'exercise', Taxonomy::TAXONOMY );
	}

	/**
	 * Serve the archive, optionally with the template's post-template or entry changed.
	 *
	 * @param string $url     URL.
	 * @param string $find    Template text to replace, or ''.
	 * @param string $replace Replacement.
	 */
	private function serve( string $url, string $find = '', string $replace = '' ): string {
		$swap = null;
		if ( '' !== $find ) {
			// The paging reads the template before the page renders, so swap it where templates load.
			$swap = static function ( $templates ) use ( $find, $replace ) {
				foreach ( $templates as $at => $found ) {
					if ( 'taxonomy-kind-exercise' === $found->slug ) {
						// A copy: the registry hands out the same object on the next call.
						$templates[ $at ]          = clone $found;
						$templates[ $at ]->content = str_replace( $find, $replace, $found->content );
					}
				}
				return $templates;
			};
			add_filter( 'get_block_templates', $swap );
		}

		try {
			$this->go_to( $url );
			$template = resolve_block_template( 'taxonomy', [ 'taxonomy-kind-exercise.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ], '' );
			$this->assertNotNull( $template );
			if ( null !== $swap ) {
				$this->assertStringContainsString( $replace, $template->content, 'The template text to change was found.' );
			}

			global $_wp_current_template_id, $_wp_current_template_content;
			$_wp_current_template_id      = $template->id;
			$_wp_current_template_content = $template->content;

			return get_the_block_template_html();
		} finally {
			if ( null !== $swap ) {
				remove_filter( 'get_block_templates', $swap );
			}
		}
	}

	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();

		return new DOMXPath( $dom );
	}

	private function editor_render( int $post_id ): string {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/' . Exercise_Archive::ENTRY );
		$request->set_param( 'context', 'edit' );
		$request->set_param( 'post_id', $post_id );
		$request->set_param( 'pkiw_kind', 'exercise' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );

		return (string) $response->get_data()['rendered'];
	}

	public function test_the_block_is_registered_in_php_with_the_documented_defaults(): void {
		$block = WP_Block_Type_Registry::get_instance()->get_registered( Exercise_Archive::ENTRY );

		$this->assertNotNull( $block );
		$this->assertSame( 'post-kinds-indieweb/exercise-entry', Exercise_Archive::ENTRY );
		$this->assertTrue( Grouped_Archive::is_entry_block( Exercise_Archive::ENTRY ) );
		$this->assertSame( 3, $block->api_version );
		$this->assertSame( 4, $block->attributes['weeksPerPage']['default'] );
		$this->assertSame( 'integer', $block->attributes['weeksPerPage']['type'] );
		$this->assertSame( 2, $block->attributes['headingLevel']['default'] );
		$this->assertSame( 0, $block->attributes['linesPerPage']['default'] );
		$this->assertContains( 'core/post-template', $block->ancestor );
		$this->assertContains( 'pkiw-exercise-entry-editor', $block->editor_script_handles );
		$this->assertContains( 'pkiw-exercise-entry', $block->style_handles );
		$this->assertTrue( wp_script_is( 'pkiw-exercise-entry-editor', 'registered' ) );
		$this->assertTrue( wp_style_is( 'pkiw-exercise-entry', 'registered' ) );
		$this->assertFileExists( PKIW_PATH . 'assets/js/exercise-entry-editor.js' );
		$this->assertFileExists( PKIW_PATH . 'styles/exercise-entry.css' );
		$this->assertFileDoesNotExist( PKIW_PATH . 'src/blocks/exercise-entry/block.json', 'Registered in PHP; no block.json.' );
	}

	public function test_the_entry_fixes_the_week_source_and_prints_a_line_each(): void {
		$this->exercise( 'First run', '2026-08-23 07:10:00' );
		$this->exercise( 'Second run', '2026-08-24 07:10:00' );
		$xpath = $this->xpath( $this->serve( $this->archive_url() ) );

		$this->assertSame( 1, $xpath->query( '//section[contains(@class,"pkiw-exercise-week")]' )->length, 'Both fall in one week section.' );
		$this->assertSame( 2, $xpath->query( '//ul[contains(@class,"pkiw-exercise-day__bibs")]/li' )->length );
		$this->assertSame( 1, $xpath->query( '//section[contains(@class,"pkiw-exercise-week")]/h2[starts-with(@id,"pkiw-group-")]' )->length );
	}

	public function test_a_class_name_on_the_post_template_reaches_the_wrapper(): void {
		$this->exercise( 'Styled run', '2026-08-23 07:10:00' );
		$html = $this->serve( $this->archive_url(), '<!-- wp:post-template -->', '<!-- wp:post-template {"className":"is-style-cr-bib"} -->' );

		$xpath   = $this->xpath( $html );
		$wrapper = $xpath->query( '//div[contains(concat(" ",normalize-space(@class)," ")," pkiw-exercise-log ")]' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $wrapper );
		$this->assertStringContainsString( 'is-style-cr-bib', $wrapper->getAttribute( 'class' ) );
	}

	public function test_heading_level_moves_the_week_heading_and_the_bib_title(): void {
		$this->exercise( 'Levelled run', '2026-08-23 07:10:00' );
		$default = $this->xpath( $this->serve( $this->archive_url() ) );
		$this->assertSame( 1, $default->query( '//section[contains(@class,"pkiw-exercise-week")]/h2' )->length );
		$this->assertSame( 1, $default->query( '//h3[contains(@class,"pkiw-exercise-bib__title")]' )->length );

		$raised = $this->xpath( $this->serve( $this->archive_url(), '"weeksPerPage":4', '"weeksPerPage":4,"headingLevel":3' ) );
		$this->assertSame( 1, $raised->query( '//section[contains(@class,"pkiw-exercise-week")]/h3' )->length );
		$this->assertSame( 1, $raised->query( '//h4[contains(@class,"pkiw-exercise-bib__title")]' )->length );

		$flat = $this->xpath( $this->serve( $this->archive_url(), '"weeksPerPage":4', '"weeksPerPage":4,"showSections":false' ) );
		$this->assertSame( 0, $flat->query( '//section[contains(@class,"pkiw-exercise-week")]' )->length, 'Sections off prints the bare lines.' );
		$this->assertSame( 1, $flat->query( '//h2[contains(@class,"pkiw-exercise-bib__title")]' )->length );
	}

	public function test_a_line_count_beside_weeks_per_page_never_cuts_a_week(): void {
		foreach ( range( 1, 7 ) as $day ) {
			$this->exercise( 'Week one run ' . $day, sprintf( '2026-08-%02d 07:00:00', 15 + $day ) );
		}
		$this->exercise( 'Older run', '2026-08-02 07:00:00' );

		$xpath = $this->xpath( $this->serve( $this->archive_url(), '"weeksPerPage":4', '"weeksPerPage":4,"linesPerPage":3' ) );
		$this->assertSame( 2, $xpath->query( '//section[contains(@class,"pkiw-exercise-week")]' )->length );
		$this->assertSame( 8, $xpath->query( '//ul[contains(@class,"pkiw-exercise-day__bibs")]/li' )->length, 'All eight on one page: two whole weeks.' );
		$this->assertSame( 1, (int) $GLOBALS['wp_query']->max_num_pages );
	}

	public function test_weeks_per_page_sets_how_many_weeks_a_page_holds_and_clamps_to_twelve(): void {
		foreach ( [ '2026-08-23', '2026-08-16', '2026-08-09' ] as $date ) {
			$this->exercise( 'Run ' . $date, $date . ' 08:00:00' );
		}

		// go_to() builds a new main query each time, so read it afresh after each page.
		$this->serve( $this->archive_url(), '"weeksPerPage":4', '"weeksPerPage":2' );
		$this->assertSame( 2, (int) $GLOBALS['wp_query']->max_num_pages, 'Two weeks a page: two pages.' );
		$this->assertCount( 2, $GLOBALS['wp_query']->get( 'pkiw_calendar' )['keys'] );

		$this->serve( $this->archive_url(), '"weeksPerPage":4', '"weeksPerPage":99' );
		$this->assertSame( 1, (int) $GLOBALS['wp_query']->max_num_pages );
		$this->assertCount( 3, $GLOBALS['wp_query']->get( 'pkiw_calendar' )['keys'], 'All three weeks fit on one page.' );
	}

	public function test_weeks_per_page_is_clamped_to_twelve_in_the_plan(): void {
		foreach ( range( 0, 13 ) as $week ) {
			$this->exercise( 'Week ' . $week, gmdate( 'Y-m-d', strtotime( '2026-08-23 -' . ( 7 * $week ) . ' days' ) ) . ' 08:00:00' );
		}

		$this->serve( $this->archive_url(), '"weeksPerPage":4', '"weeksPerPage":99' );
		$this->assertCount( 12, $GLOBALS['wp_query']->get( 'pkiw_calendar' )['keys'], 'No more than twelve weeks a page.' );
		$this->assertSame( 2, (int) $GLOBALS['wp_query']->max_num_pages );

		$this->serve( $this->archive_url(), '"weeksPerPage":4', '"weeksPerPage":0' );
		$this->assertCount( 4, $GLOBALS['wp_query']->get( 'pkiw_calendar' )['keys'], 'Zero means the default of four.' );
	}

	public function test_the_block_renderer_preview_prints_the_same_week_body_as_the_front_end(): void {
		$sat = $this->exercise( 'Saturday run', '2026-08-29 23:30:00', [ 'activity' => 'Run', 'distance' => 3.1, 'distanceUnit' => 'mi', 'showDetailsPublicly' => true ] );
		$sun = $this->exercise( 'Sunday run', '2026-08-23 07:10:00', [ 'activity' => 'Run', 'distance' => 5.0, 'showDetailsPublicly' => true ] );

		$front = $this->xpath( $this->serve( $this->archive_url() ) );
		$front_days = $front->query( '//ol[contains(@class,"pkiw-exercise-week__days")]' )->item( 0 );
		$front_text = preg_replace( '/\s+/', ' ', $front->query( '//p[contains(@class,"pkiw-exercise-week__summary")]' )->item( 0 )->textContent );

		// The newest post of a week opens its section in the editor, and prints the whole week.
		$preview = $this->xpath( $this->editor_render( $sat ) );
		$days    = $preview->query( '//ol[contains(@class,"pkiw-exercise-week__days")]' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $days );
		$this->assertSame( 7, $preview->query( './li', $days )->length );
		$this->assertSame( $front_text, preg_replace( '/\s+/', ' ', $preview->query( '//p[contains(@class,"pkiw-exercise-week__summary")]' )->item( 0 )->textContent ) );
		$this->assertSame( $front->query( './li', $front_days )->length, $preview->query( './li', $days )->length, 'Seven cells on the front end and in the preview.' );

		// A post that continues the week prints a hidden placeholder.
		$continued = $this->editor_render( $sun );
		$this->assertStringContainsString( 'pkiw-exercise-week__continued', $continued );
		$this->assertStringContainsString( 'hidden', $continued );
		$this->assertStringNotContainsString( 'pkiw-exercise-week__days', $continued );
	}

	public function test_a_protected_post_prints_only_its_title_link_in_the_preview(): void {
		$this->exercise( 'Open run', '2026-08-23 07:10:00', [ 'activity' => 'Run', 'distance' => 5.0, 'showDetailsPublicly' => true ] );
		$locked = $this->exercise( 'Locked run', '2026-08-24 07:10:00', [ 'activity' => 'Cycle', 'distance' => 20.0, 'showDetailsPublicly' => true ], [ 'post_password' => 'secret' ] );

		$html = $this->editor_render( $locked );
		$this->assertStringContainsString( 'Protected: Locked run', $html );
		$this->assertStringNotContainsString( 'Cycle', $html );
		$this->assertStringNotContainsString( '20.00', $html, 'No distance from a protected card.' );
		$this->assertStringNotContainsString( '25.0 km', $html, 'Its distance is not in the week total either.' );
		$this->assertStringContainsString( '5.0 km', $html, 'The open run still counts in the total.' );
	}

	/**
	 * A workout titled from a hidden location.
	 *
	 * @param array<string, mixed> $args Post args.
	 */
	private function location_titled( array $args = [] ): int {
		return $this->exercise(
			'Maplewood Track',
			'2026-08-23 07:10:00',
			[ 'activity' => 'Run' ],
			array_merge(
				[
					'meta_input' => [
						'_pkiw_title_source' => 'location',
						'_pkiw_geo_privacy'  => 'private',
					],
				],
				$args
			)
		);
	}

	public function test_a_hidden_location_title_never_prints_in_a_bib_on_an_admin_screen(): void {
		$id = $this->location_titled();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		set_current_screen( 'edit-post' );
		$this->assertTrue( is_admin() );

		$preview = $this->editor_render( $id );
		$this->assertStringNotContainsStringIgnoringCase( 'maplewood', $preview );
		$this->assertStringContainsString( 'Check-in, August 23, 2026', $preview );
	}

	public function test_a_hidden_location_title_never_prints_in_a_protected_bib_on_an_admin_screen(): void {
		$id = $this->location_titled( [ 'post_password' => 'secret' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		set_current_screen( 'edit-post' );

		$preview = $this->editor_render( $id );
		$this->assertStringNotContainsStringIgnoringCase( 'maplewood', $preview );
		$this->assertStringContainsString( 'Protected: Check-in, August 23, 2026', $preview, 'Core\'s prefix, then the safe title.' );
	}

	public function test_a_protected_bib_with_a_visible_title_prints_it_as_core_gives_it_on_an_admin_screen(): void {
		$id = $this->exercise( 'Locked run', '2026-08-23 07:10:00', [ 'activity' => 'Run' ], [ 'post_password' => 'secret' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		set_current_screen( 'edit-post' );

		$html = $this->editor_render( $id );
		$this->assertStringContainsString( '>Locked run</a>', $html, 'Core adds its prefix on the front end only.' );
		$this->assertStringNotContainsString( 'Run</p>', $html, 'A protected post prints no activity.' );
	}

	public function test_the_entry_outside_a_section_prints_one_line_with_no_week_markup(): void {
		$id   = $this->exercise( 'Lone run', '2026-08-23 07:10:00' );
		$post = get_post( $id );
		$this->go_to( get_permalink( $id ) );
		$GLOBALS['post'] = $post;
		setup_postdata( $post );

		$html = Exercise_Archive::render( [ 'headingLevel' => 2 ], '', null );
		$this->assertStringNotContainsString( '<section', $html );
		$this->assertStringNotContainsString( 'pkiw-exercise-week', $html );
		$this->assertStringContainsString( '<h2 class="pkiw-exercise-bib__title">', $html, 'No section heading above it, so the title keeps the entry level.' );
		$this->assertStringContainsString( 'Lone run', $html );
	}
}
