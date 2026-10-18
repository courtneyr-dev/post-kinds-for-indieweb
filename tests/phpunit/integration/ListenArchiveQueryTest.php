<?php
/**
 * Podcast listen archive query behavior (issue 236).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Grouped_Archive;
use PKIW\Kind_Archive_Layouts;
use PKIW\Listen_Archive;
use PKIW\Listen_Media_Type;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class ListenArchiveQueryTest extends WP_UnitTestCase {

	private string $original_stylesheet = '';

	private string $original_timezone = '';

	private int $original_posts_per_page = 0;

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet     = get_stylesheet();
		$this->original_timezone       = (string) get_option( 'timezone_string' );
		$this->original_posts_per_page = (int) get_option( 'posts_per_page' );
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'posts_per_page', 12 );
		switch_theme( 'twentytwentyfive' );
		( new Meta_Fields() )->register_meta_fields();
		( new Taxonomy() )->create_default_terms();
		( new Listen_Archive() )->register();
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		update_option( 'timezone_string', $this->original_timezone );
		update_option( 'posts_per_page', $this->original_posts_per_page );
		switch_theme( $this->original_stylesheet );
		$this->set_permalink_structure( '' );
		wp_set_current_user( 0 );
		wp_reset_postdata();
		parent::tear_down();
	}

	private function listen( string $title, string $date, ?string $type, array $args = [], string $kind = 'listen' ): int {
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
		wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY );
		if ( null !== $type ) {
			update_post_meta( $id, Listen_Media_Type::META, $type );
		}
		update_post_meta( $id, '_pkiw_listen_track', $title );
		update_post_meta( $id, '_pkiw_listen_album', 'Example Radio' );

		return (int) $id;
	}

	private function archive_url(): string {
		return (string) get_term_link( 'listen', Taxonomy::TAXONOMY );
	}

	private function podcast_url( string $suffix = '' ): string {
		return add_query_arg( Listen_Archive::QUERY_VAR, 'podcast', $this->archive_url() . $suffix );
	}

	/**
	 * Serve the resolved block template.
	 */
	private function serve( string $url ): string {
		$this->go_to( $url );
		$hierarchy = [
			'taxonomy-kind-listen-podcast.php',
			'taxonomy-kind-listen.php',
			'taxonomy-kind.php',
			'taxonomy.php',
			'archive.php',
			'index.php',
		];
		$template = resolve_block_template( 'taxonomy', $hierarchy, '' );
		$this->assertNotNull( $template );

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;

		return get_the_block_template_html();
	}

	/**
	 * @return int[]
	 */
	private function current_ids(): array {
		return array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );
	}

	public function test_the_podcast_view_lists_only_public_podcast_listens(): void {
		$podcast = $this->listen( 'P01', '2026-09-10 10:00:00', 'podcast' );
		$note = $this->listen( 'N01', '2026-09-11 10:00:00', 'podcast', [], 'note' );
		$music = $this->listen( 'M01', '2026-09-09 10:00:00', 'music' );
		$unset = $this->listen( 'M02', '2026-09-08 10:00:00', null );
		$private = $this->listen( 'U01', '2026-09-07 10:00:00', 'podcast', [ 'post_status' => 'private' ] );
		$draft = $this->listen( 'U02', '2026-09-06 10:00:00', 'podcast', [ 'post_status' => 'draft' ] );
		$trashed = $this->listen( 'U03', '2026-09-05 10:00:00', 'podcast' );
		wp_trash_post( $trashed );

		$this->go_to( $this->podcast_url() );

		$this->assertSame( [ $podcast ], $this->current_ids() );
		foreach ( [ $note, $music, $unset, $private, $draft, $trashed ] as $excluded ) {
			$this->assertNotContains( $excluded, $this->current_ids() );
		}
		$this->assertSame( 'taxonomy-kind-listen-podcast', Kind_Archive_Layouts::filtered_view()['slug'] );
	}

	public function test_plain_and_unknown_views_preserve_the_existing_shelf_membership_and_order(): void {
		$newest = $this->listen( 'P01', '2026-09-12 10:00:00', 'podcast' );
		$music = $this->listen( 'M01', '2026-09-11 10:00:00', 'music' );
		$unset = $this->listen( 'M02', '2026-09-10 10:00:00', null );

		$this->go_to( $this->archive_url() );
		$plain = $this->current_ids();
		$this->assertSame( [ $newest, $music, $unset ], $plain );
		$this->assertNull( Kind_Archive_Layouts::filtered_view() );
		$this->assertNotContains( 'listen', Grouped_Archive::grouped_kinds(), 'The source has no listen kind default.' );

		$this->go_to( add_query_arg( Listen_Archive::QUERY_VAR, 'music', $this->archive_url() ) );
		$this->assertSame( $plain, $this->current_ids() );
		$this->assertNull( Kind_Archive_Layouts::filtered_view() );
	}

	public function test_other_kind_archives_ignore_the_listen_view(): void {
		$note = $this->listen( 'N01', '2026-09-11 10:00:00', 'podcast', [], 'note' );
		$url = add_query_arg( Listen_Archive::QUERY_VAR, 'podcast', get_term_link( 'note', Taxonomy::TAXONOMY ) );

		$this->go_to( $url );

		$this->assertContains( $note, $this->current_ids() );
		$this->assertNull( Kind_Archive_Layouts::filtered_view() );
	}

	public function test_page_links_keep_the_podcast_query_variable(): void {
		foreach ( range( 1, 14 ) as $number ) {
			$this->listen( 'P' . $number, sprintf( '2026-09-%02d 10:00:00', 28 - $number ), 'podcast' );
		}
		$html = $this->serve( $this->podcast_url() );

		$this->assertStringContainsString( 'pkiw_listen_type=podcast', get_pagenum_link( 2 ) );
		$xpath = $this->xpath( $html );
		$links = $xpath->query( '//*[contains(concat(" ",@class," ")," wp-block-query-pagination ")]//a' );
		$this->assertGreaterThan( 0, $links->length );
		foreach ( $links as $link ) {
			$this->assertStringContainsString( 'pkiw_listen_type=podcast', $link->getAttribute( 'href' ) );
		}
	}

	public function test_the_kind_feed_filters_to_podcasts_without_grouping_and_keeps_stable_order(): void {
		$older = $this->listen( 'P01', '2026-09-10 10:00:00', 'podcast' );
		$newer_low = $this->listen( 'P02', '2026-09-11 10:00:00', 'podcast' );
		$newer_high = $this->listen( 'P03', '2026-09-11 10:00:00', 'podcast' );
		$this->listen( 'M01', '2026-09-12 10:00:00', 'music' );
		$term = get_term_by( 'slug', 'listen', Taxonomy::TAXONOMY );
		$this->assertInstanceOf( WP_Term::class, $term );

		$this->go_to( add_query_arg( Listen_Archive::QUERY_VAR, 'podcast', get_term_feed_link( $term->term_id, Taxonomy::TAXONOMY ) ) );

		$this->assertTrue( is_feed() );
		$this->assertSame( [ $newer_high, $newer_low, $older ], $this->current_ids() );
		$this->assertSame( '', (string) $GLOBALS['wp_query']->get( 'pkiw_group_source' ) );
	}

	public function test_duplicate_media_type_rows_do_not_duplicate_a_post_or_found_count(): void {
		$id = $this->listen( 'P01', '2026-09-10 10:00:00', null );
		add_post_meta( $id, Listen_Media_Type::META, 'podcast', false );
		add_post_meta( $id, Listen_Media_Type::META, 'podcast', false );

		$this->go_to( $this->podcast_url() );

		$this->assertSame( 1, (int) $GLOBALS['wp_query']->found_posts );
		$this->assertSame( [ $id ], $this->current_ids() );
	}

	public function test_the_podcast_template_and_current_type_link_are_served(): void {
		$this->listen( 'P01', '2026-09-10 10:00:00', 'podcast' );
		$html = $this->serve( $this->podcast_url() );

		$this->assertSame( 'taxonomy-kind-listen-podcast', Kind_Archive_Layouts::filtered_view()['slug'] );
		$this->assertStringContainsString( 'wp-block-post-kinds-indieweb-listen-types', $html );
		$this->assertMatchesRegularExpression( '/<a class="pk-listen-types__link"[^>]+aria-current="page"[^>]*>Podcasts<\/a>/', $html );
	}

	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();

		return new DOMXPath( $dom );
	}
}
