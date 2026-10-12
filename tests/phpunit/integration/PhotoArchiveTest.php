<?php
/**
 * Photo month archive tests for issue 241.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Grouped_Archive;
use PKIW\Taxonomy;

/**
 * /kind/photo/ groups photo posts by the local publication month.
 *
 * @group integration
 */
final class PhotoArchiveTest extends WP_UnitTestCase {

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
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'posts_per_page', 20 );
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
	 * Core's taxonomy hierarchy for the photo term.
	 *
	 * @return string[]
	 */
	private function hierarchy(): array {
		return [ 'taxonomy-kind-photo.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ];
	}

	/**
	 * A published photo post.
	 *
	 * @param string $title      Post title.
	 * @param string $local_date Local post date.
	 * @return int Post ID.
	 */
	private function photo( string $title, string $local_date ): int {
		$id = self::factory()->post->create(
			[
				'post_status'   => 'publish',
				'post_title'    => $title,
				'post_date'     => $local_date,
				'post_date_gmt' => get_gmt_from_date( $local_date ),
			]
		);
		wp_set_object_terms( $id, 'photo', Taxonomy::TAXONOMY );

		return (int) $id;
	}

	/**
	 * Photo archive URL.
	 */
	private function archive_url(): string {
		$url = get_term_link( 'photo', Taxonomy::TAXONOMY );
		$this->assertIsString( $url );

		return $url;
	}

	/**
	 * Visit and render a photo archive URL.
	 *
	 * @param string $url URL.
	 */
	private function serve( string $url ): string {
		$this->go_to( $url );
		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );
		$this->assertNotNull( $template );

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;

		return get_the_block_template_html();
	}

	/**
	 * Parse HTML into XPath.
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
	 * Rendered month sections.
	 *
	 * @param string $html Rendered archive.
	 * @return array<int, array{group:string, heading:string, id:string, titles:string[]}>
	 */
	private function sections( string $html ): array {
		$xpath = $this->xpath( $html );
		$out   = [];
		foreach ( $xpath->query( '//section[@data-pkiw-sections]' ) as $section ) {
			$heading = $xpath->query( './*[1]', $section )->item( 0 );
			$titles  = [];
			foreach ( $xpath->query( './/li//*[contains(concat(" ", normalize-space(@class), " "), " pk-title ")]', $section ) as $title ) {
				$titles[] = trim( $title->textContent );
			}
			$out[] = [
				'group'   => $section->getAttribute( 'data-pkiw-group' ),
				'heading' => $heading instanceof DOMElement ? $heading->nodeName . ':' . trim( $heading->textContent ) : '',
				'id'      => $heading instanceof DOMElement ? $heading->getAttribute( 'id' ) : '',
				'titles'  => $titles,
			];
		}

		return $out;
	}

	/**
	 * Fourteen photos in one month.
	 *
	 * @return int[]
	 */
	private function fourteen(): array {
		$ids = [];
		for ( $i = 0; $i < 14; ++$i ) {
			$ids[] = $this->photo( 'Frame ' . sprintf( '%02d', $i + 1 ), '2026-08-' . sprintf( '%02d', 28 - $i ) . ' 10:00:00' );
		}

		return $ids;
	}

	public function test_twelve_frames_fill_the_first_page_without_changing_counts(): void {
		$this->fourteen();

		$first = $this->serve( $this->archive_url() );
		$this->assertSame( 12, $this->xpath( $first )->query( '//li[contains(concat(" ", normalize-space(@class), " "), " wp-block-post ")]' )->length );
		$this->assertSame( 14, $GLOBALS['wp_query']->found_posts );
		$this->assertSame( 2, $GLOBALS['wp_query']->max_num_pages );

		$query = new WP_Query(
			[
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'tax_query'      => [
					[
						'taxonomy' => Taxonomy::TAXONOMY,
						'field'    => 'slug',
						'terms'    => [ 'photo' ],
					],
				],
			]
		);
		$this->assertSame( 14, count( $query->posts ) );

		$second = $this->serve( $this->archive_url() . 'page/2/' );
		$this->assertSame( 2, $this->xpath( $second )->query( '//li[contains(concat(" ", normalize-space(@class), " "), " wp-block-post ")]' )->length );
	}

	public function test_sections_run_newest_month_first_and_order_within_a_month(): void {
		$august    = $this->photo( 'Late August', '2026-08-31 23:30:00' );
		$september = $this->photo( 'Early September', '2026-09-01 00:10:00' );
		$older     = $this->photo( 'Same Time Lower ID', '2026-09-02 12:00:00' );
		$newer     = $this->photo( 'Same Time Higher ID', '2026-09-02 12:00:00' );
		$this->assertGreaterThan( $older, $newer );

		$sections = $this->sections( $this->serve( $this->archive_url() ) );

		$this->assertSame( [ '2026-09', '2026-08' ], array_column( $sections, 'group' ) );
		$this->assertSame( [ 'h2:September 2026', 'h2:August 2026' ], array_column( $sections, 'heading' ) );
		$this->assertContains( 'Early September', $sections[0]['titles'] );
		$this->assertContains( 'Late August', $sections[1]['titles'] );
		$this->assertSame( [ 'Same Time Higher ID', 'Same Time Lower ID', 'Early September' ], array_slice( $sections[0]['titles'], 0, 3 ) );
		$this->assertSame( '2026-08', Grouped_Archive::group_of_post( 'photo', $august )->key() );
		$this->assertSame( '2026-09', Grouped_Archive::group_of_post( 'photo', $september )->key() );
	}

	public function test_frame_titles_are_h3_under_localized_month_h2s(): void {
		$this->photo( 'Boardwalk Light', '2026-08-20 10:00:00' );

		$html = $this->serve( $this->archive_url() );

		$this->assertStringContainsString( '<h2', $html );
		$this->assertStringContainsString( 'August 2026', $html );
		$this->assertSame( 1, $this->xpath( $html )->query( '//section[@data-pkiw-sections]//h3[contains(concat(" ", normalize-space(@class), " "), " pk-title ") and contains(., "Boardwalk Light")]' )->length );
	}

	public function test_a_continuing_month_repeats_its_h2_with_a_different_id(): void {
		$this->fourteen();

		$first  = $this->sections( $this->serve( $this->archive_url() ) );
		$second = $this->sections( $this->serve( $this->archive_url() . 'page/2/' ) );

		$this->assertSame( 'h2:August 2026', $first[0]['heading'] );
		$this->assertSame( 'h2:August 2026', $second[0]['heading'] );
		$this->assertNotSame( $first[0]['id'], $second[0]['id'] );
	}

	public function test_the_editor_preview_renders_a_month_heading(): void {
		$post_id                   = $this->photo( 'Editor Preview', '2026-08-20 10:00:00' );
		$GLOBALS['wp_rest_server'] = null;
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/post-kinds-indieweb/archive-sections' );
		$request->set_param( 'context', 'edit' );
		$request->set_param( 'post_id', $post_id );
		$request->set_param( 'pkiw_kind', 'photo' );
		$request->set_param( 'attributes', [ 'headingLevel' => 2 ] );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertStringContainsString( '<h2', (string) $response->get_data()['rendered'] );
		$this->assertStringContainsString( 'August 2026', (string) $response->get_data()['rendered'] );
	}

	public function test_the_feed_stays_newest_first_and_not_grouped(): void {
		$ids = $this->fourteen();
		$url = get_term_feed_link( get_term_by( 'slug', 'photo', Taxonomy::TAXONOMY )->term_id, Taxonomy::TAXONOMY );
		$this->assertSame( 'http://example.org/kind/photo/feed/', $url );

		$this->go_to( $url );

		$this->assertTrue( is_feed() );
		$this->assertSame( array_slice( $ids, 0, count( $GLOBALS['wp_query']->posts ) ), wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );
		$this->assertStringNotContainsString( 'pkiw_group', $GLOBALS['wp_query']->request );
	}

	public function test_photo_is_a_grouped_kind(): void {
		$this->assertContains( 'photo', Grouped_Archive::grouped_kinds() );
	}
}
