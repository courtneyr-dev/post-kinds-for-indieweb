<?php
/**
 * Drawn comic series grouping (issue 266).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Comic_Strip;
use PKIW\Grouped_Archive;
use PKIW\Kind_Archive_Layouts;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class ComicSeriesGroupingTest extends WP_UnitTestCase {

	private string $original_stylesheet = '';

	private int $original_posts_per_page = 0;

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet     = get_stylesheet();
		$this->original_posts_per_page = (int) get_option( 'posts_per_page' );
		switch_theme( 'twentytwentyfive' );
		update_option( 'posts_per_page', 20 );
		( new Meta_Fields() )->register_meta_fields();
		( new Taxonomy() )->create_default_terms();
		( new Comic_Strip() )->register();
		Comic_Strip::reset_series_cache();
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		update_option( 'posts_per_page', $this->original_posts_per_page );
		switch_theme( $this->original_stylesheet );
		$this->set_permalink_structure( '' );
		wp_set_current_user( 0 );
		wp_reset_postdata();
		parent::tear_down();
	}

	private function gallery( int $attachment_id ): string {
		return '<!-- wp:gallery --><figure class="wp-block-gallery has-nested-images"><!-- wp:image {"id":' . $attachment_id . '} --><figure class="wp-block-image"><img src="https://example.org/' . $attachment_id . '.jpg" alt="Panel" /><figcaption class="wp-element-caption">Transcript</figcaption></figure><!-- /wp:image --></figure><!-- /wp:gallery -->';
	}

	private function strip( string $title, string $series, string $date ): int {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_date'    => $date,
				'post_content' => $this->gallery( self::factory()->attachment->create() ),
			]
		);
		wp_set_object_terms( $id, Comic_Strip::KIND, Taxonomy::TAXONOMY );
		if ( '' !== $series ) {
			update_post_meta( $id, Comic_Strip::SERIES_META, $series );
		}
		update_post_meta( $id, Comic_Strip::ISSUE_META, '1' );
		Comic_Strip::reset_series_cache();

		return (int) $id;
	}

	private function archive_url(): string {
		return (string) get_term_link( Comic_Strip::KIND, Taxonomy::TAXONOMY );
	}

	private function drawn_url(): string {
		return add_query_arg( Comic_Strip::VIEW_VAR, Comic_Strip::VIEW_DRAWN, $this->archive_url() );
	}

	/**
	 * Serve the resolved plugin template, optionally changing only marker attributes.
	 *
	 * @param array<string, mixed> $marker_attrs Marker attribute override.
	 */
	private function serve( array $marker_attrs = [] ): string {
		$this->go_to( $this->drawn_url() );
		$hierarchy = apply_filters(
			'taxonomy_template_hierarchy',
			[
				'taxonomy-kind.php',
				'taxonomy.php',
				'archive.php',
				'index.php',
			]
		);
		$this->assertSame( 'taxonomy-kind-comics-drawn.php', $hierarchy[0] );
		$template = resolve_block_template( 'taxonomy', $hierarchy, '' );
		$this->assertNotNull( $template );
		$this->assertSame( 'taxonomy-kind-comics-drawn', $template->slug );

		if ( [] !== $marker_attrs ) {
			$template->content = str_replace(
				'<!-- wp:post-kinds-indieweb/archive-sections {"linesPerPage":12,"headingLevel":2} /-->',
				'<!-- wp:post-kinds-indieweb/archive-sections ' . wp_json_encode( array_merge( [ 'linesPerPage' => 12, 'headingLevel' => 2 ], $marker_attrs ) ) . ' /-->',
				$template->content
			);
		}

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;

		return get_the_block_template_html();
	}

	/**
	 * Section headings and item titles.
	 *
	 * @return array<int, array{0:string,1:string[]}>
	 */
	private function sections( string $html ): array {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();
		$xpath = new DOMXPath( $dom );
		$out   = [];
		foreach ( $xpath->query( '//section[@data-pkiw-sections]' ) as $section ) {
			$heading = $xpath->query( './h2', $section )->item( 0 );
			$titles  = [];
			foreach ( $xpath->query( './/*[contains(concat(" ",@class," ")," wp-block-post-title ")]', $section ) as $title ) {
				$titles[] = trim( $title->textContent );
			}
			$out[] = [ $heading instanceof DOMElement ? trim( $heading->textContent ) : '', $titles ];
		}

		return $out;
	}

	private function seed_groups(): void {
		$this->strip( 'Field old', 'Field Notes', '2026-10-01 10:00:00' );
		$this->strip( 'Field new', 'Field Notes', '2026-10-04 10:00:00' );
		$this->strip( 'Small', 'Small Web Stories', '2026-10-03 10:00:00' );
		$this->strip( 'Standalone', '', '2026-10-02 10:00:00' );
	}

	public function test_real_drawn_template_groups_series_ascending_with_empty_last(): void {
		$this->seed_groups();

		$this->assertSame(
			[
				[ 'Field Notes', [ 'Field new', 'Field old' ] ],
				[ 'Small Web Stories', [ 'Small' ] ],
				[ 'Comics', [ 'Standalone' ] ],
			],
			$this->sections( $this->serve() )
		);
	}

	public function test_marker_empty_label_overrides_the_term_name(): void {
		$this->seed_groups();
		$headings = array_column( $this->sections( $this->serve( [ 'emptyLabel' => 'Standalone strips' ] ) ), 0 );

		$this->assertSame( [ 'Field Notes', 'Small Web Stories', 'Standalone strips' ], $headings );
		$this->assertNotContains( 'Comics', $headings );
	}

	public function test_plain_comics_archive_builds_no_sections_or_group_source(): void {
		$this->seed_groups();
		$this->go_to( $this->archive_url() );
		$html = do_blocks( (string) file_get_contents( PKIW_PATH . 'templates/taxonomy-kind-comics-drawn.html' ) );

		$this->assertSame( [], $this->sections( $html ) );
		$this->assertSame( '', (string) $GLOBALS['wp_query']->get( 'pkiw_group_source' ) );
		$this->assertNull( Kind_Archive_Layouts::filtered_view() );
	}

	public function test_series_source_has_no_kind_default_and_legacy_defaults_are_unchanged(): void {
		$this->assertNotNull( Grouped_Archive::source( Comic_Strip::SOURCE ) );
		$this->assertNotContains( Comic_Strip::KIND, Grouped_Archive::grouped_kinds() );
		$this->assertSame(
			[
				'eat'   => '_pkiw_eat_cuisine',
				'drink' => '_pkiw_drink_type',
			],
			Kind_Archive_Layouts::DEFAULT_GROUP_FIELDS
		);
	}

	public function test_group_source_selects_the_series_source_only_for_the_drawn_view(): void {
		$plain = new WP_Query();
		$drawn = new WP_Query();
		$drawn->set( Comic_Strip::VIEW_VAR, Comic_Strip::VIEW_DRAWN );

		$this->assertNull( apply_filters( 'pkiw_archive_group_source', null, Comic_Strip::KIND, $plain ) );
		$this->assertSame( Comic_Strip::SOURCE, apply_filters( 'pkiw_archive_group_source', null, Comic_Strip::KIND, $drawn ) );
		$this->assertSame( 'other', apply_filters( 'pkiw_archive_group_source', 'other', 'listen', $drawn ) );
		$this->assertNull( apply_filters( 'pkiw_archive_group_source', null, Comic_Strip::KIND, null ) );
	}
}
