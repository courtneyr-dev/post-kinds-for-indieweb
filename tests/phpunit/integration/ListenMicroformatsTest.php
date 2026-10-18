<?php
/**
 * Podcast listen microformats (issue 236).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Listen_Archive;
use PKIW\Listen_Media_Type;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class ListenMicroformatsTest extends WP_UnitTestCase {

	private string $original_stylesheet = '';

	private string $original_timezone = '';

	private int $author_id = 0;

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		$this->original_timezone   = (string) get_option( 'timezone_string' );
		update_option( 'timezone_string', 'America/New_York' );
		switch_theme( 'twentytwentyfive' );
		( new Meta_Fields() )->register_meta_fields();
		( new Taxonomy() )->create_default_terms();
		( new Listen_Archive() )->register();
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
		$this->author_id = self::factory()->user->create( [ 'display_name' => 'Morgan Reed' ] );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		update_option( 'timezone_string', $this->original_timezone );
		switch_theme( $this->original_stylesheet );
		$this->set_permalink_structure( '' );
		wp_set_current_user( 0 );
		wp_reset_postdata();
		parent::tear_down();
	}

	private function listen( string $content = '<!-- wp:paragraph --><p>Import-shaped listen note.</p><!-- /wp:paragraph -->' ): int {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Listened to Harbor Signals',
				'post_date'    => '2026-09-26 14:00:00',
				'post_author'  => $this->author_id,
				'post_content' => $content,
			]
		);
		wp_set_object_terms( $id, 'listen', Taxonomy::TAXONOMY );
		foreach (
			[
				Listen_Media_Type::META   => 'podcast',
				'_pkiw_listen_track'       => 'Harbor Signals',
				'_pkiw_listen_album'       => 'Alder Street Radio',
				'_pkiw_listen_url'         => 'https://audio.example.org/episodes/harbor-signals',
				'_pkiw_listen_listened_at' => '2026-09-26',
			] as $key => $value
		) {
			update_post_meta( $id, $key, $value );
		}

		return (int) $id;
	}

	private function line( int $post_id ): string {
		$block = new WP_Block(
			[
				'blockName'    => Listen_Archive::EPISODE_BLOCK,
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			],
			[ 'postId' => $post_id, 'postType' => 'post' ]
		);

		return Listen_Archive::render( [], '', $block );
	}

	private function stream( int $post_id ): string {
		$post = get_post( $post_id );
		$this->assertInstanceOf( WP_Post::class, $post );
		$GLOBALS['post'] = $post;
		setup_postdata( $post );

		return do_blocks( '<!-- wp:post-kinds-indieweb/stream-card /-->' );
	}

	public function test_lineup_line_parses_as_an_entry_with_author_date_url_and_listen_cite(): void {
		$id = $this->listen();
		$html = '<li class="' . esc_attr( implode( ' ', get_post_class( '', $id ) ) ) . '">' . $this->line( $id ) . '</li>';
		$entry = $this->entry( $html, (string) get_permalink( $id ) );

		$this->assertSame( [ get_permalink( $id ) ], $entry['properties']['url'] );
		$this->assertCount( 1, $entry['properties']['published'] );
		$this->assertSame( [ 'Morgan Reed' ], $entry['properties']['author'][0]['properties']['name'] );
		$this->assert_listen_cite( $entry );
	}

	public function test_generic_stream_card_parses_with_the_same_listen_cite(): void {
		$id = $this->listen();
		// A rooted card is its own h-entry; Microformats::add_post_classes() drops the root from the list item, so the item carries no class here.
		$html = '<li>' . $this->stream( $id ) . '</li>';
		$entry = $this->entry( $html, (string) get_permalink( $id ) );

		$this->assert_listen_cite( $entry );
	}

	public function test_hidden_singular_cite_is_absent_off_singular_and_added_once_on_its_post(): void {
		$id = $this->listen();
		$post = get_post( $id );
		$this->assertInstanceOf( WP_Post::class, $post );
		$GLOBALS['post'] = $post;
		setup_postdata( $post );

		$this->go_to( get_term_link( 'listen', Taxonomy::TAXONOMY ) );
		$GLOBALS['post'] = $post;
		setup_postdata( $post );
		$archive_content = apply_filters( 'the_content', (string) $post->post_content );
		$this->assertStringNotContainsString( 'u-listen-of', $archive_content );

		$this->go_to( get_permalink( $id ) );
		$GLOBALS['post'] = $post;
		setup_postdata( $post );
		$singular_content = apply_filters( 'the_content', (string) $post->post_content );
		$this->assertSame( 1, substr_count( $singular_content, 'u-listen-of' ) );
		$entry = $this->entry( $singular_content, (string) get_permalink( $id ) );
		$this->assert_listen_cite( $entry );
	}

	public function test_card_authored_podcast_gets_no_second_listen_cite(): void {
		$content = '<!-- wp:post-kinds-indieweb/listen-card {"trackTitle":"Harbor Signals","artistName":"Alder Street Radio","albumTitle":"Alder Street Radio","listenUrl":"https://audio.example.org/episodes/harbor-signals"} /-->';
		$id = $this->listen( $content );
		$post = get_post( $id );
		$this->go_to( get_permalink( $id ) );
		$GLOBALS['post'] = $post;
		setup_postdata( $post );

		$html = apply_filters( 'the_content', $content );

		$this->assertSame( 1, substr_count( $html, 'u-listen-of' ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function entry( string $html, string $base ): array {
		$items = \Mf2\parse( $html, $base )['items'] ?? [];
		foreach ( $items as $item ) {
			if ( in_array( 'h-entry', $item['type'] ?? [], true ) ) {
				return $item;
			}
		}
		$this->fail( 'No h-entry parsed.' );
	}

	/**
	 * @param array<string, mixed> $entry Parsed h-entry.
	 */
	private function assert_listen_cite( array $entry ): void {
		$this->assertCount( 1, $entry['properties']['listen-of'] ?? [] );
		$cite = $entry['properties']['listen-of'][0];
		$this->assertIsArray( $cite );
		$this->assertContains( 'h-cite', $cite['type'] );
		$this->assertSame( [ 'Harbor Signals' ], $cite['properties']['name'] );
		$this->assertSame( [ 'https://audio.example.org/episodes/harbor-signals' ], $cite['properties']['url'] );
		$this->assertSame( [ 'Alder Street Radio' ], $cite['properties']['author'][0]['properties']['name'] );
	}
}
