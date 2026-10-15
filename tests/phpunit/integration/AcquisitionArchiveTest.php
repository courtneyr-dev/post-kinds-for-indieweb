<?php
/**
 * The acquisition archive: month sections and lean cards for issue 239.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * /kind/acquisition/ is served by the plugin's template and groups
 * acquisitions by the local month of post_date.
 *
 * @group integration
 */
final class AcquisitionArchiveTest extends WP_UnitTestCase {

	/**
	 * Stylesheet active before each test.
	 *
	 * @var string
	 */
	private string $original_stylesheet = '';

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'date_format', 'F j, Y' );
		update_option( 'posts_per_page', 20 );
		add_filter( 'pkiw_set_featured_from_artwork', '__return_false' );
		add_filter(
			'pre_http_request',
			static fn() => new WP_Error( 'blocked', 'No network in tests.' )
		);
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
	}

	public function tear_down(): void {
		switch_theme( $this->original_stylesheet );
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/**
	 * A serialized acquisition-card comment.
	 *
	 * @param array<string, mixed> $attrs Card attributes.
	 */
	private function card( array $attrs ): string {
		return '<!-- wp:post-kinds-indieweb/acquisition-card ' . serialize_block_attributes( $attrs ) . ' /-->';
	}

	/**
	 * A published acquisition.
	 *
	 * @param string                    $title Post title.
	 * @param string                    $date  Post date.
	 * @param array<string, mixed>|null $attrs Card attributes, or null for Quick Post shape.
	 * @param array<string, mixed>      $args  Post overrides.
	 */
	private function acquisition( string $title, string $date, ?array $attrs = [], array $args = [] ): int {
		$content = null === $attrs
			? '<!-- wp:paragraph --><p>' . esc_html( $title ) . ' notes.</p><!-- /wp:paragraph -->'
			: $this->card( array_merge( [ 'title' => $title ], $attrs ) );
		$id      = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_date'    => $date,
					'post_content' => wp_slash( $content ),
				],
				$args
			)
		);
		wp_set_object_terms( $id, 'acquisition', Taxonomy::TAXONOMY );
		if ( null === $attrs ) {
			update_post_meta( $id, '_pkiw_acquisition_name', $title );
		}

		return $id;
	}

	/**
	 * The archive URL.
	 */
	private function archive_url(): string {
		$url = get_term_link( 'acquisition', Taxonomy::TAXONOMY );
		$this->assertSame( 'http://example.org/kind/acquisition/', $url );

		return $url;
	}

	/**
	 * Visit a URL and render the block template it resolves to.
	 *
	 * @param string $url URL.
	 */
	private function serve( string $url ): string {
		$this->go_to( $url );
		$template = resolve_block_template( 'taxonomy', [ 'taxonomy-kind-acquisition.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ], '' );
		$this->assertNotNull( $template );

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;

		return get_the_block_template_html();
	}

	/**
	 * Parse rendered HTML.
	 *
	 * @param string $html HTML.
	 */
	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();

		return new DOMXPath( $dom );
	}

	/**
	 * Month sections and item titles.
	 *
	 * @param string $html Rendered page.
	 * @return array<int, array{heading:string,titles:string[]}>
	 */
	private function sections( string $html ): array {
		$xpath = $this->xpath( $html );
		$out   = [];
		foreach ( $xpath->query( '//section[@data-pkiw-sections]' ) as $section ) {
			$heading = $xpath->query( './*[1]', $section )->item( 0 );
			$titles  = [];
			foreach ( $xpath->query( './/li//*[contains(concat(" ", normalize-space(@class), " "), " pk-title ")]', $section ) as $title ) {
				$titles[] = trim( (string) preg_replace( '/\s+/u', ' ', $title->textContent ) );
			}
			$out[] = [
				'heading' => $heading instanceof DOMElement ? $heading->nodeName . ':' . trim( $heading->textContent ) : '',
				'titles'  => $titles,
			];
		}

		return $out;
	}

	/**
	 * Create the 26-post fixture.
	 *
	 * @return array<string, int>
	 */
	private function fixtures(): array {
		$ids = [];
		foreach (
			[
				[ 'September Pin A', '2026-09-12 10:00:00', [] ],
				[ 'September Pin B', '2026-09-11 10:00:00', [] ],
				[ 'September Pin C', '2026-09-10 10:00:00', [] ],
				[ 'August Field A', '2026-08-28 10:00:00', [] ],
				[ 'August Field B', '2026-08-24 10:00:00', null ],
				[ 'August Field C', '2026-08-20 10:00:00', [] ],
				[ 'August Field D', '2026-08-16 10:00:00', [] ],
				[ 'August Field E', '2026-08-12 10:00:00', null ],
				[ 'July Field A', '2026-07-31 10:00:00', [] ],
				[ 'July Field B', '2026-07-26 10:00:00', [] ],
				[ 'July Field C', '2026-07-22 10:00:00', null ],
				[ 'July Field D', '2026-07-18 10:00:00', [] ],
				[ 'July Field E', '2026-07-14 10:00:00', [] ],
				[ 'July Field F', '2026-07-10 10:00:00', [] ],
				[ 'June Field A', '2026-06-15 10:00:00', [] ],
				[ 'May Field A', '2026-05-28 10:00:00', [] ],
				[ 'May Field B', '2026-05-20 10:00:00', [] ],
				[ 'May Field C', '2026-05-16 10:00:00', null ],
				[ 'May Field D', '2026-05-12 10:00:00', [] ],
				[ 'May Field E', '2026-05-08 10:00:00', [] ],
				[ 'May Field F', '2026-05-04 10:00:00', [] ],
				[ 'April Field A', '2026-04-20 10:00:00', [] ],
				[ 'April Field B', '2026-04-12 10:00:00', [] ],
				[ 'April Field C', '2026-04-04 10:00:00', [] ],
				[ 'March Field A', '2026-03-22 10:00:00', [] ],
				[ 'March Field B', '2026-03-10 10:00:00', [] ],
			] as [ $title, $date, $attrs ]
		) {
			$ids[ $title ] = $this->acquisition( $title, $date, $attrs );
		}

		return $ids;
	}

	public function test_month_sections_paginate_by_twelve_and_repeat_a_cross_page_month(): void {
		$this->fixtures();

		$page_1 = $this->sections( $this->serve( $this->archive_url() ) );
		$this->assertSame( [ 'h2:September 2026', 'h2:August 2026', 'h2:July 2026' ], array_column( $page_1, 'heading' ) );
		$this->assertSame( [ 3, 5, 4 ], array_map( 'count', array_column( $page_1, 'titles' ) ) );
		$this->assertSame( 26, $GLOBALS['wp_query']->found_posts );
		$this->assertSame( 12, $GLOBALS['wp_query']->post_count );
		$this->assertSame( 12, (int) $GLOBALS['wp_query']->get( 'posts_per_page' ) );
		$this->assertSame( 3, $GLOBALS['wp_query']->max_num_pages );

		$page_2 = $this->sections( $this->serve( $this->archive_url() . 'page/2/' ) );
		$this->assertSame( [ 'h2:July 2026', 'h2:June 2026', 'h2:May 2026', 'h2:April 2026' ], array_column( $page_2, 'heading' ) );
		$this->assertSame( [ 2, 1, 6, 3 ], array_map( 'count', array_column( $page_2, 'titles' ) ) );
		$this->assertSame( 26, $GLOBALS['wp_query']->found_posts );
		$this->assertSame( 12, $GLOBALS['wp_query']->post_count );

		$page_3_html = $this->serve( $this->archive_url() . 'page/3/' );
		$page_3      = $this->sections( $page_3_html );
		$this->assertSame( [ 'h2:March 2026' ], array_column( $page_3, 'heading' ) );
		$this->assertSame( [ 2 ], array_map( 'count', array_column( $page_3, 'titles' ) ) );
		$this->assertSame( 26, $GLOBALS['wp_query']->found_posts );
		$this->assertSame( 2, $GLOBALS['wp_query']->post_count );
		$this->assertStringNotContainsString( 'wp-block-query-pagination-next', $page_3_html );
	}

	public function test_late_new_york_post_files_under_its_local_month(): void {
		$this->acquisition( 'Late July Field', '2026-07-31 22:30:00', [] );

		$sections = $this->sections( $this->serve( $this->archive_url() ) );

		$this->assertSame( [ 'h2:July 2026' ], array_column( $sections, 'heading' ) );
		$this->assertSame( [ [ 'Late July Field' ] ], array_column( $sections, 'titles' ) );
	}

	public function test_item_titles_are_h3_inside_list_items(): void {
		$this->acquisition( 'Field Recorder', '2026-08-12 10:00:00', [] );
		$xpath = $this->xpath( $this->serve( $this->archive_url() ) );

		$this->assertSame( 1, $xpath->query( '//section[@data-pkiw-sections]//li//h3[contains(concat(" ", normalize-space(@class), " "), " pk-title ")]' )->length );
	}

	public function test_archive_pages_hide_cost_and_card_details_even_in_hidden_data(): void {
		$this->acquisition(
			'Attic Recorder',
			'2026-08-24 10:00:00',
			[
				'acquisitionType'  => 'gift',
				'where'            => 'Family Attic Zq7',
				'whereUrl'         => 'https://shop.example.com/attic-zq9',
				'notes'            => 'Dusty note Zq8',
				'acquiredAt'       => '2026-08-02',
				'cost'             => '$129.00',
				'showCostPublicly' => true,
			]
		);
		$this->acquisition( 'Second Cost', '2026-08-20 10:00:00', [ 'cost' => '$55.00 Zq6', 'showCostPublicly' => true ] );

		$html = $this->serve( $this->archive_url() );

		foreach ( [ 'Family Attic Zq7', 'attic-zq9', 'Dusty note Zq8', 'August 2, 2026', '2026-08-02', '129.00', '55.00 Zq6' ] as $missing ) {
			$this->assertStringNotContainsString( $missing, $html, $missing );
		}
		$xpath = $this->xpath( $html );
		$li    = $xpath->query( '//li[.//*[contains(., "Attic Recorder")]]' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $li );
		$this->assertStringNotContainsString( 'Gift', $li->textContent );
	}

	public function test_password_protected_acquisition_shows_only_its_title_link(): void {
		wp_set_current_user( 0 );
		$this->acquisition( 'Locked Acquisition', '2026-08-12 10:00:00', [ 'title' => 'Hidden Item', 'where' => 'Hidden Room Zq4' ], [ 'post_password' => 'secret' ] );

		$html = $this->serve( $this->archive_url() );

		$this->assertStringContainsString( 'Protected: Locked Acquisition', $html );
		$this->assertStringNotContainsString( 'Hidden Item', $html );
		$this->assertStringNotContainsString( 'Hidden Room Zq4', $html );
		$this->assertStringNotContainsString( '<img', $html );
	}

	public function test_private_and_draft_acquisitions_do_not_appear_logged_out(): void {
		wp_set_current_user( 0 );
		$this->acquisition( 'Public Field', '2026-08-12 10:00:00', [] );
		$this->acquisition( 'Private Field Zq5', '2026-08-11 10:00:00', [], [ 'post_status' => 'private' ] );
		$this->acquisition( 'Draft Field Zq6', '2026-08-10 10:00:00', [], [ 'post_status' => 'draft' ] );

		$html = $this->serve( $this->archive_url() );

		$this->assertSame( 1, $GLOBALS['wp_query']->found_posts );
		$this->assertStringContainsString( 'Public Field', $html );
		$this->assertStringNotContainsString( 'Private Field Zq5', $html );
		$this->assertStringNotContainsString( 'Draft Field Zq6', $html );
	}

	public function test_feed_uses_date_order_and_posts_per_rss_without_grouping(): void {
		$ids = $this->fixtures();
		update_option( 'posts_per_rss', 5 );

		$this->go_to( get_term_feed_link( get_term_by( 'slug', 'acquisition', Taxonomy::TAXONOMY )->term_id, Taxonomy::TAXONOMY ) );
		$this->assertTrue( is_feed() );

		$wp_query = $GLOBALS['wp_query'];
		$this->assertSame( '', (string) $wp_query->get( 'pkiw_group_source' ) );
		$this->assertSame( 5, $wp_query->post_count );
		$this->assertSame(
			array_slice( array_values( $ids ), 0, 5 ),
			wp_list_pluck( $wp_query->posts, 'ID' )
		);
	}

	public function test_empty_archive_prints_empty_text_without_sections_or_pagination(): void {
		$html  = $this->serve( $this->archive_url() );
		$xpath = $this->xpath( $html );

		$this->assertStringContainsString( 'No acquisitions yet.', $html );
		$this->assertSame( 0, $xpath->query( '//section[@data-pkiw-sections]' )->length );
		$this->assertSame( 0, $xpath->query( '//nav[contains(concat(" ", normalize-space(@class), " "), " wp-block-query-pagination ")]' )->length );
	}
}
