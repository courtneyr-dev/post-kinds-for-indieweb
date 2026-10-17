<?php
/**
 * The plugin's exercise archive template and its week paging hook (issue 245).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Exercise_Archive;
use PKIW\Exercise_Medals;
use PKIW\Taxonomy;

/**
 * The exercise archive template belongs to the plugin unless a theme or user
 * copy wins, and only a template that holds the exercise entry turns on week
 * paging.
 *
 * @group integration
 */
final class ExerciseArchiveTemplateTest extends WP_UnitTestCase {

	private static string $theme_root = '';

	private string $original_stylesheet = '';

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		self::$theme_root = trailingslashit( get_temp_dir() ) . 'pkiw-exercise-template-themes-' . wp_generate_password( 8, false );
		$theme            = self::$theme_root . '/pkiw-exercise-theme';
		wp_mkdir_p( $theme . '/templates' );
		file_put_contents( $theme . '/style.css', "/*\nTheme Name: PKIW Exercise Theme\n*/\n" );
		file_put_contents( $theme . '/templates/index.html', '<!-- wp:paragraph --><p>index</p><!-- /wp:paragraph -->' );
		file_put_contents( $theme . '/templates/taxonomy-kind-exercise.html', '<!-- wp:paragraph --><p>theme-owned-exercise-archive</p><!-- /wp:paragraph -->' );
	}

	public static function tear_down_after_class(): void {
		foreach ( [ 'templates/taxonomy-kind-exercise.html', 'templates/index.html', 'style.css' ] as $file ) {
			wp_delete_file( self::$theme_root . '/pkiw-exercise-theme/' . $file );
		}
		rmdir( self::$theme_root . '/pkiw-exercise-theme/templates' );
		rmdir( self::$theme_root . '/pkiw-exercise-theme' );
		rmdir( self::$theme_root );
		parent::tear_down_after_class();
	}

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		register_theme_directory( dirname( __DIR__ ) . '/fixtures/themes' );
		register_theme_directory( self::$theme_root );
		search_theme_directories( true );
		wp_clean_themes_cache();
		switch_theme( 'twentytwentyfive' );
		( new Taxonomy() )->create_default_terms();
	}

	public function tear_down(): void {
		switch_theme( $this->original_stylesheet );
		foreach ( [ dirname( __DIR__ ) . '/fixtures/themes', self::$theme_root ] as $root ) {
			$at = array_search( $root, $GLOBALS['wp_theme_directories'], true );
			if ( false !== $at ) {
				unset( $GLOBALS['wp_theme_directories'][ $at ] );
			}
		}
		search_theme_directories( true );
		wp_clean_themes_cache();
		parent::tear_down();
	}

	/**
	 * @return string[]
	 */
	private function hierarchy(): array {
		return [ 'taxonomy-kind-exercise.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ];
	}

	private function term(): WP_Term {
		$term = get_term_by( 'slug', 'exercise', Taxonomy::TAXONOMY );
		$this->assertInstanceOf( WP_Term::class, $term );

		return $term;
	}

	private function template_content(): string {
		$this->assertFileExists( PKIW_PATH . 'templates/taxonomy-kind-exercise.html' );

		return (string) file_get_contents( PKIW_PATH . 'templates/taxonomy-kind-exercise.html' );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function blocks(): array {
		$flatten = static function ( array $blocks ) use ( &$flatten ): array {
			$out = [];
			foreach ( $blocks as $block ) {
				if ( null === $block['blockName'] ) {
					continue;
				}
				$out[] = $block;
				$out   = array_merge( $out, $flatten( $block['innerBlocks'] ?? [] ) );
			}
			return $out;
		};

		return $flatten( parse_blocks( $this->template_content() ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks Blocks.
	 * @param string                           $name   Block name.
	 * @return array<int, array<string, mixed>>
	 */
	private function of_type( array $blocks, string $name ): array {
		return array_values( array_filter( $blocks, static fn( array $block ): bool => $name === $block['blockName'] ) );
	}

	public function test_the_plugin_template_serves_the_exercise_archive(): void {
		$this->assertContains( 'post-kinds-for-indieweb//taxonomy-kind-exercise', wp_list_pluck( get_block_templates( [ 'slug__in' => [ 'taxonomy-kind-exercise' ] ] ), 'id' ) );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'post-kinds-for-indieweb//taxonomy-kind-exercise', $template->id );
		$this->assertSame( 'Exercise Archive', $template->title );
	}

	public function test_a_theme_exercise_template_wins(): void {
		switch_theme( 'pkiw-exercise-theme' );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'pkiw-exercise-theme//taxonomy-kind-exercise', $template->id );
		$this->assertStringContainsString( 'theme-owned-exercise-archive', $template->content );
	}

	public function test_a_theme_kind_template_wins_over_the_plugin_exercise_template(): void {
		// A plain taxonomy-kind template is courtneyr-child's case: it blocks the plugin's per-kind slug.
		switch_theme( 'pkiw-kind-theme' );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'pkiw-kind-theme//taxonomy-kind', $template->id );
		$this->assertStringContainsString( 'theme-owned-kind-archive', $template->content );
	}

	public function test_a_site_editor_copy_wins(): void {
		$post_id = self::factory()->post->create(
			[
				'post_type'    => 'wp_template',
				'post_name'    => 'taxonomy-kind-exercise',
				'post_title'   => 'Exercise archive customized',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>user-customized-exercise</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_post_terms( $post_id, get_stylesheet(), 'wp_theme' );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'custom', $template->source );
		$this->assertStringContainsString( 'user-customized-exercise', $template->content );
	}

	public function test_the_template_structure_matches_the_exercise_archive_contract(): void {
		$blocks = $this->blocks();
		$names  = array_column( $blocks, 'blockName' );

		$this->assertCount( 1, $this->of_type( $blocks, 'core/query-title' ) );
		$this->assertCount( 1, $this->of_type( $blocks, 'core/term-description' ) );
		$this->assertCount( 1, $this->of_type( $blocks, Exercise_Medals::BLOCK ) );

		$queries = $this->of_type( $blocks, 'core/query' );
		$this->assertCount( 1, $queries );
		$this->assertTrue( $queries[0]['attrs']['query']['inherit'] ?? false );

		$loops = $this->of_type( $blocks, 'core/post-template' );
		$this->assertCount( 1, $loops );
		$this->assertSame( [ Exercise_Archive::ENTRY ], array_column( $loops[0]['innerBlocks'], 'blockName' ) );
		$this->assertSame( 4, $loops[0]['innerBlocks'][0]['attrs']['weeksPerPage'] ?? null );

		$this->assertLessThan( array_search( 'core/query', $names, true ), array_search( Exercise_Medals::BLOCK, $names, true ), 'The medal row comes before the loop.' );
		$this->assertLessThan( array_search( 'core/query-pagination', $names, true ), array_search( 'core/post-template', $names, true ), 'Pagination follows the post template.' );

		$pagination = $this->of_type( $blocks, 'core/query-pagination' );
		$this->assertCount( 1, $pagination );
		foreach ( [ 'core/query-pagination-previous', 'core/query-pagination-numbers', 'core/query-pagination-next' ] as $part ) {
			$this->assertCount( 1, $this->of_type( $blocks, $part ), $part );
		}
		$this->assertCount( 1, $this->of_type( $blocks, 'core/query-no-results' ) );
	}

	public function test_the_template_asks_for_the_week_plan_and_a_template_without_the_entry_does_not(): void {
		$term = $this->term();

		$this->assertSame(
			[
				'unit'  => 'week',
				'count' => 4,
				'order' => 'DESC',
			],
			apply_filters( 'pkiw_calendar_paging', null, $term, $this->template_content() )
		);
		$this->assertSame(
			[
				'unit'  => 'week',
				'count' => 4,
				'order' => 'DESC',
			],
			Exercise_Archive::calendar_paging( null, $term, $this->template_content() )
		);

		$bare = '<!-- wp:query {"queryId":0,"query":{"inherit":true}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --></div><!-- /wp:query -->';
		$this->assertNull( apply_filters( 'pkiw_calendar_paging', null, $term, $bare ), 'No exercise-entry, no week paging.' );
		$this->assertNull( Exercise_Archive::calendar_paging( null, $term, $bare ) );
	}

	public function test_the_plan_follows_the_entry_block_weeks_and_ignores_other_kinds_and_a_stray_entry(): void {
		$term     = $this->term();
		$template = str_replace( '"weeksPerPage":4', '"weeksPerPage":6', $this->template_content() );
		$this->assertSame( 6, apply_filters( 'pkiw_calendar_paging', null, $term, $template )['count'] );

		$this->assertNull( apply_filters( 'pkiw_calendar_paging', null, get_term_by( 'slug', 'note', Taxonomy::TAXONOMY ), $this->template_content() ), 'Only the exercise term asks.' );
		$this->assertNull( Exercise_Archive::calendar_paging( null, 'not a term', $this->template_content() ) );
		$this->assertNull( Exercise_Archive::calendar_paging( null, $term, 12 ) );

		// An entry outside the inheriting Query Loop configures nothing.
		$stray = '<!-- wp:post-kinds-indieweb/exercise-entry {"weeksPerPage":3} /--><!-- wp:query {"queryId":0,"query":{"inherit":false}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --></div><!-- /wp:query -->';
		$this->assertNull( apply_filters( 'pkiw_calendar_paging', null, $term, $stray ) );
	}

	public function test_another_filter_answer_is_passed_through_for_a_template_without_the_entry(): void {
		$term  = $this->term();
		$other = [
			'unit'  => 'month',
			'count' => 1,
			'order' => 'ASC',
		];

		$this->assertSame( $other, Exercise_Archive::calendar_paging( $other, $term, '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ) );
	}

	public function test_the_plan_reads_the_weeks_from_the_engine_not_from_a_second_parse(): void {
		$source = (string) file_get_contents( PKIW_PATH . 'includes/class-exercise-archive.php' );

		$this->assertStringContainsString( 'Grouped_Archive::main_query_plan(', $source );
		$this->assertStringNotContainsString( 'weeksPerPage', substr( $source, (int) strpos( $source, 'public static function calendar_paging' ), 1400 ), 'calendar_paging() does not parse the template for weeksPerPage.' );
	}
}
