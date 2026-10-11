<?php
/**
 * Reply archive grouping by cited host (issue 253).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Grouped_Archive;
use PKIW\Taxonomy;

/**
 * Covers the reply archive's cite-host grouping contract through the existing
 * grouped archive engine and archive-sections marker. Names, titles and hosts
 * are invented.
 *
 * @group integration
 */
final class ReplyArchiveGroupingTest extends WP_UnitTestCase {

	private const BLOCK = 'post-kinds-indieweb/archive-sections';

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
		update_option( 'posts_per_page', 20 );
		( new Taxonomy() )->create_default_terms();
		( new \PKIW\Meta_Fields() )->register_meta_fields();
		( new \PKIW\Reply_Archive() )->register();
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		switch_theme( $this->original_stylesheet );
		parent::tear_down();
	}

	/**
	 * A published reply.
	 *
	 * @param string                $title    Title.
	 * @param string                $date     Post date.
	 * @param array<string, string> $meta     Meta key => value.
	 * @param string                $password Post password.
	 */
	private function reply( string $title, string $date, array $meta = [], string $password = '' ): int {
		$id = self::factory()->post->create(
			[
				'post_status'   => 'publish',
				'post_title'    => $title,
				'post_date'     => $date,
				'post_password' => $password,
				'post_content'  => '<!-- wp:paragraph --><p>Reply body.</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_object_terms( $id, 'reply', Taxonomy::TAXONOMY );
		foreach ( $meta as $key => $value ) {
			add_post_meta( $id, $key, $value );
		}

		return $id;
	}

	/**
	 * Replies in grouped archive order fixtures.
	 *
	 * @return array<string, int>
	 */
	private function replies(): array {
		return [
			'R01' => $this->reply( 'R01', '2026-09-14 10:00:00', [ '_pkiw_cite_url' => 'https://www.A11Y.example/notes' ] ),
			'R02' => $this->reply( 'R02', '2026-09-13 10:00:00', [ '_pkiw_cite_url' => 'https://a11y.example/alt' ] ),
			'R03' => $this->reply( 'R03', '2026-09-12 10:00:00', [ '_pkiw_cite_url' => 'https://WWW.a11y.example/more' ] ),
			'R04' => $this->reply( 'R04', '2026-09-11 10:00:00', [ '_pkiw_cite_url' => 'https://bird.example/thanks' ] ),
			'R05' => $this->reply( 'R05', '2026-09-10 10:00:00', [ '_pkiw_cite_url' => 'https://indieweb.example/t1' ] ),
			'R06' => $this->reply( 'R06', '2026-09-09 10:00:00', [ '_pkiw_cite_url' => 'https://indieweb.example/t2' ] ),
			'R07' => $this->reply( 'R07', '2026-09-08 10:00:00', [ '_pkiw_cite_url' => 'https://indieweb.example/t3' ] ),
			'R08' => $this->reply( 'R08', '2026-09-07 10:00:00', [ '_pkiw_cite_url' => 'https://indieweb.example/t4' ] ),
			'R09' => $this->reply( 'R09', '2026-09-06 10:00:00', [ '_pkiw_cite_url' => 'https://indieweb.example/t5' ] ),
			'R10' => $this->reply( 'R10', '2026-09-05 10:00:00', [ '_pkiw_cite_url' => 'https://indieweb.example/t6' ] ),
			'R11' => $this->reply( 'R11', '2026-09-04 10:00:00', [ '_pkiw_in_reply_to' => 'https://legacy.example/post/7' ] ),
			'R12' => $this->reply( 'R12', '2026-09-03 10:00:00', [ '_pkiw_cite_url' => 'https://zine.example/issue-1' ] ),
			'R13' => $this->reply( 'R13', '2026-09-02 10:00:00' ),
			'R14' => $this->reply( 'R14', '2026-09-01 10:00:00', [ '_pkiw_cite_url' => 'https://indieweb.example/t7' ], 'secret' ),
		];
	}

	/**
	 * An archive template: an inheriting Query Loop whose Post Template holds
	 * a title and the marker.
	 *
	 * @param array<string, mixed> $attrs Marker attributes.
	 */
	private function template( array $attrs = [] ): string {
		$attrs  = array_merge(
			[
				'linesPerPage' => 9,
				'emptyLabel'   => '',
			],
			$attrs
		);
		$marker = '<!-- wp:' . self::BLOCK . ' ' . wp_json_encode( $attrs ) . ' /-->';

		return '<!-- wp:query {"queryId":12,"query":{"inherit":true}} --><div class="wp-block-query"><!-- wp:post-template {"className":"is-style-pkiw-shelf"} --><!-- wp:post-title {"level":3,"isLink":true} /-->' . $marker . '<!-- /wp:post-template --></div><!-- /wp:query -->';
	}

	/**
	 * Serve /kind/reply/ from a template and render it.
	 *
	 * @param string $template Template content.
	 * @param int    $page     Page number.
	 */
	private function serve( string $template, int $page = 1 ): string {
		$swap = static function ( $templates ) use ( $template ) {
			foreach ( $templates as $found ) {
				if ( in_array( $found->slug, [ 'taxonomy-kind', 'taxonomy-kind-reply' ], true ) ) {
					$found->content = $template;
				}
			}
			return $templates;
		};
		add_filter( 'get_block_templates', $swap );
		$url = get_term_link( 'reply', 'kind' );
		$this->assertIsString( $url );
		$this->go_to( $page > 1 ? add_query_arg( 'paged', $page, $url ) : $url );
		$html = do_blocks( $template );
		remove_filter( 'get_block_templates', $swap );

		return $html;
	}

	/**
	 * Each section: its group key, heading tag and text, then post titles.
	 *
	 * @return array<int, array{group:string,heading:string,titles:string[]}>
	 */
	private function sections( string $html ): array {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();
		$xpath = new DOMXPath( $dom );
		$out   = [];
		foreach ( $xpath->query( '//section[@data-pkiw-sections]' ) as $section ) {
			$heading = $section->firstChild;
			$titles  = [];
			foreach ( $xpath->query( './ul/li//*[contains(@class, "wp-block-post-title")]', $section ) as $title ) {
				$titles[] = trim( $title->textContent );
			}
			$out[] = [
				'group'   => $section->hasAttribute( 'data-pkiw-group' ) ? $section->getAttribute( 'data-pkiw-group' ) : '(none)',
				'heading' => $heading instanceof DOMElement ? $heading->nodeName . ':' . trim( $heading->textContent ) : '',
				'titles'  => $titles,
			];
		}

		return $out;
	}

	/**
	 * The section heading text for a title in a rendered page.
	 *
	 * @param array<int, array{group:string,heading:string,titles:string[]}> $sections Rendered sections.
	 * @param string                                                         $title    Post title.
	 */
	private function heading_for_title( array $sections, string $title ): string {
		foreach ( $sections as $section ) {
			if ( in_array( $title, $section['titles'], true ) ) {
				return substr( $section['heading'], strlen( 'h2:' ) );
			}
		}

		return '';
	}

	public function test_hosts_run_a_to_z_with_other_last(): void {
		$this->replies();
		$template = $this->template();

		$page_1 = $this->sections( $this->serve( $template ) );
		$page_2 = $this->sections( $this->serve( $template, 2 ) );

		$this->assertSame( [ 'h2:a11y.example', 'h2:bird.example', 'h2:indieweb.example' ], array_column( $page_1, 'heading' ) );
		$this->assertSame( [ 'R01', 'R02', 'R03' ], $page_1[0]['titles'] );
		$this->assertSame( [ 'R04' ], $page_1[1]['titles'] );
		$this->assertSame( [ 'R05', 'R06', 'R07', 'R08', 'R09' ], $page_1[2]['titles'] );
		$this->assertSame( [ 'h2:indieweb.example', 'h2:legacy.example', 'h2:zine.example', 'h2:Other' ], array_column( $page_2, 'heading' ) );
	}

	public function test_www_and_case_variants_merge_into_one_host_section(): void {
		$ids      = $this->replies();
		$sections = $this->sections( $this->serve( $this->template() ) );
		$a11y     = array_values(
			array_filter(
				$sections,
				static fn( array $section ): bool => 'h2:a11y.example' === $section['heading']
			)
		);

		$this->assertCount( 1, $a11y );
		$this->assertSame( [ 'R01', 'R02', 'R03' ], $a11y[0]['titles'] );
		$this->assertSame( 'a11y.example', \PKIW\kind_facts( $ids['R01'] )['host'] );
		$this->assertSame( 'a11y.example', \PKIW\kind_facts( $ids['R03'] )['host'] );
	}

	public function test_the_heading_repeats_at_the_top_of_page_two(): void {
		$this->replies();
		$page_2 = $this->sections( $this->serve( $this->template(), 2 ) );

		$this->assertSame( 'h2:indieweb.example', $page_2[0]['heading'] );
		$this->assertSame( [ 'R10' ], $page_2[0]['titles'] );
	}

	public function test_nine_a_page_from_the_markers_lines_per_page_and_found_posts_matches_the_ungrouped_count(): void {
		$this->replies();
		$template = $this->template();
		$this->serve( $template );

		global $wp_query;
		$this->assertSame( 9, (int) $wp_query->get( 'posts_per_page' ) );
		$this->assertSame( 9, $wp_query->post_count );
		$this->assertSame( 14, (int) $wp_query->found_posts );
		$this->assertSame( 2, (int) $wp_query->max_num_pages );

		$ungrouped = new WP_Query(
			[
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'tax_query'      => [ [ 'taxonomy' => 'kind', 'field' => 'slug', 'terms' => 'reply' ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			]
		);
		$this->assertSame( (int) $ungrouped->found_posts, (int) $wp_query->found_posts );

		$this->serve( $template, 2 );
		$this->assertSame( 5, $wp_query->post_count );
	}

	public function test_a_protected_reply_files_under_other(): void {
		$ids      = $this->replies();
		$group    = Grouped_Archive::group_of_post( 'reply', $ids['R14'] );
		$sections = $this->sections( $this->serve( $this->template(), 2 ) );
		$other    = end( $sections );

		$this->assertTrue( $group->is_empty() );
		$this->assertSame( '', $group->key() );
		$this->assertSame( [ 'R13', 'Protected: R14' ], $other['titles'] );
		$this->assertStringContainsString( 'R14', end( $other['titles'] ) );
		foreach ( $sections as $section ) {
			if ( 'h2:indieweb.example' === $section['heading'] ) {
				$this->assertNotContains( 'Protected: R14', $section['titles'] );
			}
		}
	}

	public function test_a_replys_facts_host_is_the_heading_it_files_under(): void {
		$ids      = $this->replies();
		$template = $this->template();
		$page_1   = $this->sections( $this->serve( $template ) );
		$page_2   = $this->sections( $this->serve( $template, 2 ) );

		foreach ( [ 'R01', 'R04', 'R05' ] as $title ) {
			$this->assertSame( $this->heading_for_title( $page_1, $title ), \PKIW\kind_facts( $ids[ $title ] )['host'] );
		}
		$this->assertSame( $this->heading_for_title( $page_2, 'R11' ), \PKIW\kind_facts( $ids['R11'] )['host'] );
	}

	public function test_registering_the_reply_archive_adds_no_group_source(): void {
		$before = Grouped_Archive::source( 'cite_host' );

		( new \PKIW\Reply_Archive() )->register();

		$this->assertNotNull( $before );
		$this->assertSame( $before, Grouped_Archive::source( 'cite_host' ) );
	}
}
