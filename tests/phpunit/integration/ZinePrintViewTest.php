<?php
/** Zine print-view tests. @package PKIW */
declare(strict_types=1);

use PKIW\Zine_Export;
use PKIW\Zine_Manifest;

require_once __DIR__ . '/ZineTestCase.php';

/** @group integration */
final class ZinePrintViewTest extends ZineTestCase {
	public function test_sixteen_page_manifest_keeps_dom_reading_order(): void {
		for ( $index = 1; $index <= 14; $index++ ) {
			$this->post( sprintf( 'Entry %02d', $index ), [ 'post_date' => sprintf( '2025-03-%02d 12:00:00', $index ) ] );
		}
		$manifest = $this->manifest( $this->archive_query( 16 ), 16 );
		$this->assertSame( range( 3, 16 ), array_column( $manifest['entries'], 'page' ) );
	}

	public function test_booklet_structure_has_one_h1_contents_and_slot_attributes(): void {
		$this->post( 'Printable entry' );
		$this->go_to( add_query_arg( 'pkiw_zine_pages', '8', get_term_link( $this->recipe ) ) );
		do_action( 'wp' );
		$html = Zine_Export::render_booklet();
		$this->assertSame( 1, substr_count( $html, '<h1 class="pkiw-zine__title">' ) );
		$this->assertStringContainsString( '<h2>Contents</h2>', $html );
		$this->assertSame( 8, substr_count( $html, 'data-pkiw-zine-page=' ) );
		$this->assertStringContainsString( 'Back to Recipe', $html );
	}

	public function test_robots_header_and_nocache_run_for_valid_and_invalid_zine_vars_only(): void {
		$headers = [];
		Zine_Export::override_header_sink_for_tests( static function ( string $line ) use ( &$headers ): void { $headers[] = $line; } );
		$spy = 0;
		$filter = static function ( array $values ) use ( &$spy ): array { ++$spy; return $values; };
		add_filter( 'nocache_headers', $filter );
		try {
			$this->go_to( add_query_arg( [ 'pkiw_zine_pages' => '8', 'pkiw_zine_from' => 'banana' ], get_term_link( $this->recipe ) ) );
			Zine_Export::send_privacy_headers();
			$this->assertSame( [ 'X-Robots-Tag: noindex' ], $headers );
			$this->assertGreaterThan( 0, $spy );
			$this->assertArrayHasKey( 'noindex', Zine_Export::robots( [] ) );
		} finally {
			remove_filter( 'nocache_headers', $filter );
		}
	}

	public function test_entry_renderer_skips_cross_post_blocks_and_forbidden_elements(): void {
		$this->post( 'Other title' );
		$this->post( 'Context entry', [ 'post_content' => '<!-- wp:latest-posts /--><!-- wp:html --><iframe src="https://qx7.example/"></iframe><script>alert(1)</script><!-- /wp:html -->' ] );
		$this->go_to( add_query_arg( 'pkiw_zine_pages', '8', get_term_link( $this->recipe ) ) );
		do_action( 'wp' );
		$html = Zine_Export::render_booklet();
		$this->assertStringNotContainsString( '<iframe', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertSame( 1, substr_count( $html, 'Other title' ), 'The other title appears in Contents only, not inside the entry body.' );
	}

	public function test_d1_private_visibility_changes_count_and_output_but_not_public_listing(): void {
		$id = $this->post( 'Private visibility' );
		add_post_meta( $id, PKIW\Public_Listing::meta_keys()[0], 'private' );
		Zine_Manifest::override_narrowed_visibility_for_tests( [] );
		$this->assertSame( 1, $this->manifest( $this->archive_query() )['total'] );
		Zine_Manifest::override_narrowed_visibility_for_tests( [ 'private' ] );
		$this->assertSame( 0, $this->manifest( $this->archive_query() )['total'] );
	}

	public function test_stream_query_vars_match_rendered_query_loop_with_empty_global(): void {
		$stream_ids = [];
		$outside_id = 0;
		for ( $index = 1; $index <= 12; $index++ ) {
			$id = $this->post( sprintf( 'Stream %02d', $index ), [ 'post_date' => sprintf( '2025-02-%02d 12:00:00', $index ) ] );
			if ( 12 !== $index ) {
				add_post_meta( $id, '_pkiw_surface', 'stream' );
				$stream_ids[] = $id;
			} else {
				$outside_id = $id;
			}
		}
		$content = '<!-- wp:post-kinds-indieweb/zine-export {"source":"stream"} /--><!-- wp:query {"queryId":1,"query":{"perPage":10,"postType":"post","order":"desc","orderBy":"date","inherit":false}} --><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --><!-- /wp:query -->';
		$page_id = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'stream', 'post_title' => 'Stream', 'post_content' => $content ] );
		$page     = get_post( $page_id );
		$this->assertInstanceOf( WP_Post::class, $page );
		$surface = static function ( array $vars ): array {
			if ( is_page( 'stream' ) ) {
				$vars['meta_query'][] = [ 'key' => '_pkiw_surface', 'value' => 'stream' ]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The fixture proves page-conditioned Query Loop parity.
			}
			return $vars;
		};
		add_filter( 'query_loop_block_query_vars', $surface );
		try {
			$this->go_to( get_permalink( $page_id ) );
			$query_block = array_values( array_filter( parse_blocks( $content ), static fn( array $block ): bool => 'core/query' === $block['blockName'] ) )[0];
			$served_ids  = self::post_ids_from_html( render_block( $query_block ) );
			$front_vars  = Zine_Manifest::stream_query_vars( $page );
			$this->assertIsArray( $front_vars );
			$this->assertSame( $served_ids, self::query_ids( $front_vars ) );
			$this->assertNotContains( $outside_id, $served_ids, 'The non-stream fixture must be excluded so parity is not vacuous.' );
			$full_vars                   = $front_vars;
			$full_vars['posts_per_page'] = -1;
			$oracle_ids                  = self::query_ids( $full_vars );

			$prior               = $GLOBALS['wp_query'];
			$GLOBALS['wp_query'] = new WP_Query(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- This is the frozen WP-CLI/MCP context control.
			try {
				$empty_vars = Zine_Manifest::stream_query_vars( $page );
			} finally {
				$GLOBALS['wp_query'] = $prior; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the front-end request.
			}
			$this->assertIsArray( $empty_vars );
			$this->assertSame( $served_ids, self::query_ids( $empty_vars ) );

			foreach ( [ 8 => 6, 16 => 11 ] as $pages => $expected ) {
				$manifest = Zine_Manifest::build( [ 'source' => 'stream', 'page_id' => $page_id, 'pages' => $pages ] );
				$this->assertIsArray( $manifest );
				$this->assertSame( $expected, count( $manifest['included'] ) );
				$this->assertSame( 11, $manifest['total'] );
				$this->assertSame( array_slice( $oracle_ids, 0, 8 === $pages ? 6 : 14 ), $manifest['included'] );
			}
		} finally {
			remove_filter( 'query_loop_block_query_vars', $surface );
		}
	}

	/** Return post IDs in one rendered Post Template. */
	private static function post_ids_from_html( string $html ): array {
		$ids  = [];
		$tags = new WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag() ) {
			$classes = $tags->get_attribute( 'class' );
			if ( ! is_string( $classes ) ) {
				continue;
			}
			foreach ( explode( ' ', $classes ) as $class ) {
				if ( str_starts_with( $class, 'post-' ) && ctype_digit( substr( $class, 5 ) ) ) {
					$ids[] = (int) substr( $class, 5 );
				}
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/** Run one Query Loop variable set and return ordered IDs. */
	private static function query_ids( array $vars ): array {
		$query = new WP_Query( $vars );
		return array_map( 'intval', wp_list_pluck( $query->posts, 'ID' ) );
	}
}
