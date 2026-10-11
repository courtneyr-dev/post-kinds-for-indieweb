<?php
/**
 * The like archive host groups (issue 254).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Grouped_Archive;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * Covers the like archive's host grouping through the archive-sections marker
 * and the REST posts route. The like lane supplies facts only; the shared
 * cite_host source and archive engine own the grouping.
 *
 * @group integration
 */
final class LikeArchiveGroupingTest extends WP_UnitTestCase {

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
		( new Meta_Fields() )->register_meta_fields();
		add_filter(
			'pre_http_request',
			static function () {
				return new WP_Error( 'blocked', 'No network in tests.' );
			}
		);
		( new \PKIW\Like_Archive() )->register();
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		switch_theme( $this->original_stylesheet );
		parent::tear_down();
	}

	/**
	 * A published like.
	 *
	 * @param string               $title Title.
	 * @param string               $date  Post date.
	 * @param string|null          $url   Cited URL, or null for no URL row.
	 * @param array<string, mixed> $args  Post args.
	 */
	private function like( string $title, string $date, ?string $url = null, array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status' => 'publish',
					'post_title'  => $title,
					'post_date'   => $date,
				],
				$args
			)
		);
		wp_set_object_terms( $id, 'like', Taxonomy::TAXONOMY );
		if ( null !== $url ) {
			add_post_meta( $id, Meta_Fields::PREFIX . 'cite_url', $url );
		}

		return $id;
	}

	/**
	 * An inheriting Query Loop whose Post Template holds a title and marker.
	 *
	 * @param array<string, mixed> $marker_attrs Marker attributes.
	 */
	private function template( array $marker_attrs = [] ): string {
		$marker = '<!-- wp:' . self::BLOCK . ( $marker_attrs ? ' ' . wp_json_encode( $marker_attrs ) : '' ) . ' /-->';

		return '<!-- wp:query {"queryId":12,"query":{"inherit":true}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title {"level":3} /-->' . $marker . '<!-- /wp:post-template --></div><!-- /wp:query -->';
	}

	/**
	 * Serve /kind/like/ from a swapped block template and render it.
	 *
	 * @param string $template Template content.
	 * @param int    $page     Page number.
	 */
	private function serve( string $template, int $page = 1 ): string {
		$swap = static function ( $templates ) use ( $template ) {
			foreach ( $templates as $found ) {
				if ( in_array( $found->slug, [ 'taxonomy-kind', 'taxonomy-kind-like' ], true ) ) {
					$found->content = $template;
				}
			}
			return $templates;
		};
		add_filter( 'get_block_templates', $swap );
		$url = get_term_link( 'like', Taxonomy::TAXONOMY );
		$this->assertIsString( $url );
		$this->go_to( $page > 1 ? add_query_arg( 'paged', $page, $url ) : $url );
		$html = do_blocks( $template );
		remove_filter( 'get_block_templates', $swap );

		return $html;
	}

	/**
	 * Each section: heading text and item titles.
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
			$heading = $section->firstChild;
			$titles  = [];
			foreach ( $xpath->query( './ul/li//*[contains(@class, "wp-block-post-title")]', $section ) as $title ) {
				$titles[] = trim( $title->textContent );
			}
			$out[] = [
				$heading instanceof DOMElement ? $heading->nodeName . ':' . trim( $heading->textContent ) : '',
				$titles,
			];
		}

		return $out;
	}

	/**
	 * Count rendered post titles inside archive sections.
	 */
	private function section_title_count( string $html ): int {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();
		$xpath = new DOMXPath( $dom );

		return $xpath->query( '//section[@data-pkiw-sections]/ul/li//*[contains(@class, "wp-block-post-title")]' )->length;
	}

	/**
	 * Post IDs the REST posts route returns for likes in grouped order.
	 *
	 * @return int[]
	 */
	private function rest_ids(): array {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$term = get_term_by( 'slug', 'like', Taxonomy::TAXONOMY );
		$this->assertInstanceOf( WP_Term::class, $term );
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$request->set_param( 'kind', [ $term->term_id ] );
		$request->set_param( 'orderby', 'pkiw_group' );
		$request->set_param( 'per_page', 20 );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		return array_map( 'intval', wp_list_pluck( $response->get_data(), 'id' ) );
	}

	public function test_like_archive_registers_no_group_source(): void {
		$source = Grouped_Archive::source( 'cite_host' );

		$this->assertNotNull( $source );
		( new \PKIW\Like_Archive() )->register();
		$this->assertSame( $source, Grouped_Archive::source( 'cite_host' ) );
	}

	public function test_hosts_section_ascending_in_lowercase_with_newest_first(): void {
		$this->like( 'Zine one', '2026-08-01 10:00:00', 'https://zine.example.org/1' );
		$this->like( 'Notes old', '2026-08-02 10:00:00', 'https://Notes.Example.com/a' );
		$this->like( 'Blog', '2026-08-03 10:00:00', 'https://blog.example.com/p' );
		$this->like( 'Notes new', '2026-08-04 10:00:00', 'https://notes.example.com/b' );

		$this->assertSame(
			[
				[ 'h2:blog.example.com', [ 'Blog' ] ],
				[ 'h2:notes.example.com', [ 'Notes new', 'Notes old' ] ],
				[ 'h2:zine.example.org', [ 'Zine one' ] ],
			],
			$this->sections( $this->serve( $this->template( [ 'linesPerPage' => 12 ] ) ) )
		);
	}

	public function test_www_and_port_variants_share_one_group(): void {
		$this->like( 'WWW', '2026-08-01 10:00:00', 'https://www.notes.example.com/a' );
		$this->like( 'Port', '2026-08-02 10:00:00', 'https://notes.example.com:8443/b' );
		$this->like( 'Caps', '2026-08-03 10:00:00', 'https://NOTES.example.com/c' );

		$this->assertSame(
			[ [ 'h2:notes.example.com', [ 'Caps', 'Port', 'WWW' ] ] ],
			$this->sections( $this->serve( $this->template( [ 'linesPerPage' => 12 ] ) ) )
		);
	}

	public function test_empty_and_unparsable_urls_file_last_under_other(): void {
		$this->like( 'Alpha', '2026-08-01 10:00:00', 'https://alpha.example.org/p' );
		$this->like( 'No URL', '2026-08-02 10:00:00', null );
		$this->like( 'Relative', '2026-08-03 10:00:00', '/relative/only' );
		$this->like( 'Text', '2026-08-04 10:00:00', 'not a url' );

		$this->assertSame(
			[
				[ 'h2:alpha.example.org', [ 'Alpha' ] ],
				[ 'h2:Other', [ 'Text', 'Relative', 'No URL' ] ],
			],
			$this->sections( $this->serve( $this->template( [ 'linesPerPage' => 12 ] ) ) )
		);
	}

	public function test_the_marker_empty_label_overrides_other(): void {
		$this->like( 'Alpha', '2026-08-01 10:00:00', 'https://alpha.example.org/p' );
		$this->like( 'No URL', '2026-08-02 10:00:00', null );
		$this->like( 'Relative', '2026-08-03 10:00:00', '/relative/only' );
		$this->like( 'Text', '2026-08-04 10:00:00', 'not a url' );

		$sections = $this->sections( $this->serve( $this->template( [ 'emptyLabel' => 'Elsewhere' ] ) ) );

		$this->assertSame( 'h2:Elsewhere', $sections[1][0] );
		$this->assertNotContains( 'h2:Other', array_column( $sections, 0 ) );
	}

	public function test_a_protected_like_files_under_other(): void {
		$this->like( 'Open notes', '2026-08-02 10:00:00', 'https://notes.example.com/open' );
		$this->like( 'Secret notes', '2026-08-01 10:00:00', 'https://notes.example.com/secret', [ 'post_password' => 'secret' ] );

		$this->assertSame(
			[
				[ 'h2:notes.example.com', [ 'Open notes' ] ],
				[ 'h2:Other', [ 'Protected: Secret notes' ] ],
			],
			$this->sections( $this->serve( $this->template( [ 'linesPerPage' => 12 ] ) ) )
		);
	}

	public function test_grouped_kinds_include_like_and_group_of_post_names_the_host(): void {
		$id = $this->like( 'Liked post', '2026-08-01 10:00:00', 'https://www.notes.example.com/x' );

		$this->assertContains( 'like', Grouped_Archive::grouped_kinds() );
		$this->assertSame( 'notes.example.com', Grouped_Archive::group_of_post( 'like', $id )->key() );
		$this->assertSame( 'notes.example.com', Grouped_Archive::group_label_of_post( 'like', $id ) );
	}

	public function test_the_rest_route_orders_likes_by_host(): void {
		$zine     = $this->like( 'Zine', '2026-08-01 10:00:00', 'https://zine.example.org/1' );
		$notes_old = $this->like( 'Notes old', '2026-08-02 10:00:00', 'https://notes.example.com/a' );
		$blog     = $this->like( 'Blog', '2026-08-03 10:00:00', 'https://blog.example.com/p' );
		$notes_new = $this->like( 'Notes new', '2026-08-04 10:00:00', 'https://notes.example.com/b' );
		$empty    = $this->like( 'No URL', '2026-08-05 10:00:00', null );

		$this->assertSame( [ $blog, $notes_new, $notes_old, $zine, $empty ], $this->rest_ids() );
	}

	public function test_twelve_likes_fill_a_page(): void {
		$hosts = [ 'alpha.example.org', 'beta.example.org', 'zine.example.org' ];
		for ( $i = 1; $i <= 14; $i++ ) {
			$host = $hosts[ ( $i - 1 ) % 3 ];
			$this->like( 'Like ' . $i, sprintf( '2026-08-%02d 10:00:00', $i ), 'https://' . $host . '/post-' . $i );
		}

		$html = $this->serve( $this->template( [ 'linesPerPage' => 12 ] ) );
		$this->assertSame( 12, $this->section_title_count( $html ) );
		$this->assertSame( 14, $GLOBALS['wp_query']->found_posts );
		$this->assertSame( 2, $GLOBALS['wp_query']->max_num_pages );

		$html = $this->serve( $this->template( [ 'linesPerPage' => 12 ] ), 2 );
		$this->assertSame( 2, $this->section_title_count( $html ) );
		$this->assertSame( 14, $GLOBALS['wp_query']->found_posts );
		$this->assertSame( 2, $GLOBALS['wp_query']->max_num_pages );
	}
}
