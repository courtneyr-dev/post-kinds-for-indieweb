<?php
/**
 * The served quote archive: sections, page map, filters and privacy end to end.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Quote_Archive;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class QuoteArchivePageMapTest extends WP_UnitTestCase {

	private string $original_stylesheet = '';

	/** @var array<string, int> */
	private array $ids = [];

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		( new Taxonomy() )->create_default_terms();
		( new Quote_Archive() )->register();
		delete_transient( Quote_Archive::INDEX_TRANSIENT );
		wp_set_current_user( 0 );
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
	}

	public function tear_down(): void {
		switch_theme( $this->original_stylesheet );
		$this->set_permalink_structure( '' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function quote( string $key, string $author, string $work, string $date, array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Quote ' . $key,
					'post_date'    => $date,
					'post_content' => '<!-- wp:quote --><blockquote class="wp-block-quote"><p>Words of ' . $key . '.</p></blockquote><!-- /wp:quote -->',
				],
				$args
			)
		);
		wp_set_object_terms( $id, 'quote', Taxonomy::TAXONOMY );
		if ( '' !== $author ) {
			add_post_meta( $id, '_pkiw_cite_author', $author );
		}
		if ( '' !== $work ) {
			add_post_meta( $id, '_pkiw_cite_name', $work );
		}
		$this->ids[ $key ] = (int) $id;

		return (int) $id;
	}

	/** Nineteen visible quotes plus one password-protected control. */
	private function seed(): void {
		$day = 31;
		$map = [
			'Q01' => [ 'Devon Reed', 'A Map of Returning' ],
			'Q02' => [ 'Devon Reed', 'A Map of Returning' ],
			'Q03' => [ 'Devon Reed', '' ],
			'Q04' => [ 'Lena Park', 'Weather of Attention' ],
			'Q05' => [ 'Lena Park', 'Weather of Attention' ],
			'Q06' => [ 'Mira Sol', 'The Margins We Keep' ],
			'Q07' => [ 'Mira Sol', 'The Margins We Keep' ],
			'Q08' => [ 'Mira Sol', 'The Margins We Keep' ],
			'Q09' => [ 'Mira Sol', '' ],
			'Q10' => [ 'Rowan Ellis', 'Small Fires' ],
			'Q11' => [ 'Rowan Ellis', 'Small Fires' ],
			'Q12' => [ 'rowan ellis', 'Small Fires' ],
			'Q13' => [ 'Rowan Ellis', 'Small Fires' ],
			'Q14' => [ 'Rowan Ellis', 'The Margins We Keep' ],
			'Q15' => [ 'Rowan Ellis', '' ],
			'Q16' => [ 'Ysolde Lind', 'Northern Ledger' ],
			'Q17' => [ '', '' ],
			'Q18' => [ '', '' ],
			'Q19' => [ '', '' ],
		];
		foreach ( $map as $key => $pair ) {
			$this->quote( $key, $pair[0], $pair[1], sprintf( '2026-08-%02d 10:00:00', $day-- ) );
		}
		$this->quote( 'C04', 'Locked Example', 'Locked Work', '2026-06-01 10:00:00', [ 'post_password' => 'seed-pass' ] );
		$this->quote( 'C01', 'Hidden Example', 'Private Ledger', '2026-06-02 10:00:00', [ 'post_status' => 'private' ] );
		$this->quote( 'C02', 'Draft Example', 'Draft Ledger', '2026-06-03 10:00:00', [ 'post_status' => 'draft' ] );
	}

	private function archive_url(): string {
		return (string) get_term_link( 'quote', Taxonomy::TAXONOMY );
	}

	private function serve( string $url ): string {
		$this->go_to( $url );
		$template = resolve_block_template( 'taxonomy', [ 'taxonomy-kind-quote.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ], '' );
		$this->assertNotNull( $template );
		$this->assertSame( 'post-kinds-for-indieweb//taxonomy-kind-quote', $template->id );

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = (string) $template->content;

		return get_the_block_template_html();
	}

	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();

		return new DOMXPath( $dom );
	}

	/** @return array<int, array{heading:string, linked:bool, cards:int}> */
	private function sections( string $html ): array {
		$xpath = $this->xpath( $html );
		$out   = [];
		foreach ( $xpath->query( '//section[@data-pkiw-sections]' ) as $section ) {
			$heading = $xpath->query( './h3', $section )->item( 0 );
			$out[]   = [
				'heading' => $heading instanceof DOMElement ? trim( $heading->textContent ) : '',
				'linked'  => $heading instanceof DOMElement && $xpath->query( './a', $heading )->length > 0,
				'cards'   => $xpath->query( './/div[contains(@class,"pkiw-quote-entry ")]', $section )->length,
			];
		}

		return $out;
	}

	public function test_page_map_repeats_a_crossing_group_and_files_protected_quotes_under_other(): void {
		$this->seed();

		$one = $this->sections( $this->serve( $this->archive_url() ) );
		$this->assertSame( [ 'Devon Reed →', 'Lena Park →', 'Mira Sol →' ], array_column( $one, 'heading' ) );
		$this->assertSame( [ 3, 2, 3 ], array_column( $one, 'cards' ) );
		$this->assertSame( 8, (int) $GLOBALS['wp_query']->get( 'posts_per_page' ) );
		$this->assertSame( 20, $GLOBALS['wp_query']->found_posts );
		$this->assertSame( 3, $GLOBALS['wp_query']->max_num_pages );

		$two = $this->sections( $this->serve( $this->archive_url() . 'page/2/' ) );
		$this->assertSame( [ 'Mira Sol →', 'Rowan Ellis →', 'Ysolde Lind →' ], array_column( $two, 'heading' ) );
		$this->assertSame( [ 1, 6, 1 ], array_column( $two, 'cards' ) );
		$this->assertSame( [ true, true, true ], array_column( $two, 'linked' ) );

		$html  = $this->serve( $this->archive_url() . 'page/3/' );
		$three = $this->sections( $html );
		$this->assertSame( [ 'Other' ], array_column( $three, 'heading' ) );
		$this->assertSame( [ false ], array_column( $three, 'linked' ) );
		$this->assertSame( [ 4 ], array_column( $three, 'cards' ) );
		foreach ( [ 'Locked Example', 'Locked Work', 'Hidden Example', 'Private Ledger', 'Draft Example' ] as $absent ) {
			$this->assertStringNotContainsString( $absent, $html );
		}
		$this->assertStringContainsString( 'Protected: Quote C04', wp_strip_all_tags( $html ) );

		$this->serve( $this->archive_url() . 'page/4/' );
		$this->assertTrue( is_404() );
	}

	public function test_reading_order_is_authors_tabs_then_sections_then_works(): void {
		$this->seed();
		$html = $this->serve( $this->archive_url() );

		$by_author = strpos( $html, 'id="pkiw-quote-by-author"' );
		$section   = strpos( $html, 'class="pkiw-quote-section' );
		$by_work   = strpos( $html, 'id="pkiw-quote-by-work"' );
		$this->assertNotFalse( $by_author );
		$this->assertLessThan( $section, $by_author );
		$this->assertLessThan( $by_work, $section );

		$xpath = $this->xpath( $html );
		$tabs  = [];
		foreach ( $xpath->query( '//nav[contains(@class,"pkiw-quote-tabs")]//a' ) as $link ) {
			$tabs[] = trim( $link->textContent );
		}
		$this->assertSame( [ 'All', 'D', 'L', 'M', 'R', 'Y' ], $tabs );
		$this->assertSame( 1, $xpath->query( '//h2[@id="pkiw-quote-by-author"]' )->length );
		$this->assertSame( 1, $xpath->query( '//h2[@id="pkiw-quote-by-work"]' )->length );
		$this->assertStringContainsString( 'data-pkiw-works="6"', $html );
	}

	public function test_author_filter_lists_only_public_matches_and_a_locked_author_finds_nothing(): void {
		$this->seed();

		$html     = $this->serve( add_query_arg( 'pkiw_quote_author', rawurlencode( 'lena park' ), $this->archive_url() ) );
		$sections = $this->sections( $html );
		$this->assertSame( [ 'Lena Park →' ], array_column( $sections, 'heading' ) );
		$this->assertSame( [ 2 ], array_column( $sections, 'cards' ) );

		$html = $this->serve( add_query_arg( 'pkiw_quote_author', rawurlencode( 'Locked Example' ), $this->archive_url() ) );
		$this->assertSame( [], $this->sections( $html ) );
		$this->assertStringNotContainsString( 'Locked Example', $html );
		$this->assertStringNotContainsString( 'Quote C04', $html );
	}
}
