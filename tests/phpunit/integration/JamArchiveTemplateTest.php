<?php
/**
 * Jam archive template coverage for issue 235.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Grouped_Archive;
use PKIW\Jam_Archive;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * The plugin's jam template resolves behind theme customizations and renders
 * one lead jam followed by post-month sections.
 *
 * @group integration
 */
final class JamArchiveTemplateTest extends WP_UnitTestCase {

	/**
	 * Stylesheet active before each test.
	 *
	 * @var string
	 */
	private string $original_stylesheet = '';

	/**
	 * Directory holding the throwaway theme.
	 *
	 * @var string
	 */
	private string $theme_root = '';

	/**
	 * Register the archive request environment.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'date_format', 'F j, Y' );
		add_filter( 'pkiw_set_featured_from_artwork', '__return_false' );
		add_filter(
			'pre_http_request',
			static fn() => new WP_Error( 'blocked', 'No network in tests.' )
		);
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
		wp_set_current_user( 0 );
	}

	/**
	 * Restore theme, URL and user state and remove the test theme.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		$this->set_permalink_structure( '' );
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
	 * Core's taxonomy hierarchy for the jam term.
	 *
	 * @return string[]
	 */
	private function hierarchy(): array {
		return [ 'taxonomy-kind-jam.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ];
	}

	/**
	 * The shipped template content.
	 */
	private function template_content(): string {
		$file = PKIW_PATH . 'templates/taxonomy-kind-jam.html';
		$this->assertFileExists( $file );

		return (string) file_get_contents( $file );
	}

	/**
	 * Parsed blocks of the shipped template, flattened.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function template_blocks(): array {
		return $this->flatten( parse_blocks( $this->template_content() ) );
	}

	/**
	 * Flatten a parsed block tree.
	 *
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
	 * Select flattened blocks by name.
	 *
	 * @param array<int, array<string, mixed>> $blocks Flattened blocks.
	 * @param string                           $name   Block name.
	 * @return array<int, array<string, mixed>>
	 */
	private function of_type( array $blocks, string $name ): array {
		return array_values( array_filter( $blocks, static fn( $block ) => $name === $block['blockName'] ) );
	}

	/**
	 * Create a child theme with its own jam archive template.
	 */
	private function make_theme_with_a_jam_template(): string {
		$this->theme_root = trailingslashit( get_temp_dir() ) . 'pkiw-jam-themes-' . wp_generate_password( 8, false );
		$theme_dir        = $this->theme_root . '/pkiw-jam-theme';
		wp_mkdir_p( $theme_dir . '/templates' );
		file_put_contents( $theme_dir . '/style.css', "/*\nTheme Name: PKIW Jam Theme (test)\nTemplate: twentytwentyfive\nVersion: 1.0.0\n*/\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $theme_dir . '/templates/taxonomy-kind-jam.html', '<!-- wp:group {"tagName":"main","className":"theme-owned-jam-archive"} --><main class="wp-block-group theme-owned-jam-archive"><!-- wp:query-title {"type":"archive"} /--></main><!-- /wp:group -->' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		register_theme_directory( $this->theme_root );
		search_theme_directories( true );
		wp_clean_themes_cache();

		return 'pkiw-jam-theme';
	}

	/**
	 * Remove a directory tree created by this test.
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
				wp_delete_file( $path );
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	/**
	 * A serialized jam-card comment.
	 *
	 * @param array<string, mixed> $attrs Card attributes.
	 */
	private function card( array $attrs ): string {
		return '<!-- wp:post-kinds-indieweb/jam-card ' . serialize_block_attributes( $attrs ) . ' /-->';
	}

	/**
	 * Insert a published jam.
	 *
	 * @param string               $post_title Post title.
	 * @param string               $post_date  Local post date.
	 * @param array<string, mixed> $attrs      Card attributes.
	 * @param array<string, mixed> $args       Extra post arguments.
	 */
	private function jam( string $post_title, string $post_date, array $attrs, array $args = [] ): int {
		$id = wp_insert_post(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => $post_title,
					'post_date'    => $post_date,
					'post_content' => wp_slash( $this->card( $attrs ) ),
				],
				$args
			),
			true
		);
		$this->assertIsInt( $id );
		wp_set_object_terms( $id, 'jam', Taxonomy::TAXONOMY );

		return $id;
	}

	/**
	 * Serve the resolved jam archive template.
	 */
	private function serve(): string {
		$this->go_to( (string) get_term_link( 'jam', Taxonomy::TAXONOMY ) );
		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );
		$this->assertNotNull( $template );
		$this->assertSame( 'post-kinds-for-indieweb//taxonomy-kind-jam', $template->id );

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;

		return get_the_block_template_html();
	}

	public function test_plugin_template_serves_when_the_theme_has_no_jam_template(): void {
		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'post-kinds-for-indieweb//taxonomy-kind-jam', $template->id );
		$this->assertContains( 'post-kinds-for-indieweb//taxonomy-kind-jam', wp_list_pluck( get_block_templates( [ 'slug__in' => [ 'taxonomy-kind-jam' ] ] ), 'id' ) );
	}

	public function test_theme_jam_template_wins_over_the_plugin_template(): void {
		switch_theme( $this->make_theme_with_a_jam_template() );
		$this->assertSame( 'pkiw-jam-theme', get_stylesheet() );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'pkiw-jam-theme//taxonomy-kind-jam', $template->id );
		$this->assertStringContainsString( 'theme-owned-jam-archive', $template->content );
	}

	public function test_site_editor_customization_wins_over_the_plugin_template(): void {
		$post_id = self::factory()->post->create(
			[
				'post_type'    => 'wp_template',
				'post_name'    => 'taxonomy-kind-jam',
				'post_title'   => 'Jam archive customized',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>user-customized-jam</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_post_terms( $post_id, get_stylesheet(), 'wp_theme' );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'custom', $template->source );
		$this->assertStringContainsString( 'user-customized-jam', $template->content );
	}

	public function test_template_has_the_exact_portable_archive_structure(): void {
		$top = array_values( array_filter( parse_blocks( $this->template_content() ), static fn( $block ) => null !== $block['blockName'] ) );
		$this->assertSame( [ 'core/template-part', 'core/group', 'core/template-part' ], array_column( $top, 'blockName' ) );
		$this->assertSame( 'header', $top[0]['attrs']['slug'] ?? '' );
		$this->assertSame( 'main', $top[1]['attrs']['tagName'] ?? '' );
		$this->assertSame( 'constrained', $top[1]['attrs']['layout']['type'] ?? '' );
		$this->assertSame( 'footer', $top[2]['attrs']['slug'] ?? '' );

		$blocks = $this->template_blocks();
		$titles = $this->of_type( $blocks, 'core/query-title' );
		$this->assertCount( 1, $titles );
		$this->assertSame( 'archive', $titles[0]['attrs']['type'] ?? '' );
		$this->assertFalse( $titles[0]['attrs']['showPrefix'] ?? true );
		$this->assertSame( 1, (int) ( $titles[0]['attrs']['level'] ?? 1 ) );
		$this->assertCount( 1, $this->of_type( $blocks, 'core/term-description' ) );

		$queries = $this->of_type( $blocks, 'core/query' );
		$this->assertCount( 1, $queries );
		$this->assertTrue( $queries[0]['attrs']['query']['inherit'] ?? false );
		$this->assertSame( 'pkiw-kind-archive', $queries[0]['attrs']['className'] ?? '' );

		$templates = $this->of_type( $blocks, 'core/post-template' );
		$this->assertCount( 1, $templates );
		$this->assertArrayNotHasKey( 'className', $templates[0]['attrs'] ?? [] );
		$children = array_values( array_filter( $templates[0]['innerBlocks'], static fn( $block ) => null !== $block['blockName'] ) );
		$this->assertSame( [ 'post-kinds-indieweb/archive-sections', 'post-kinds-indieweb/stream-card' ], array_column( $children, 'blockName' ) );
		$this->assertSame( 12, $children[0]['attrs']['linesPerPage'] ?? null );
		$this->assertSame( 1, $children[0]['attrs']['leadCount'] ?? null );
		$this->assertSame( 'Now Playing', $children[0]['attrs']['leadLabel'] ?? null );
		$this->assertSame( 3, $children[1]['attrs']['headingLevel'] ?? null );

		$pagination = $this->of_type( $blocks, 'core/query-pagination' );
		$this->assertCount( 1, $pagination );
		$this->assertSame( 'arrow', $pagination[0]['attrs']['paginationArrow'] ?? '' );
		$this->assertSame( 'center', $pagination[0]['attrs']['layout']['justifyContent'] ?? '' );
		$parts = array_values( array_filter( $pagination[0]['innerBlocks'], static fn( $block ) => null !== $block['blockName'] ) );
		$this->assertSame( [ 'core/query-pagination-previous', 'core/query-pagination-numbers', 'core/query-pagination-next' ], array_column( $parts, 'blockName' ) );
		$this->assertArrayNotHasKey( 'label', $parts[0]['attrs'] ?? [] );
		$this->assertArrayNotHasKey( 'label', $parts[2]['attrs'] ?? [] );

		$no_results = $this->of_type( $blocks, 'core/query-no-results' );
		$this->assertCount( 1, $no_results );
		$this->assertSame( [ 'No jams yet.' ], array_map( static fn( $block ) => trim( wp_strip_all_tags( (string) $block['innerHTML'] ) ), $this->of_type( $blocks, 'core/paragraph' ) ) );
		$this->assertSame( [], array_values( array_filter( $blocks, static fn( $block ) => str_starts_with( (string) $block['blockName'], 'post-kinds-indieweb/jam-' ) ) ) );
	}

	public function test_template_drives_twelve_post_month_items_per_page(): void {
		$plan = Grouped_Archive::main_query_plan( new WP_Query(), 'jam', $this->template_content() );

		$this->assertIsArray( $plan );
		$this->assertSame( 12, $plan['per_page'] );
		$this->assertNotEmpty( $plan['vars'] );
		$this->assertSame( 'post_month', $plan['vars']['pkiw_group_source'] ?? '' );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function archive_zones(): array {
		return [
			'Chicago'  => [ 'America/Chicago' ],
			'New York' => [ 'America/New_York' ],
		];
	}

	/**
	 * @dataProvider archive_zones
	 */
	public function test_served_archive_has_a_lead_then_month_sections_and_listing_cards( string $zone ): void {
		update_option( 'timezone_string', $zone );
		$newest = $this->jam(
			'Boundary Jam',
			'2026-08-31 23:30:00',
			[
				'title'    => 'Hollow Light',
				'artist'   => 'Marlowe Kite',
				'jammedAt' => '2026-09-01T00:30',
			]
		);
		$this->jam( 'August Jam', '2026-08-15 10:00:00', [ 'title' => 'The Quiet Orchards', 'artist' => 'Marlowe Kite' ] );
		$this->jam( 'July Jam', '2026-07-20 10:00:00', [ 'title' => 'Signal Bloom', 'artist' => 'Marlowe Kite' ] );

		$html = $this->serve();

		$this->assertSame( 1, substr_count( $html, '<h1' ) );
		$this->assertStringContainsString( '<section class="pkiw-lead" data-pkiw-lead="1">', $html );
		$this->assertStringContainsString( '<h2 id="pkiw-lead" class="pkiw-lead__heading">Now Playing</h2>', $html );
		$this->assertTrue( strpos( $html, 'class="pkiw-lead"' ) < strpos( $html, 'class="pkiw-group"' ) );
		$this->assertSame( 1, substr_count( $html, '>August 2026</h2>' ) );
		$this->assertSame( 1, substr_count( $html, '>July 2026</h2>' ) );
		$this->assertStringNotContainsString( '>September 2026</h2>', $html, 'Sections use post_date, not jammedAt.' );
		$this->assertSame( 3, substr_count( $html, 'pk-card--jam-listing' ) );
		$this->assertSame( 1, substr_count( $html, '>Hollow Light</span>' ), 'The lead jam is held out of its month section.' );
		$this->assertStringNotContainsString( 'September 1, 2026', $html, 'Listing cards omit date text.' );
		$this->assertStringContainsString( (string) get_permalink( $newest ), $html );

		$facts = Jam_Archive::facts( $newest );
		$this->assertSame( '2026-09-01', $facts['date'] );
		$this->go_to( get_permalink( $newest ) );
		$GLOBALS['post'] = get_post( $newest ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$stream          = \PKIW\render_stream_card( [ 'headingLevel' => 3 ] );
		$this->assertStringContainsString( 'datetime="2026-09-01"', $stream );
		$this->assertStringContainsString( 'September 1, 2026', $stream );
	}

	public function test_protected_newest_jam_renders_title_only_in_the_lead(): void {
		$this->jam(
			'Protected Jam',
			'2026-09-04 10:00:00',
			[
				'title'  => 'Locked Track Probe',
				'artist' => 'Locked Artist Probe',
				'note'   => 'Locked Note Probe',
			],
			[ 'post_password' => 'secret' ]
		);
		$this->jam( 'Public Jam', '2026-09-03 10:00:00', [ 'title' => 'Hollow Light' ] );

		$html = $this->serve();
		preg_match( '#<section class="pkiw-lead".*?</section>#s', $html, $lead );
		$this->assertNotEmpty( $lead[0] ?? '' );
		$this->assertStringContainsString( 'pk-card--protected', $lead[0] );
		$this->assertStringContainsString( 'Protected: Protected Jam', $lead[0] );
		foreach ( [ 'Locked Track Probe', 'Locked Artist Probe', 'Locked Note Probe' ] as $secret ) {
			$this->assertStringNotContainsString( $secret, $lead[0] );
		}
	}
}
