<?php
/**
 * The plugin's wish archive block template.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

use PKIW\Kind_Archive_Layouts;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * Covers template precedence, structure, archive sizing, feeds and preview.
 *
 * @since 1.9.0
 *
 * @group integration
 */
final class WishArchiveTemplateTest extends WP_UnitTestCase {

	/**
	 * Root containing the throwaway wish-specific theme.
	 *
	 * @since 1.9.0
	 *
	 * @var string
	 */
	private static string $theme_root = '';

	/**
	 * Stylesheet active before each test.
	 *
	 * @since 1.9.0
	 *
	 * @var string
	 */
	private string $original_stylesheet = '';

	/**
	 * Create a block theme with a wish-specific archive template.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		self::$theme_root = trailingslashit( get_temp_dir() ) . 'pkiw-wish-template-themes-' . wp_generate_password( 8, false );
		$theme            = self::$theme_root . '/pkiw-wish-theme';
		wp_mkdir_p( $theme . '/templates' );
		file_put_contents( $theme . '/style.css', "/*\nTheme Name: PKIW Wish Theme\n*/\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $theme . '/templates/index.html', '<!-- wp:paragraph --><p>index</p><!-- /wp:paragraph -->' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $theme . '/templates/taxonomy-kind-wish.html', '<!-- wp:paragraph --><p>theme-owned-wish-archive</p><!-- /wp:paragraph -->' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Remove the throwaway theme files.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public static function tear_down_after_class(): void {
		foreach ( [ 'templates/taxonomy-kind-wish.html', 'templates/index.html', 'style.css' ] as $file ) {
			wp_delete_file( self::$theme_root . '/pkiw-wish-theme/' . $file );
		}
		rmdir( self::$theme_root . '/pkiw-wish-theme/templates' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( self::$theme_root . '/pkiw-wish-theme' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( self::$theme_root ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		parent::tear_down_after_class();
	}

	/**
	 * Prepare block themes, terms, rewrites and registered wish meta.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		register_theme_directory( dirname( __DIR__ ) . '/fixtures/themes' );
		register_theme_directory( self::$theme_root );
		search_theme_directories( true );
		wp_clean_themes_cache();
		switch_theme( 'twentytwentyfive' );
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
		add_filter( 'pre_http_request', '__return_empty_array' );
	}

	/**
	 * Restore themes, rewrites and request identity.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		switch_theme( $this->original_stylesheet );
		foreach ( [ dirname( __DIR__ ) . '/fixtures/themes', self::$theme_root ] as $root ) {
			$at = array_search( $root, $GLOBALS['wp_theme_directories'], true );
			if ( false !== $at ) {
				unset( $GLOBALS['wp_theme_directories'][ $at ] );
			}
		}
		search_theme_directories( true );
		wp_clean_themes_cache();
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/**
	 * Core taxonomy template hierarchy for the wish term.
	 *
	 * @since 1.9.0
	 *
	 * @return string[] Template candidates.
	 */
	private function hierarchy(): array {
		return [ 'taxonomy-kind-wish.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ];
	}

	/**
	 * Flattened blocks from the shipped wish template.
	 *
	 * @since 1.9.0
	 *
	 * @return array<int, array<string, mixed>> Parsed blocks.
	 */
	private function blocks(): array {
		$file = PKIW_PATH . 'templates/taxonomy-kind-wish.html';
		$this->assertFileExists( $file );
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

		return $flatten( parse_blocks( (string) file_get_contents( $file ) ) );
	}

	/**
	 * Blocks of one type.
	 *
	 * @since 1.9.0
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @param string                           $name   Block name.
	 * @return array<int, array<string, mixed>> Matching blocks.
	 */
	private function of_type( array $blocks, string $name ): array {
		return array_values( array_filter( $blocks, static fn( array $block ): bool => $name === $block['blockName'] ) );
	}

	/**
	 * Direct children of the template's only Post Template block.
	 *
	 * @since 1.9.0
	 *
	 * @return array<int, array<string, mixed>> Child blocks.
	 */
	private function post_template_children(): array {
		$template = $this->of_type( $this->blocks(), 'core/post-template' );
		$this->assertCount( 1, $template );

		return array_values( array_filter( $template[0]['innerBlocks'], static fn( array $block ): bool => null !== $block['blockName'] ) );
	}

	/**
	 * Create a published wish card post.
	 *
	 * @since 1.9.0
	 *
	 * @param string $title Post and card title.
	 * @param string $type  Stored wish type.
	 * @param string $date  Post date.
	 * @return int Post ID.
	 */
	private function wish( string $title, string $type, string $date ): int {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_date'    => $date,
				'post_content' => '<!-- wp:post-kinds-indieweb/wish-card ' . serialize_block_attributes( [ 'title' => $title, 'wishType' => $type ] ) . ' /-->',
			]
		);
		wp_set_object_terms( $id, 'wish', Taxonomy::TAXONOMY );

		return $id;
	}

	/**
	 * The plugin template serves when the theme has no matching template.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_plugin_template_serves_the_wish_archive(): void {
		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'post-kinds-for-indieweb//taxonomy-kind-wish', $template->id );
		$this->assertContains( 'post-kinds-for-indieweb//taxonomy-kind-wish', wp_list_pluck( get_block_templates( [ 'slug__in' => [ 'taxonomy-kind-wish' ] ] ), 'id' ) );
	}

	/**
	 * A theme's wish-specific template has precedence over the plugin copy.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_theme_wish_template_wins(): void {
		switch_theme( 'pkiw-wish-theme' );
		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'pkiw-wish-theme//taxonomy-kind-wish', $template->id );
		$this->assertStringContainsString( 'theme-owned-wish-archive', $template->content );
	}

	/**
	 * A theme's generic kind template has precedence over the plugin copy.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_theme_kind_template_wins(): void {
		switch_theme( 'pkiw-kind-theme' );
		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'pkiw-kind-theme//taxonomy-kind', $template->id );
		$this->assertStringContainsString( 'theme-owned-kind-archive', $template->content );
	}

	/**
	 * A Site Editor copy has precedence over plugin and theme files.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_site_editor_copy_wins(): void {
		$id = self::factory()->post->create(
			[
				'post_type'    => 'wp_template',
				'post_name'    => 'taxonomy-kind-wish',
				'post_title'   => 'Wish archive customized',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>user-customized-wish</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_post_terms( $id, get_stylesheet(), 'wp_theme' );
		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'custom', $template->source );
		$this->assertStringContainsString( 'user-customized-wish', $template->content );
	}

	/**
	 * The shipped template contains each structural block exactly once.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_template_structure_matches_the_archive_contract(): void {
		$blocks = $this->blocks();
		$titles = $this->of_type( $blocks, 'core/query-title' );
		$this->assertCount( 1, $titles );
		$this->assertSame( 'archive', $titles[0]['attrs']['type'] ?? '' );
		$this->assertFalse( $titles[0]['attrs']['showPrefix'] ?? true );
		$this->assertSame( 1, (int) ( $titles[0]['attrs']['level'] ?? 1 ) );
		foreach ( $blocks as $block ) {
			if ( in_array( $block['blockName'], [ 'core/heading', 'core/post-title' ], true ) ) {
				$this->assertNotSame( 1, (int) ( $block['attrs']['level'] ?? 2 ) );
			}
		}
		$this->assertCount( 1, $this->of_type( $blocks, 'core/term-description' ) );
		$queries = $this->of_type( $blocks, 'core/query' );
		$this->assertCount( 1, $queries );
		$this->assertTrue( $queries[0]['attrs']['query']['inherit'] ?? false );
		$this->assertSame( 'pkiw-kind-archive', $queries[0]['attrs']['className'] ?? '' );

		$pagination = $this->of_type( $blocks, 'core/query-pagination' );
		$this->assertCount( 1, $pagination );
		$this->assertSame( 'arrow', $pagination[0]['attrs']['paginationArrow'] ?? '' );
		$this->assertSame( 'center', $pagination[0]['attrs']['layout']['justifyContent'] ?? '' );
		$this->assertCount( 1, $this->of_type( $blocks, 'core/query-pagination-previous' ) );
		$this->assertCount( 1, $this->of_type( $blocks, 'core/query-pagination-numbers' ) );
		$this->assertCount( 1, $this->of_type( $blocks, 'core/query-pagination-next' ) );

		$empty = $this->of_type( $blocks, 'core/query-no-results' );
		$this->assertCount( 1, $empty );
		$paragraphs = $this->of_type( $empty[0]['innerBlocks'], 'core/paragraph' );
		$this->assertCount( 1, $paragraphs );
		$this->assertSame( 'No wishes yet.', trim( wp_strip_all_tags( $paragraphs[0]['innerHTML'] ) ) );
		$this->assertCount( 0, $this->of_type( $blocks, 'post-kinds-indieweb/staff-picks' ) );

		$parts = $this->of_type( $blocks, 'core/template-part' );
		$this->assertSame( [ 'header', 'footer' ], array_column( array_column( $parts, 'attrs' ), 'slug' ) );
	}

	/**
	 * The Post Template holds one marker followed by one level-three Stream card.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_post_template_contains_the_marker_then_stream_card(): void {
		$templates = $this->of_type( $this->blocks(), 'core/post-template' );
		$this->assertCount( 1, $templates );
		$this->assertDoesNotMatchRegularExpression( '/(^|\s)is-style-/', (string) ( $templates[0]['attrs']['className'] ?? '' ) );

		$children = $this->post_template_children();
		$this->assertSame( [ 'post-kinds-indieweb/archive-sections', 'post-kinds-indieweb/stream-card' ], array_column( $children, 'blockName' ) );
		$this->assertSame( 12, $children[0]['attrs']['linesPerPage'] ?? null );
		$this->assertArrayNotHasKey( 'groupLabels', $children[0]['attrs'] );
		$this->assertArrayNotHasKey( 'emptyLabel', $children[0]['attrs'] );
		$this->assertSame( 3, $children[1]['attrs']['headingLevel'] ?? null );
	}

	/**
	 * Resolved template content lets the marker set the main query page size.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_resolved_template_marker_sets_twelve_posts_per_page(): void {
		$content = Kind_Archive_Layouts::kind_template_content( 'wish' );
		$this->assertIsString( $content );
		$this->assertStringContainsString( 'archive-sections {"linesPerPage":12}', $content );
		for ( $at = 1; $at <= 13; $at++ ) {
			$this->wish( 'Sized wish ' . $at, 'book', sprintf( '2026-01-%02d 10:00:00', $at ) );
		}

		$this->go_to( (string) get_term_link( 'wish', Taxonomy::TAXONOMY ) );
		$this->assertSame( 12, (int) $GLOBALS['wp_query']->get( 'posts_per_page' ) );
		$this->assertSame( 12, $GLOBALS['wp_query']->post_count );
	}

	/**
	 * Kind feeds retain newest-first order and the site's configured page size.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_kind_feed_keeps_date_order_and_site_page_size(): void {
		update_option( 'posts_per_rss', 3 );
		$oldest = $this->wish( 'Oldest', 'book', '2026-02-01 10:00:00' );
		$newest = $this->wish( 'Newest', 'tech', '2026-02-04 10:00:00' );
		$second = $this->wish( 'Second', 'other', '2026-02-03 10:00:00' );
		$third  = $this->wish( 'Third', 'book', '2026-02-02 10:00:00' );
		$term   = get_term_by( 'slug', 'wish', Taxonomy::TAXONOMY );
		$this->assertInstanceOf( WP_Term::class, $term );

		$this->go_to( get_term_feed_link( $term->term_id, Taxonomy::TAXONOMY ) );

		$this->assertTrue( is_feed() );
		$this->assertSame( 3, (int) $GLOBALS['wp_query']->get( 'posts_per_page' ) );
		$this->assertSame( [ $newest, $second, $third ], array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) ) );
		$this->assertNotContains( $oldest, array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) ) );
	}

	/**
	 * Site Editor block renders place each section heading above its first card.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_site_editor_preview_places_headings_above_first_cards(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->wish( 'Book Alpha', 'book', '2026-03-04 10:00:00' );
		$this->wish( 'Book Beta', 'book', '2026-03-03 10:00:00' );
		$this->wish( 'Tech Gamma', 'tech', '2026-03-02 10:00:00' );
		$this->wish( 'Other Delta', 'other', '2026-03-01 10:00:00' );
		$this->go_to( (string) get_term_link( 'wish', Taxonomy::TAXONOMY ) );
		$ids = array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );

		$sequence = [];
		foreach ( $ids as $id ) {
			foreach ( $this->post_template_children() as $child ) {
				$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/' . $child['blockName'] );
				$request->set_param( 'context', 'edit' );
				$request->set_param( 'attributes', $child['attrs'] );
				$request->set_param( 'post_id', $id );
				$request->set_param( 'pkiw_kind', 'wish' );
				$response = rest_get_server()->dispatch( $request );
				$this->assertSame( 200, $response->get_status(), $child['blockName'] . ': ' . wp_json_encode( $response->get_data() ) );
				$html                      = (string) ( $response->get_data()['rendered'] ?? '' );
				$GLOBALS['wp_rest_server'] = null;

				if ( 'post-kinds-indieweb/archive-sections' !== $child['blockName'] ) {
					$sequence[] = 'card:' . get_the_title( $id );
				} elseif ( preg_match( '/<h[2-4][^>]*>([^<]*)<\/h[2-4]>/', $html, $match ) ) {
					$sequence[] = 'H:' . $match[1];
				} else {
					$this->assertStringContainsString( ' hidden>', $html );
					$sequence[] = '(hidden)';
				}
			}
		}

		$this->assertSame(
			[
				'H:Book',
				'card:Book Alpha',
				'(hidden)',
				'card:Book Beta',
				'H:Tech',
				'card:Tech Gamma',
				'H:Other',
				'card:Other Delta',
			],
			$sequence
		);
	}
}
