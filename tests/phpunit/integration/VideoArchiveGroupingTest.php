<?php
/**
 * The video archive's year sections and paging (issue 242).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Taxonomy;
use PKIW\Video_Archive;

/**
 * The shipped video archive groups visible posts by their local post year.
 *
 * @group integration
 */
final class VideoArchiveGroupingTest extends WP_UnitTestCase {

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
		( new Video_Archive() )->register();
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'posts_per_page', 20 );
		wp_set_current_user( 0 );
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		switch_theme( $this->original_stylesheet );
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/**
	 * Core's taxonomy hierarchy for the video term.
	 *
	 * @return string[]
	 */
	private function hierarchy(): array {
		return [ 'taxonomy-kind-video.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ];
	}

	/**
	 * Video archive URL.
	 */
	private function archive_url(): string {
		$url = get_term_link( 'video', Taxonomy::TAXONOMY );
		$this->assertIsString( $url );

		return $url;
	}

	/**
	 * Render the resolved archive template for a URL.
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
	 */
	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();

		return new DOMXPath( $dom );
	}

	/**
	 * Rendered year sections.
	 *
	 * @return array<int, array{group:string, heading:string, titles:string[]}>
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
				'heading' => $heading instanceof DOMElement ? trim( $heading->textContent ) : '',
				'titles'  => $titles,
			];
		}

		return $out;
	}

	/**
	 * A video attachment row with no file on disk.
	 */
	private function video_attachment( string $file, ?int $length ): int {
		$id = (int) self::factory()->attachment->create_object(
			[
				'file'           => $file,
				'post_mime_type' => 'video/mp4',
			]
		);
		wp_update_attachment_metadata( $id, null === $length ? [] : [ 'length' => $length ] );

		return $id;
	}

	/**
	 * An image attachment row with no file on disk.
	 */
	private function image_attachment( string $file, string $alt = '' ): int {
		$id = (int) self::factory()->attachment->create_object(
			[
				'file'           => $file,
				'post_mime_type' => 'image/png',
			]
		);
		if ( '' !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}

		return $id;
	}

	/**
	 * A saved core/video block.
	 */
	private function video_block( int $id, string $html = '' ): string {
		$attrs = $id > 0 ? ' {"id":' . $id . '}' : '';
		if ( '' === $html ) {
			$html = '<figure class="wp-block-video"><video controls src="' . esc_url( (string) wp_get_attachment_url( $id ) ) . '"></video></figure>';
		}

		return '<!-- wp:video' . $attrs . ' -->' . $html . '<!-- /wp:video -->';
	}

	/**
	 * A saved core/embed block.
	 */
	private function embed_block( string $suffix ): string {
		$url = 'https://www.youtube.com/watch?v=seed242' . $suffix;

		return '<!-- wp:embed {"url":"' . esc_url( $url ) . '","type":"video","providerNameSlug":"youtube"} -->'
			. '<figure class="wp-block-embed is-type-video is-provider-youtube"></figure>'
			. '<!-- /wp:embed -->';
	}

	/**
	 * A post assigned to the video kind.
	 *
	 * @param string               $title   Post title.
	 * @param string               $date    Local post date.
	 * @param string               $content Stored content.
	 * @param array<string, mixed> $args    Extra post arguments.
	 */
	private function video( string $title, string $date, string $content, array $args = [] ): int {
		$id = (int) self::factory()->post->create(
			array_merge(
				[
					'post_status'   => 'publish',
					'post_title'    => $title,
					'post_content'  => $content,
					'post_date'     => $date,
					'post_date_gmt' => get_gmt_from_date( $date ),
				],
				$args
			)
		);
		$this->assertNotWPError( wp_set_object_terms( $id, 'video', Taxonomy::TAXONOMY ) );

		return $id;
	}

	/**
	 * Seed the frozen archive plan.
	 *
	 * @return array<string, int|string>
	 */
	private function seed_plan(): array {
		$m1       = $this->video_attachment( '2026/08/seed-242-m1.mp4', 134 );
		$m2       = $this->video_attachment( '2026/08/seed-242-m2.mp4', 3725 );
		$m3       = $this->video_attachment( '2026/08/seed-242-m3.mp4', null );
		$poster   = $this->image_attachment( '2026/08/seed-242-p1.png', 'A still frame' );
		$featured = $this->image_attachment( '2026/08/seed-242-f1.png', 'A feature frame' );
		$local    = $this->video_block( $m1 );
		$poster_block = $this->video_block(
			$m1,
			'<figure class="wp-block-video"><video controls src="' . esc_url( (string) wp_get_attachment_url( $m1 ) ) . '" poster="' . esc_url( (string) wp_get_attachment_url( $poster ) ) . '"></video></figure>'
		);
		$uploads = wp_get_upload_dir();
		$track   = trailingslashit( (string) $uploads['baseurl'] ) . '2026/08/seed-242-t1.vtt';
		$tracks  = $this->video_block(
			$m1,
			'<figure class="wp-block-video"><video src="' . esc_url( (string) wp_get_attachment_url( $m1 ) ) . '"><track kind="captions" srclang="en" label="English" src="' . esc_url( $track ) . '"></video></figure>'
		);
		$remote = $this->video_block( 0, '<figure class="wp-block-video"><video src="https://media.example.org/seed-242.mp4"></video></figure>' );

		$ids          = [];
		$ids['x01']   = $this->video( 'Seed Locked Reel', '2026-09-30 10:00:00', $local, [ 'post_password' => 'seed-242' ] );
		$ids['v01']   = $this->video( 'Seed Orchard Walk', '2026-09-18 10:00:00', $local );
		set_post_thumbnail( (int) $ids['v01'], $featured );
		$ids['v02']   = $this->video( 'Seed Poster Study', '2026-08-17 10:00:00', $poster_block );
		$ids['v03']   = $this->video( 'Seed Long Workshop', '2026-07-03 10:00:00', $this->video_block( $m2 ) );
		$ids['v04']   = $this->video( 'Seed Unmeasured Clip', '2026-06-12 10:00:00', $this->video_block( $m3 ) );
		$ids['v05']   = $this->video( 'Seed Garden Embed', '2026-05-09 10:00:00', $this->embed_block( 'a' ) );
		$ids['v06']   = $this->video( 'Seed Studio Embed', '2026-03-21 10:00:00', $this->embed_block( 'b' ) );
		$ids['v07_title'] = str_pad( 'Seed Ninety Character Reel ', 90, 'x' );
		$ids['v07']   = $this->video( (string) $ids['v07_title'], '2026-01-01 00:10:00', $local );
		$ids['v08']   = $this->video( '', '2025-12-31 23:30:00', $local );
		$ids['v09']   = $this->video( 'Seed Snow Day', '2025-12-14 10:00:00', $local );
		$ids['v10']   = $this->video( 'Seed Snow Day', '2025-11-02 10:00:00', $local );
		$ids['v11']   = $this->video( 'Seed Embed Before File', '2025-09-27 10:00:00', $this->embed_block( 'c' ) . $local );
		$ids['v12']   = $this->video( 'Seed Captioned Reel', '2025-08-08 10:00:00', $tracks );
		$ids['v13']   = $this->video( 'Seed Remote Reel', '2025-06-30 10:00:00', $remote );
		$ids['v14']   = $this->video( 'Seed Kitchen Reel', '2025-05-09 10:00:00', $local );
		$ids['v15']   = $this->video( 'Seed Intro Reel', '2025-02-14 10:00:00', '<!-- wp:paragraph --><p>Before the player.</p><!-- /wp:paragraph -->' . $local );
		$ids['v16']   = $this->video( 'Seed Old Reel', '2024-11-23 10:00:00', $local );
		$ids['x02']   = $this->video( 'Seed Private Control', '2026-10-01 10:00:00', $local, [ 'post_status' => 'private' ] );
		$ids['x03']   = $this->video( 'Seed Draft Control', '2026-10-02 10:00:00', $local, [ 'post_status' => 'draft' ] );
		$ids['x04']   = $this->video( 'Seed Note Control', '2026-10-03 10:00:00', $local );
		$this->assertNotWPError( wp_set_object_terms( (int) $ids['x04'], 'note', Taxonomy::TAXONOMY ) );
		$ids['m1_url'] = (string) wp_get_attachment_url( $m1 );

		return $ids;
	}

	public function test_frozen_archive_plan_pages_by_twelve_and_repeats_a_continuing_year(): void {
		$ids   = $this->seed_plan();
		$page1 = $this->serve( $this->archive_url() );
		$one   = $this->sections( $page1 );

		$this->assertSame( [ '2026', '2025' ], array_column( $one, 'group' ) );
		$this->assertSame( [ '2026', '2025' ], array_column( $one, 'heading' ) );
		$this->assertCount( 8, $one[0]['titles'] );
		$this->assertCount( 4, $one[1]['titles'] );
		$this->assertSame(
			[
				'Protected: Seed Locked Reel',
				'Seed Orchard Walk',
				'Seed Poster Study',
				'Seed Long Workshop',
				'Seed Unmeasured Clip',
				'Seed Garden Embed',
				'Seed Studio Embed',
				$ids['v07_title'],
			],
			$one[0]['titles']
		);
		$this->assertSame(
			[
				\PKIW\untitled_name( get_post( (int) $ids['v08'] ), false ),
				'Seed Snow Day',
				'Seed Snow Day',
				'Seed Embed Before File',
			],
			$one[1]['titles']
		);
		$this->assertSame( 12, $this->xpath( $page1 )->query( '//li[contains(concat(" ", normalize-space(@class), " "), " wp-block-post ")]' )->length );
		$this->assertSame( 17, $GLOBALS['wp_query']->found_posts );
		$this->assertSame( 2, $GLOBALS['wp_query']->max_num_pages );

		$page2 = $this->serve( $this->archive_url() . 'page/2/' );
		$two   = $this->sections( $page2 );
		$this->assertSame( [ '2025', '2024' ], array_column( $two, 'group' ) );
		$this->assertSame( [ '2025', '2024' ], array_column( $two, 'heading' ) );
		$this->assertCount( 4, $two[0]['titles'] );
		$this->assertSame( [ 'Seed Captioned Reel', 'Seed Remote Reel', 'Seed Kitchen Reel', 'Seed Intro Reel' ], $two[0]['titles'] );
		$this->assertSame( [ 'Seed Old Reel' ], $two[1]['titles'] );
		$this->assertStringNotContainsString( 'media.example.org', $page2 );
		$this->assertStringNotContainsString( '<iframe', $page2 );

		$this->go_to( $this->archive_url() . 'page/3/' );
		$this->assertTrue( is_404() );
	}

	public function test_archive_uses_local_dates_descending_and_excludes_nonpublic_controls(): void {
		$ids  = $this->seed_plan();
		$html = $this->serve( $this->archive_url() );
		$all  = array_merge( ...array_column( $this->sections( $html ), 'titles' ) );

		$this->assertContains( \PKIW\untitled_name( get_post( (int) $ids['v08'] ), false ), $all );
		$this->assertNotContains( 'Seed Private Control', $all );
		$this->assertNotContains( 'Seed Draft Control', $all );
		$this->assertNotContains( 'Seed Note Control', $all );
		$this->assertLessThan( array_search( 'Seed Snow Day', $all, true ), array_search( \PKIW\untitled_name( get_post( (int) $ids['v08'] ), false ), $all, true ) );
		$this->assertStringNotContainsString( 'media.example.org', $html );
		$this->assertStringNotContainsString( '<iframe', $html );
	}

	public function test_protected_archive_card_is_only_a_title_link_with_no_media_or_runtime(): void {
		$ids   = $this->seed_plan();
		$html  = $this->serve( $this->archive_url() );
		$xpath = $this->xpath( $html );
		$nodes = $xpath->query( '//li[.//*[contains(normalize-space(.), "Seed Locked Reel")]]' );
		$this->assertSame( 1, $nodes->length );
		$item = $nodes->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $item );
		$item_html = (string) $item->ownerDocument->saveHTML( $item );

		$this->assertSame( 1, $xpath->query( './/a', $item )->length );
		$this->assertStringNotContainsString( 'u-video', $item_html );
		$this->assertStringNotContainsString( '<video', $item_html );
		$this->assertStringNotContainsString( (string) $ids['m1_url'], $item_html );
		$this->assertStringNotContainsString( 'PT2M14S', $item_html );
	}

	public function test_one_video_makes_one_year_section_and_no_pager(): void {
		$media = $this->video_attachment( '2026/08/seed-242-one.mp4', 134 );
		$this->video( 'Seed Orchard Walk', '2026-09-18 10:00:00', $this->video_block( $media ) );

		$html     = $this->serve( $this->archive_url() );
		$sections = $this->sections( $html );

		$this->assertSame( [ [ 'group' => '2026', 'heading' => '2026', 'titles' => [ 'Seed Orchard Walk' ] ] ], $sections );
		$this->assertStringNotContainsString( 'wp-block-query-pagination-next', $html );
		$this->assertStringNotContainsString( 'wp-block-query-pagination-previous', $html );
	}

	public function test_empty_archive_prints_one_h1_and_no_year_section(): void {
		$html = $this->serve( $this->archive_url() );

		$this->assertStringContainsString( 'No videos yet.', $html );
		$this->assertSame( 1, substr_count( $html, '<h1' ) );
		$this->assertSame( [], $this->sections( $html ) );
		$this->assertStringNotContainsString( 'pkiw-group__heading', $html );
	}
}
