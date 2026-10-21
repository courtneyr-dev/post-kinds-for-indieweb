<?php
/**
 * Drawn comics archive query behavior (issue 266).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Comic_Strip;
use PKIW\Kind_Archive_Layouts;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class ComicDrawnViewTest extends WP_UnitTestCase {

	private string $original_stylesheet = '';

	private int $original_posts_per_page = 0;

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet     = get_stylesheet();
		$this->original_posts_per_page = (int) get_option( 'posts_per_page' );
		switch_theme( 'twentytwentyfive' );
		update_option( 'posts_per_page', 50 );
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
		return '<!-- wp:gallery --><figure class="wp-block-gallery has-nested-images"><!-- wp:image {"id":' . $attachment_id . '} --><figure class="wp-block-image"><img src="https://example.org/panel-' . $attachment_id . '.jpg" alt="Panel" /><figcaption class="wp-element-caption">Transcript</figcaption></figure><!-- /wp:image --></figure><!-- /wp:gallery -->';
	}

	/**
	 * Create a drawn strip fixture.
	 *
	 * @param string               $title  Post title.
	 * @param string               $series Series value.
	 * @param array<string, mixed> $args   Post overrides.
	 */
	private function strip( string $title, string $series = '', array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_content' => $this->gallery( self::factory()->attachment->create() ),
				],
				$args
			)
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

	private function drawn_url( string $series = '', string $suffix = '' ): string {
		$args = [ Comic_Strip::VIEW_VAR => Comic_Strip::VIEW_DRAWN ];
		if ( '' !== $series ) {
			$args[ Comic_Strip::SERIES_VAR ] = $series;
		}

		return add_query_arg( $args, $this->archive_url() . $suffix );
	}

	/**
	 * Main-query post IDs.
	 *
	 * @return int[]
	 */
	private function current_ids(): array {
		return array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );
	}

	public function test_drawn_view_selects_public_strips_and_excludes_card_states(): void {
		$expected = [];
		foreach ( range( 1, 14 ) as $number ) {
			$expected[] = $this->strip( 'D' . sprintf( '%02d', $number ), $number <= 6 ? 'Field Notes' : ( $number <= 11 ? 'Small Web Stories' : '' ) );
		}
		$expected[] = $this->strip( 'X04', 'Field Notes' );

		$r02 = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'R02',
				'post_content' => '<!-- wp:post-kinds-indieweb/comic-card /-->',
			]
		);
		wp_set_object_terms( $r02, Comic_Strip::KIND, Taxonomy::TAXONOMY );
		update_post_meta( $r02, Comic_Strip::STATUS_META, 'reading' );
		$r03 = $this->strip( 'R03', 'Lumberjanes' );
		update_post_meta( $r03, Comic_Strip::STATUS_META, 'finished' );
		$locked = $this->strip( 'X01', 'Secret Series', [ 'post_password' => 'secret' ] );
		$this->strip( 'X02', 'Private Series', [ 'post_status' => 'private' ] );
		$this->strip( 'X03', 'Draft Series', [ 'post_status' => 'draft' ] );

		// The view's template sets 12 to a page, so the fifteen strips span two pages.
		$this->go_to( $this->drawn_url() );
		$this->assertSame( 15, (int) $GLOBALS['wp_query']->found_posts );
		$ids = $this->current_ids();
		$this->go_to( $this->drawn_url( '', 'page/2/' ) );
		$ids = array_merge( $ids, $this->current_ids() );
		$this->assertEqualsCanonicalizing( $expected, $ids );
		foreach ( [ $r02, $r03, $locked ] as $excluded ) {
			$this->assertNotContains( $excluded, $ids );
		}
		$this->assertSame( 'taxonomy-kind-comics-drawn', Kind_Archive_Layouts::filtered_view()['slug'] );
	}

	public function test_plain_archive_has_no_new_meta_query_and_keeps_its_sql(): void {
		$this->strip( 'Plain strip', 'Field Notes' );
		$this->go_to( $this->archive_url() );
		$with_hook = (string) $GLOBALS['wp_query']->request;
		$this->assertEmpty( $GLOBALS['wp_query']->get( 'meta_query' ) );
		$this->assertStringNotContainsString( Comic_Strip::STATUS_META, $with_hook );
		$this->assertNull( Kind_Archive_Layouts::filtered_view() );

		remove_action( 'pre_get_posts', [ Comic_Strip::class, 'filter_main_query' ] );
		$this->go_to( $this->archive_url() );
		$without_hook = (string) $GLOBALS['wp_query']->request;
		add_action( 'pre_get_posts', [ Comic_Strip::class, 'filter_main_query' ] );

		$this->assertSame( $without_hook, $with_hook );
	}

	public function test_valid_series_narrows_and_unknown_or_private_series_is_ignored(): void {
		$field = $this->strip( 'Field strip', 'Field Notes' );
		$small = $this->strip( 'Small strip', 'Small Web Stories' );
		$this->strip( 'Protected strip', 'Secret Series', [ 'post_password' => 'secret' ] );

		$this->go_to( $this->drawn_url( 'Field Notes' ) );
		$this->assertSame( [ $field ], $this->current_ids() );

		$this->go_to( $this->drawn_url( 'Not Stored' ) );
		$this->assertEqualsCanonicalizing( [ $field, $small ], $this->current_ids() );

		$this->go_to( $this->drawn_url( 'Secret Series' ) );
		$this->assertEqualsCanonicalizing( [ $field, $small ], $this->current_ids() );
	}

	public function test_password_protected_strip_is_hidden_from_subscriber_but_visible_to_editor(): void {
		$public = $this->strip( 'Open strip', 'Field Notes' );
		$locked = $this->strip( 'Locked strip', 'Field Notes', [ 'post_password' => 'secret' ] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->go_to( $this->drawn_url() );
		$this->assertSame( [ $public ], $this->current_ids() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->go_to( $this->drawn_url() );
		$this->assertEqualsCanonicalizing( [ $public, $locked ], $this->current_ids() );
	}

	public function test_page_links_keep_the_view_and_series_variables(): void {
		update_option( 'posts_per_page', 12 );
		foreach ( range( 1, 14 ) as $number ) {
			$this->strip( 'Page strip ' . $number, 'Field Notes', [ 'post_date' => sprintf( '2026-10-%02d 10:00:00', $number ) ] );
		}
		$url = $this->drawn_url( 'Field Notes' );
		$this->go_to( $url );
		$html = do_blocks( (string) file_get_contents( PKIW_PATH . 'templates/taxonomy-kind-comics-drawn.html' ) );

		$page_query = [];
		parse_str( (string) wp_parse_url( wp_specialchars_decode( get_pagenum_link( 2 ) ), PHP_URL_QUERY ), $page_query );
		$this->assertSame( Comic_Strip::VIEW_DRAWN, $page_query[ Comic_Strip::VIEW_VAR ] );
		$this->assertSame( 'Field Notes', $page_query[ Comic_Strip::SERIES_VAR ] );
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();
		$xpath = new DOMXPath( $dom );
		$links = $xpath->query( '//*[contains(concat(" ",@class," ")," wp-block-query-pagination ")]//a' );
		$this->assertGreaterThan( 0, $links->length );
		foreach ( $links as $link ) {
			$link_query = [];
			parse_str( (string) wp_parse_url( wp_specialchars_decode( $link->getAttribute( 'href' ) ), PHP_URL_QUERY ), $link_query );
			$this->assertSame( Comic_Strip::VIEW_DRAWN, $link_query[ Comic_Strip::VIEW_VAR ] );
			$this->assertSame( 'Field Notes', $link_query[ Comic_Strip::SERIES_VAR ] );
		}
	}
}
