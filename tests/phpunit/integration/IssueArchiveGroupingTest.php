<?php
/**
 * Repository-grouped issue archive and its two-page fixture.
 *
 * @package PKIW
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/fixtures/issue-render-state-reset.php';

use PKIW\Issue_Archive;
use PKIW\Meta_Fields;
use PKIW\Plugin;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class IssueArchiveGroupingTest extends WP_UnitTestCase {

	use Issue_Render_State_Reset;

	/** @var array<string, int> */
	private array $ids = [];
	private string $original_stylesheet = '';

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		( new Issue_Archive() )->register();
		update_option( 'posts_per_page', 20 );
		add_filter( 'pre_http_request', static fn() => new WP_Error( 'blocked', 'No network in tests.' ) );
		wp_set_current_user( 0 );
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
	}

	public function tear_down(): void {
		$this->reset_render_state();
		$this->set_permalink_structure( '' );
		wp_set_current_user( 0 );
		switch_theme( $this->original_stylesheet );
		parent::tear_down();
	}

	/** @param array<string, string> $meta */
	private function ticket( string $key, string $title, string $date, array $meta = [], array $args = [], string $kind = 'issue' ): int {
		$post_id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_date'    => $date,
					'post_content' => '<p>Ticket ' . esc_html( $key ) . '.</p>',
				],
				$args
			)
		);
		wp_set_object_terms( $post_id, $kind, Taxonomy::TAXONOMY );
		foreach ( $meta as $name => $value ) {
			update_post_meta( $post_id, Meta_Fields::PREFIX . $name, $value );
		}
		$this->ids[ $key ] = (int) $post_id;

		return (int) $post_id;
	}

	private function seed(): void {
		$repos = [
			'R1' => [ 'https://a.example.net/acorn-ledger', 'acorn-ledger' ],
			'R2' => [ 'https://b.example.org/birch-notes', 'birch-notes' ],
			'R3' => [ 'https://c.example.com/cedar-kit-with-an-extraordinarily-long-repository-name', 'cedar-kit-with-an-extraordinarily-long-repository-name' ],
		];
		for ( $at = 1; $at <= 9; $at++ ) {
			$key    = sprintf( 'A%02d', $at );
			$status = $at <= 5 ? 'open' : ( $at <= 8 ? 'closed' : '' );
			$meta   = [ 'cite_url' => $repos['R1'][0], 'cite_name' => $repos['R1'][1], 'issue_number' => (string) ( 100 + $at ) ];
			if ( '' !== $status ) {
				$meta['issue_status'] = $status;
			}
			$this->ticket( $key, 2 === $at ? 'Export drops the last row' : $key, sprintf( '2026-07-%02d 10:00:00', 25 - $at ), $meta );
		}
		for ( $at = 1; $at <= 8; $at++ ) {
			$key  = sprintf( 'B%02d', $at );
			$meta = [
				'cite_url'     => $repos['R2'][0],
				'cite_name'    => $repos['R2'][1],
				'issue_number' => (string) ( 20 + $at ),
				'issue_status' => $at <= 4 ? 'open' : 'closed',
			];
			$this->ticket( $key, 2 === $at ? 'Export drops the last row' : $key, sprintf( '2026-07-%02d 11:00:00', 17 - $at ), $meta );
		}
		for ( $at = 1; $at <= 3; $at++ ) {
			$key = sprintf( 'C%02d', $at );
			$this->ticket(
				$key,
				$key,
				sprintf( '2026-07-%02d 12:00:00', 8 - $at ),
				[ 'cite_url' => $repos['R3'][0], 'cite_name' => $repos['R3'][1], 'issue_number' => (string) ( 2 + $at ), 'issue_status' => 'closed' ]
			);
		}
		$this->ticket( 'N01', 'N01', '2026-07-04 10:00:00', [ 'issue_number' => '7', 'issue_status' => 'open' ] );
		$this->ticket( 'N02', 'N02', '2026-07-03 10:00:00' );
		$this->ticket( 'L01', 'L01', '2026-07-02 10:00:00', [], [ 'post_content' => '<p>Issue for <a class="u-in-reply-to" href="https://a.example.net/acorn-ledger">repo</a>.</p>' ] );
		$this->ticket( 'X01', 'X01 protected', '2026-07-01 10:00:00', [ 'cite_url' => $repos['R1'][0], 'cite_name' => $repos['R1'][1], 'issue_number' => '110', 'issue_status' => 'open' ], [ 'post_password' => 'ticket-secret' ] );
		$this->ticket( 'P01', 'P01 private', '2026-06-03 10:00:00', [], [ 'post_status' => 'private' ] );
		$this->ticket( 'D01', 'D01 draft', '2026-06-02 10:00:00', [], [ 'post_status' => 'draft' ] );
		$this->ticket( 'K01', 'K01 note', '2026-06-01 10:00:00', [ 'cite_url' => $repos['R1'][0], 'issue_status' => 'open' ], [], 'note' );
	}

	private function archive_url(): string {
		$url = get_term_link( 'issue', Taxonomy::TAXONOMY );
		$this->assertIsString( $url );

		return $url;
	}

	/** @param array<string, mixed> $attributes */
	private function serve( string $url, array $attributes = [] ): string {
		$swap = null;
		if ( [] !== $attributes ) {
			$swap = static function ( array $templates ) use ( $attributes ): array {
				foreach ( $templates as $template ) {
					if ( 'taxonomy-kind-issue' === $template->slug ) {
						$template->content = str_replace(
							'<!-- wp:post-kinds-indieweb/archive-sections {"linesPerPage":12,"headingLevel":2} /-->',
							'<!-- wp:post-kinds-indieweb/archive-sections ' . wp_json_encode( array_merge( [ 'linesPerPage' => 12, 'headingLevel' => 2 ], $attributes ) ) . ' /-->',
							(string) $template->content
						);
					}
				}
				return $templates;
			};
			add_filter( 'get_block_templates', $swap );
		}

		$this->go_to( $url );
		$template = resolve_block_template( 'taxonomy', [ 'taxonomy-kind-issue.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ], '' );
		if ( null !== $swap ) {
			remove_filter( 'get_block_templates', $swap );
		}
		$this->assertNotNull( $template );
		$this->assertSame( 'post-kinds-for-indieweb//taxonomy-kind-issue', $template->id );
		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;

		return get_the_block_template_html();
	}

	/** @return string[] */
	private function rack_headings( string $html ): array {
		$xpath = $this->xpath( $html );
		$out   = [];
		foreach ( $xpath->query( '//section[contains(concat(" ", normalize-space(@class), " "), " pkiw-group ")]/*[1]' ) as $heading ) {
			$out[] = trim( $heading->textContent );
		}
		return $out;
	}

	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();

		return new DOMXPath( $dom );
	}

	/** @return array<int, array<string, mixed>> */
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

	public function test_template_definition_and_block_contract_are_shipped(): void {
		$method      = new ReflectionMethod( Plugin::class, 'get_plugin_template_definitions' );
		$definitions = $method->invoke( Plugin::get_instance() );
		$this->assertSame( 'Issue Archive', $definitions['taxonomy-kind-issue']['title'] );

		$blocks  = $this->flatten( parse_blocks( (string) file_get_contents( PKIW_PATH . 'templates/taxonomy-kind-issue.html' ) ) );
		$queries = array_values( array_filter( $blocks, static fn( array $block ): bool => 'core/query' === $block['blockName'] ) );
		$this->assertCount( 1, $queries );
		$this->assertTrue( $queries[0]['attrs']['query']['inherit'] );
		$sections = array_values( array_filter( $blocks, static fn( array $block ): bool => 'post-kinds-indieweb/archive-sections' === $block['blockName'] ) );
		$stream   = array_values( array_filter( $blocks, static fn( array $block ): bool => 'post-kinds-indieweb/stream-card' === $block['blockName'] ) );
		$this->assertSame( 12, $sections[0]['attrs']['linesPerPage'] );
		$this->assertSame( 2, $sections[0]['attrs']['headingLevel'] );
		$this->assertSame( 4, $stream[0]['attrs']['headingLevel'] );
		foreach ( [ 'core/query-pagination', 'core/query-pagination-previous', 'core/query-pagination-numbers', 'core/query-pagination-next', 'core/query-no-results' ] as $name ) {
			$this->assertNotEmpty( array_filter( $blocks, static fn( array $block ): bool => $name === $block['blockName'] ), $name );
		}
		$this->assertStringContainsString( '<p>No issues yet.</p>', (string) file_get_contents( PKIW_PATH . 'templates/taxonomy-kind-issue.html' ) );
	}

	public function test_two_archive_pages_follow_repository_status_date_and_id_order(): void {
		$this->seed();
		$page_one = $this->serve( $this->archive_url() );
		$this->assertSame( 12, $GLOBALS['wp_query']->post_count );
		$this->assertSame( 24, $GLOBALS['wp_query']->found_posts );
		$this->assertSame( 2, $GLOBALS['wp_query']->max_num_pages );
		$this->assertSame( array_values( array_intersect_key( $this->ids, array_flip( [ 'A01', 'A02', 'A03', 'A04', 'A05', 'A06', 'A07', 'A08', 'A09', 'B01', 'B02', 'B03' ] ) ) ), wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );
		$this->assertSame( [ 'acorn-ledger', 'birch-notes' ], $this->rack_headings( $page_one ) );

		$page_two = $this->serve( $this->archive_url() . 'page/2/' );
		$expected = array_map( fn( string $key ): int => $this->ids[ $key ], [ 'B04', 'B05', 'B06', 'B07', 'B08', 'C01', 'C02', 'C03', 'N01', 'N02', 'L01', 'X01' ] );
		$this->assertSame( $expected, wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );
		$this->assertSame( [ 'birch-notes', 'cedar-kit-with-an-extraordinarily-long-repository-name', 'Other' ], $this->rack_headings( $page_two ) );
		$this->assertStringContainsString( 'X01 protected', $page_two );
		$this->assertStringNotContainsString( '110', $page_two );
		$this->assertStringNotContainsString( 'acorn-ledger', $page_two );
		$this->assertStringNotContainsString( 'P01 private', $page_one . $page_two );
		$this->assertStringNotContainsString( 'D01 draft', $page_one . $page_two );
		$this->assertStringNotContainsString( 'K01 note', $page_one . $page_two );
		foreach ( [ 'geo', 'p-location', 'h-adr', 'p-geo' ] as $private ) {
			$this->assertStringNotContainsString( $private, $page_one . $page_two );
		}
	}

	public function test_empty_rack_label_can_be_issue_and_page_three_is_a_404(): void {
		$this->seed();
		$html = $this->serve( $this->archive_url() . 'page/2/', [ 'emptyLabel' => 'Issue' ] );
		$this->assertContains( 'Issue', $this->rack_headings( $html ) );
		$this->serve( $this->archive_url() . 'page/3/' );
		$this->assertTrue( is_404() );
	}

	public function test_served_archive_lanes_use_custom_labels_heading_levels_and_privacy_buckets(): void {
		$this->seed();
		$page_one = $this->serve(
			$this->archive_url(),
			[
				'headingLevel' => 3,
				'groupLabels' => [ 'open' => 'Waiting — Open', 'closed' => 'Picked up — Closed' ],
				'emptyLabel'  => 'No workflow',
			]
		);
		$xpath = $this->xpath( $page_one );
		$this->assertSame( 2, $xpath->query( '//h4[normalize-space(.)="Waiting — Open"]' )->length );
		$this->assertSame( 1, $xpath->query( '//h4[normalize-space(.)="Picked up — Closed"]' )->length );
		$this->assertSame( 1, $xpath->query( '//h4[normalize-space(.)="No workflow"]' )->length );

		$level_five = $this->xpath( $this->serve( $this->archive_url(), [ 'headingLevel' => 4 ] ) );
		$this->assertGreaterThan( 0, $level_five->query( '//h5[contains(@class, "pkiw-issue-lane__heading")]' )->length );

		$page_two = $this->xpath( $this->serve( $this->archive_url() . 'page/2/' ) );
		$long     = 'cedar-kit-with-an-extraordinarily-long-repository-name';
		$this->assertSame( 0, $page_two->query( '//section[*[1][normalize-space(.)="' . $long . '"]]//*[@data-pkiw-status="open"]' )->length );
		$this->assertSame( 1, $page_two->query( '//section[*[1][normalize-space(.)="' . $long . '"]]//*[@data-pkiw-status="closed"]' )->length );
		$this->assertSame( 1, $page_two->query( '//section[*[1][normalize-space(.)="Other"]]//*[@data-pkiw-status="open"]' )->length );
		$this->assertSame( 1, $page_two->query( '//section[*[1][normalize-space(.)="Other"]]//*[@data-pkiw-status="none"][contains(., "X01 protected")]' )->length );
	}
}
