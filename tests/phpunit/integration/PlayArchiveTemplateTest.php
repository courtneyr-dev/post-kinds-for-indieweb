<?php
/**
 * The plugin's play archive template (issues 232 and 237).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Taxonomy;

/**
 * Covers templates/taxonomy-kind-play.html: it serves /kind/play/ when the
 * theme has no play or kind template, a theme's own taxonomy-kind-play wins,
 * and the template holds the blocks the play archive is built from.
 *
 * @group integration
 */
final class PlayArchiveTemplateTest extends WP_UnitTestCase {

	/**
	 * Stylesheet active before each test.
	 *
	 * @var string
	 */
	private string $original_stylesheet = '';

	/**
	 * Directory holding the throwaway theme, '' when none was made.
	 *
	 * @var string
	 */
	private string $theme_root = '';

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		\PKIW\register_play_kind();
		$this->original_stylesheet = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		add_filter( 'pre_http_request', '__return_empty_array' );
	}

	public function tear_down(): void {
		switch_theme( $this->original_stylesheet );
		if ( '' !== $this->theme_root ) {
			$key = array_search( $this->theme_root, $GLOBALS['wp_theme_directories'], true );
			if ( false !== $key ) {
				unset( $GLOBALS['wp_theme_directories'][ $key ] );
			}
			search_theme_directories( true );
			wp_clean_themes_cache();
			$this->remove_tree( $this->theme_root );
			$this->theme_root = '';
		}
		parent::tear_down();
	}

	/**
	 * Core's taxonomy hierarchy for the play term.
	 *
	 * @return string[]
	 */
	private function hierarchy(): array {
		return [ 'taxonomy-kind-play.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ];
	}

	/**
	 * Parsed blocks of the shipped template, flattened.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function template_blocks(): array {
		$file = PKIW_PATH . 'templates/taxonomy-kind-play.html';
		$this->assertFileExists( $file );

		return $this->flatten( parse_blocks( (string) file_get_contents( $file ) ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return array<int, array<string, mixed>>
	 */
	private function flatten( array $blocks ): array {
		$out = [];
		foreach ( $blocks as $block ) {
			if ( null === $block['blockName'] ) {
				continue;
			}
			$out[] = $block;
			$out   = array_merge( $out, $this->flatten( $block['innerBlocks'] ?? [] ) );
		}

		return $out;
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks Flattened blocks.
	 * @param string                           $name   Block name.
	 * @return array<int, array<string, mixed>>
	 */
	private function of_type( array $blocks, string $name ): array {
		return array_values( array_filter( $blocks, static fn( $b ) => $name === $b['blockName'] ) );
	}

	/**
	 * A child theme of Twenty Twenty-Five with its own play archive.
	 */
	private function make_theme_with_a_play_template(): string {
		$this->theme_root = trailingslashit( get_temp_dir() ) . 'pkiw-play-themes-' . wp_generate_password( 8, false );
		$theme_dir        = $this->theme_root . '/pkiw-play-theme';
		wp_mkdir_p( $theme_dir . '/templates' );
		file_put_contents( $theme_dir . '/style.css', "/*\nTheme Name: PKIW Play Theme (test)\nTemplate: twentytwentyfive\nVersion: 1.0.0\n*/\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $theme_dir . '/templates/taxonomy-kind-play.html', '<!-- wp:group {"tagName":"main","className":"theme-owned-play-archive"} --><main class="wp-block-group theme-owned-play-archive"><!-- wp:query-title {"type":"archive"} /--></main><!-- /wp:group -->' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		register_theme_directory( $this->theme_root );
		search_theme_directories( true );
		wp_clean_themes_cache();

		return 'pkiw-play-theme';
	}

	/**
	 * Remove a directory tree this test made.
	 *
	 * @param string $dir Directory.
	 */
	private function remove_tree( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) ) {
				$this->remove_tree( $path );
			} else {
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	// Serving.

	public function test_the_plugin_template_serves_the_play_archive_when_the_theme_has_none(): void {
		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'post-kinds-for-indieweb//taxonomy-kind-play', $template->id );
		$this->assertContains( 'post-kinds-for-indieweb//taxonomy-kind-play', wp_list_pluck( get_block_templates( [ 'slug__in' => [ 'taxonomy-kind-play' ] ] ), 'id' ) );
	}

	public function test_a_theme_play_template_wins_over_the_plugin_s(): void {
		switch_theme( $this->make_theme_with_a_play_template() );
		$this->assertSame( 'pkiw-play-theme', get_stylesheet() );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'pkiw-play-theme//taxonomy-kind-play', $template->id );
		$this->assertStringContainsString( 'theme-owned-play-archive', $template->content );
	}

	public function test_a_site_editor_play_template_wins_over_the_plugin_s(): void {
		$post_id = self::factory()->post->create(
			[
				'post_type'    => 'wp_template',
				'post_name'    => 'taxonomy-kind-play',
				'post_title'   => 'Play archive (customized)',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>user-customized-play</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_post_terms( $post_id, get_stylesheet(), 'wp_theme' );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'custom', $template->source );
		$this->assertStringContainsString( 'user-customized-play', $template->content );
	}

	// Contents.

	public function test_the_template_holds_one_title_one_inheriting_query_and_one_pagination(): void {
		$blocks = $this->template_blocks();

		$titles = $this->of_type( $blocks, 'core/query-title' );
		$this->assertCount( 1, $titles );
		$this->assertSame( 1, (int) ( $titles[0]['attrs']['level'] ?? 1 ), 'The query title is the h1.' );
		foreach ( $blocks as $block ) {
			if ( in_array( $block['blockName'], [ 'core/heading', 'core/post-title' ], true ) ) {
				$this->assertNotSame( 1, (int) ( $block['attrs']['level'] ?? 2 ), 'No second h1.' );
			}
		}
		$this->assertCount( 1, $this->of_type( $blocks, 'core/term-description' ) );

		$queries = $this->of_type( $blocks, 'core/query' );
		$this->assertCount( 1, $queries );
		$this->assertTrue( $queries[0]['attrs']['query']['inherit'] ?? false, 'The Query Loop inherits the main query.' );

		$pagination = $this->of_type( $blocks, 'core/query-pagination' );
		$this->assertCount( 1, $pagination );
		$this->assertSame( 'arrow', $pagination[0]['attrs']['paginationArrow'] ?? '' );
		$this->assertSame( 'center', $pagination[0]['attrs']['layout']['justifyContent'] ?? '' );
		$this->assertCount( 1, $this->of_type( $blocks, 'core/query-no-results' ) );
	}

	/**
	 * One Post Template takes one block style, and the play archive holds
	 * both board and video sections, so the theme paints by
	 * [data-pkiw-group], not by a style class.
	 */
	public function test_the_post_template_carries_no_block_style(): void {
		$templates = $this->of_type( $this->template_blocks(), 'core/post-template' );

		$this->assertCount( 1, $templates );
		$this->assertDoesNotMatchRegularExpression( '/(^|\s)is-style-/', (string) ( $templates[0]['attrs']['className'] ?? '' ) );
	}

	/**
	 * The marker sits above the card: in the editor it prints its section's
	 * heading where it sits in the Post Template.
	 */
	public function test_the_post_template_holds_a_12_line_sections_marker_above_a_level_3_stream_card(): void {
		$children = $this->post_template_children();
		$names    = array_column( $children, 'blockName' );

		$this->assertSame( [ 'post-kinds-indieweb/archive-sections', 'post-kinds-indieweb/stream-card' ], $names );
		$this->assertSame( 12, $children[0]['attrs']['linesPerPage'] ?? null );
		$this->assertSame( 3, $children[1]['attrs']['headingLevel'] ?? null );
		$this->assertArrayNotHasKey( 'groupLabels', $children[0]['attrs'], 'The plugin template keeps the source labels; a theme sets its own.' );
		$this->assertArrayNotHasKey( 'emptyLabel', $children[0]['attrs'] );
	}

	/**
	 * The Post Template's blocks, in order.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function post_template_children(): array {
		$template = $this->of_type( $this->template_blocks(), 'core/post-template' )[0];

		return array_values( array_filter( $template['innerBlocks'], static fn( $b ) => null !== $b['blockName'] ) );
	}

	/**
	 * Staff Picks lists board game plays on its own, so it takes no kind.
	 */
	public function test_staff_picks_sits_above_the_loop_with_no_attributes(): void {
		$blocks = $this->template_blocks();
		$names  = array_column( $blocks, 'blockName' );
		$picks  = $this->of_type( $blocks, 'post-kinds-indieweb/staff-picks' );

		$this->assertCount( 1, $picks );
		$this->assertSame( [], $picks[0]['attrs'] );
		$this->assertLessThan( array_search( 'core/query', $names, true ), array_search( 'post-kinds-indieweb/staff-picks', $names, true ) );
	}

	/**
	 * The Site Editor previews Staff Picks through the block renderer, which
	 * answers 400 rest_additional_properties_forbidden for an attribute the
	 * block doesn't register.
	 */
	public function test_the_block_renderer_accepts_the_template_s_staff_picks_attributes(): void {
		$picks = $this->of_type( $this->template_blocks(), 'post-kinds-indieweb/staff-picks' );
		$this->assertCount( 1, $picks );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/post-kinds-indieweb/staff-picks' );
		$request->set_param( 'context', 'edit' );
		$request->set_param( 'attributes', $picks[0]['attrs'] );
		$response = rest_get_server()->dispatch( $request );
		$GLOBALS['wp_rest_server'] = null;

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
	}

	/**
	 * The Site Editor previews the Post Template's blocks once per loop post,
	 * each through the block renderer, with the post_id and pkiw_kind that
	 * archive-sections-editor.js sends. The marker prints the heading only
	 * for the post that opens a section, so the template's block order
	 * decides whether the heading lands above or below that post's card.
	 */
	public function test_the_site_editor_preview_prints_each_section_heading_above_its_first_card(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->play( 'V1 Alpha', '2026-08-05 10:00:00', [ 'rawgId' => '900001' ] );
		$this->play( 'V2 Bravo', '2026-08-04 10:00:00', [ 'steamId' => '900002' ] );
		$this->play( 'B1 Delta', '2026-08-02 10:00:00', [ 'bggId' => '9990001' ] );
		$this->play( 'B2 Echo', '2026-08-01 10:00:00', [ 'bggId' => '9990002' ] );
		$this->play( 'O1 Foxtrot', '2026-07-30 10:00:00', [ 'gameUrl' => 'https://games.example/foxtrot' ] );

		$this->go_to( (string) get_term_link( 'play', Taxonomy::TAXONOMY ) );
		$ids = array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );

		$sequence = [];
		foreach ( $ids as $id ) {
			foreach ( $this->post_template_children() as $child ) {
				$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/' . $child['blockName'] );
				$request->set_param( 'context', 'edit' );
				$request->set_param( 'attributes', $child['attrs'] );
				$request->set_param( 'post_id', $id );
				$request->set_param( 'pkiw_kind', 'play' );
				$response                  = rest_get_server()->dispatch( $request );
				$GLOBALS['wp_rest_server'] = null;
				$html                      = (string) ( $response->get_data()['rendered'] ?? '' );
				$this->assertSame( 200, $response->get_status(), $child['blockName'] . ': ' . wp_json_encode( $response->get_data() ) );

				if ( 'post-kinds-indieweb/archive-sections' !== $child['blockName'] ) {
					$sequence[] = 'card:' . get_the_title( $id );
				} elseif ( 1 === preg_match( '#<h([2-4])[^>]*>([^<]*)</h\1>#', $html, $m ) ) {
					$sequence[] = 'H:' . $m[2];
				} else {
					$this->assertStringContainsString( ' hidden>', $html, 'A marker that prints no heading prints the hidden placeholder.' );
					$sequence[] = '(hidden)';
				}
			}
		}

		$this->assertSame(
			[
				'H:Video games',
				'card:V1 Alpha',
				'(hidden)',
				'card:V2 Bravo',
				'H:Board games',
				'card:B1 Delta',
				'(hidden)',
				'card:B2 Echo',
				'H:Other',
				'card:O1 Foxtrot',
			],
			$sequence
		);
	}

	// Rendered.

	/**
	 * A play with a card.
	 *
	 * @param string               $title Title.
	 * @param string               $date  Post date.
	 * @param array<string, mixed> $attrs Card attributes.
	 */
	private function play( string $title, string $date, array $attrs ): int {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_date'    => $date,
				'post_content' => '<!-- wp:post-kinds-indieweb/play-card ' . wp_json_encode( [ 'title' => $title ] + $attrs, JSON_UNESCAPED_SLASHES ) . ' /-->',
			]
		);
		wp_set_object_terms( $id, 'play', Taxonomy::TAXONOMY );

		return $id;
	}

	public function test_the_served_archive_prints_video_board_and_other_sections_with_one_h1(): void {
		$this->play( 'Backyard Tag', '2026-08-31 10:00:00', [ 'gameUrl' => 'https://games.example/backyard-tag' ] );
		$this->play( 'Forest Paths', '2026-08-28 10:00:00', [ 'bggId' => '9990001' ] );
		$this->play( 'Starbound Courier', '2026-08-10 10:00:00', [ 'rawgId' => '900001' ] );

		$url = get_term_link( 'play', Taxonomy::TAXONOMY );
		$this->assertIsString( $url );
		$this->go_to( $url );
		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );
		$this->assertSame( 'post-kinds-for-indieweb//taxonomy-kind-play', $template->id );

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;
		$html                         = get_the_block_template_html();

		$this->assertSame( 1, substr_count( $html, '<h1' ), 'Exactly one h1.' );
		$this->assertSame( 1, preg_match_all( '/data-pkiw-group="video"/', $html ) );
		$this->assertSame( 1, preg_match_all( '/data-pkiw-group="board"/', $html ) );
		$this->assertSame( 1, preg_match_all( '/data-pkiw-group=""/', $html ) );
		$this->assertMatchesRegularExpression( '#<h2 id="pkiw-group-video"[^>]*>Video games</h2>.*<h2 id="pkiw-group-board"[^>]*>Board games</h2>.*<h2 id="pkiw-group-empty"[^>]*>Other</h2>#s', $html );
		$this->assertMatchesRegularExpression( '#Starbound Courier.*Forest Paths.*Backyard Tag#s', $html );
		$this->assertDoesNotMatchRegularExpression( '#<h2[^>]*class="[^"]*pk-title#', $html, 'Card titles sit under the section H2s.' );
	}
}
