<?php
/**
 * The repost archive grouped by local post month.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Grouped_Archive;
use PKIW\Taxonomy;

/**
 * /kind/repost/ is served by the plugin's taxonomy-kind-repost template.
 * Its loop groups reposts by local post month, twelve items per page, with
 * newest-first order kept for feeds and REST.
 *
 * @group integration
 */
final class RepostArchiveTest extends WP_UnitTestCase {

	/**
	 * Stylesheet active before each test.
	 *
	 * @var string
	 */
	private string $original_stylesheet = '';

	/**
	 * Original site timezone.
	 *
	 * @var string
	 */
	private string $original_timezone = '';

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		$this->original_timezone   = (string) get_option( 'timezone_string' );
		switch_theme( 'twentytwentyfive' );
		( new Taxonomy() )->create_default_terms();
		update_option( 'posts_per_page', 20 );
		update_option( 'timezone_string', 'America/New_York' );
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
	}

	public function tear_down(): void {
		switch_theme( $this->original_stylesheet );
		update_option( 'timezone_string', $this->original_timezone );
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	private function repost( string $title, string $date_local, string $status = 'publish' ): int {
		$id = self::factory()->post->create(
			[
				'post_status' => $status,
				'post_title'  => $title,
				'post_date'   => $date_local,
			]
		);
		wp_set_object_terms( $id, 'repost', Taxonomy::TAXONOMY );
		add_post_meta( $id, '_pkiw_cite_url', 'https://quiet.example.com/' . sanitize_title( $title ) );

		return $id;
	}

	private function archive_url(): string {
		$url = get_term_link( 'repost', Taxonomy::TAXONOMY );
		$this->assertSame( 'http://example.org/kind/repost/', $url );

		return $url;
	}

	private function serve( string $url ): string {
		$this->go_to( $url );
		$template = resolve_block_template( 'taxonomy', [ 'taxonomy-kind-repost.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ], '' );
		$this->assertNotNull( $template );

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;

		return get_the_block_template_html();
	}

	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();

		return new DOMXPath( $dom );
	}

	/**
	 * @return array<int, array{group:string, heading:string, id:string, titles:string[]}>
	 */
	private function sections( string $html ): array {
		$xpath = $this->xpath( $html );
		$out   = [];
		foreach ( $xpath->query( '//section[@data-pkiw-sections]' ) as $section ) {
			$heading = $xpath->query( './*[1]', $section )->item( 0 );
			$out[]   = [
				'group'   => $section->getAttribute( 'data-pkiw-group' ),
				'heading' => $heading instanceof DOMElement ? trim( $heading->textContent ) : '',
				'id'      => $heading instanceof DOMElement ? $heading->getAttribute( 'id' ) : '',
				'titles'  => $this->titles( $xpath, $section ),
			];
		}

		return $out;
	}

	/**
	 * @return string[]
	 */
	private function titles( DOMXPath $xpath, DOMNode $node ): array {
		$titles = [];
		foreach ( $xpath->query( './/li//*[contains(concat(" ", normalize-space(@class), " "), " pk-title ")]', $node ) as $title ) {
			$titles[] = trim( $title->textContent );
		}

		return $titles;
	}

	/**
	 * The repost archive's REST posts in grouped order, keyed by ID.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rest_reposts_by_group(): array {
		$GLOBALS['wp_rest_server'] = null;
		$request                   = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$request->set_param( 'kind', [ get_term_by( 'slug', 'repost', Taxonomy::TAXONOMY )->term_id ] );
		$request->set_param( 'orderby', 'pkiw_group' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );

		return array_column( (array) $response->get_data(), null, 'id' );
	}

	public function test_reposts_group_by_local_post_month(): void {
		$july = $this->repost( 'July edge', '2026-07-01 00:30:00' );
		$june = $this->repost( 'June edge', '2026-06-30 23:30:00' );

		$july_group = Grouped_Archive::group_of_post( 'repost', $july );
		$june_group = Grouped_Archive::group_of_post( 'repost', $june );

		$this->assertSame( '2026-07', $july_group->key() );
		$this->assertSame( 'July 2026', Grouped_Archive::group_label_of_post( 'repost', $july ) );
		$this->assertSame( '2026-06', $june_group->key() );
		$this->assertSame( 'June 2026', Grouped_Archive::group_label_of_post( 'repost', $june ) );
	}

	public function test_grouped_kinds_contains_repost(): void {
		$this->assertContains( 'repost', Grouped_Archive::grouped_kinds() );
	}

	public function test_twelve_reposts_fill_a_page_and_a_continued_month_repeats_its_heading(): void {
		for ( $i = 0; $i < 10; $i++ ) {
			$this->repost( 'August repost ' . $i, '2026-08-' . sprintf( '%02d', 28 - $i ) . ' 10:00:00' );
		}
		for ( $i = 0; $i < 8; $i++ ) {
			$this->repost( 'July repost ' . $i, '2026-07-' . sprintf( '%02d', 30 - $i ) . ' 10:00:00' );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			$this->repost( 'June repost ' . $i, '2026-06-' . sprintf( '%02d', 30 - $i ) . ' 10:00:00' );
		}

		$first       = $this->serve( $this->archive_url() );
		$first_xpath = $this->xpath( $first );
		$this->assertSame( 12, $first_xpath->query( '//li[contains(concat(" ", normalize-space(@class), " "), " wp-block-post ")]' )->length );
		$this->assertSame( [ 'August 2026', 'July 2026' ], array_column( $this->sections( $first ), 'heading' ) );

		$second       = $this->serve( $this->archive_url() . 'page/2/' );
		$second_xpath = $this->xpath( $second );
		$sections     = $this->sections( $second );
		$this->assertSame( 9, $second_xpath->query( '//li[contains(concat(" ", normalize-space(@class), " "), " wp-block-post ")]' )->length );
		$this->assertSame( 'July 2026', $sections[0]['heading'] );
		$this->assertStringNotContainsString( 'continued', $second );

		foreach ( [ $first, $second ] as $html ) {
			$ids = array_column( $this->sections( $html ), 'id' );
			$this->assertNotContains( '', $ids );
			$this->assertSame( $ids, array_values( array_unique( $ids ) ) );
		}
	}

	public function test_page_one_lists_reposts_newest_first(): void {
		$newest = $this->repost( 'Newest repost', '2026-08-30 10:00:00' );
		$middle = $this->repost( 'Middle repost', '2026-08-29 10:00:00' );
		$oldest = $this->repost( 'Oldest repost', '2026-08-28 10:00:00' );

		$this->serve( $this->archive_url() );

		$this->assertSame( [ $newest, $middle, $oldest ], array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) ) );
	}

	public function test_the_feed_stays_newest_first_and_ungrouped(): void {
		update_option( 'posts_per_rss', 2 );
		$newest = $this->repost( 'Newest feed repost', '2026-08-30 10:00:00' );
		$middle = $this->repost( 'Middle feed repost', '2026-08-29 10:00:00' );
		$this->repost( 'Oldest feed repost', '2026-08-28 10:00:00' );

		$this->go_to( get_term_feed_link( get_term_by( 'slug', 'repost', Taxonomy::TAXONOMY )->term_id, Taxonomy::TAXONOMY ) );
		$wp_query = $GLOBALS['wp_query'];

		$this->assertTrue( $wp_query->is_feed() );
		foreach ( [ 'pkiw_group_source', 'pkiw_group_by', 'pkiw_group_order', 'pkiw_group_empty' ] as $var ) {
			$this->assertSame( '', (string) $wp_query->get( $var ) );
		}
		$this->assertSame( 2, (int) $wp_query->get( 'posts_per_page' ) );
		$this->assertSame( [ $newest, $middle ], array_map( 'intval', wp_list_pluck( $wp_query->posts, 'ID' ) ) );
	}

	public function test_rest_reposts_by_group_stay_newest_first(): void {
		$newest = $this->repost( 'Newest REST repost', '2026-08-30 10:00:00' );
		$middle = $this->repost( 'Middle REST repost', '2026-08-29 10:00:00' );
		$oldest = $this->repost( 'Oldest REST repost', '2026-07-28 10:00:00' );

		$items = $this->rest_reposts_by_group();

		$this->assertSame( [ $newest, $middle, $oldest ], array_keys( $items ) );
	}

	public function test_protected_reposts_keep_their_month_and_private_reposts_are_absent_logged_out(): void {
		wp_set_current_user( 0 );
		$protected = $this->repost( 'Protected repost', '2026-07-15 10:00:00' );
		wp_update_post(
			[
				'ID'            => $protected,
				'post_password' => 'secret',
			]
		);
		$private = $this->repost( 'Private repost', '2026-07-16 10:00:00', 'private' );

		$this->assertSame( '2026-07', Grouped_Archive::group_of_post( 'repost', $protected )->key() );

		$this->serve( $this->archive_url() );
		$this->assertNotContains( $private, array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) ) );
	}

	public function test_an_empty_repost_archive_renders_no_results_text(): void {
		$html = $this->serve( $this->archive_url() );

		$this->assertStringContainsString( 'Nothing here yet.', $html );
		$this->assertStringNotContainsString( '<section', $html );
	}
}
