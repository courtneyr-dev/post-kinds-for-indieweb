<?php
/**
 * Chicken archive entry block (issue 250).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Chicken_Archive;
use PKIW\Grouped_Archive;
use PKIW\Plugin;
use PKIW\Taxonomy;

/**
 * The chicken entry block renders month-sectioned chicken archive entries.
 *
 * @group integration
 */
final class ChickenEntryBlockTest extends WP_UnitTestCase {

	/**
	 * Stylesheet active before each test.
	 *
	 * @var string
	 */
	private string $original_stylesheet = '';

	/**
	 * Original timezone.
	 *
	 * @var string
	 */
	private string $original_timezone = '';

	/**
	 * Original microformats map.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $original_kind_formats = null;

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		$this->original_timezone   = (string) get_option( 'timezone_string' );
		update_option( 'timezone_string', 'America/New_York' );
		switch_theme( 'twentytwentyfive' );
		( new Taxonomy() )->create_default_terms();
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
	}

	public function tear_down(): void {
		if ( null !== $this->original_kind_formats ) {
			$property = new ReflectionProperty( Plugin::get_instance()->get_microformats(), 'kind_formats' );
			$property->setAccessible( true );
			$property->setValue( Plugin::get_instance()->get_microformats(), $this->original_kind_formats );
		}
		update_option( 'timezone_string', $this->original_timezone );
		switch_theme( $this->original_stylesheet );
		$this->set_permalink_structure( '' );
		wp_set_current_user( 0 );
		wp_reset_postdata();
		parent::tear_down();
	}

	public function test_chicken_entry_block_registration(): void {
		$block = WP_Block_Type_Registry::get_instance()->get_registered( Chicken_Archive::ENTRY );

		$this->assertNotNull( $block );
		$this->assertTrue( Grouped_Archive::is_entry_block( Chicken_Archive::ENTRY ) );
		$this->assertSame( 24, $block->attributes['linesPerPage']['default'] );
		$this->assertSame( 2, $block->attributes['headingLevel']['default'] );
		$this->assertContains( 'core/post-template', $block->ancestor );
		$this->assertContains( 'pkiw-chicken-entry-editor', $block->editor_script_handles );
		$this->assertContains( 'pkiw-chicken-entry', $block->style_handles );
		$this->assertTrue( wp_script_is( 'pkiw-chicken-entry-editor', 'registered' ) );
		$this->assertFileExists( PKIW_PATH . 'styles/chicken-entry.css' );
	}

	public function test_archive_page_renders_month_sections_and_ordered_entries(): void {
		$post_id = $this->chicken( 'Hen at the gate', '2026-10-10 09:00:00' );

		$html  = $this->serve( $this->archive_url() );
		$xpath = $this->xpath( $html );

		$this->assertSame( 1, $xpath->query( '//section[contains(concat(" ",@class," ")," pkiw-chicken__month ")]/h2[contains(concat(" ",@class," ")," pkiw-chicken__month-label ")]/following-sibling::ol[contains(concat(" ",@class," ")," pkiw-chicken__entries ")]/li[contains(concat(" ",@class," ")," h-entry ")]' )->length );

		$item = $xpath->query( '//ol[contains(concat(" ",@class," ")," pkiw-chicken__entries ")]/li' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $item );
		$children = $this->element_names( $item );
		$this->assertSame( [ 'time', 'h3', 'span' ], $children );

		$time = $xpath->query( './time[contains(concat(" ",@class," ")," dt-published ")]', $item )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $time );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $time->getAttribute( 'datetime' ) );
		$this->assertSame( get_post_time( 'c', true, get_post( $post_id ) ), $time->getAttribute( 'datetime' ) );
	}

	public function test_heading_levels_follow_section_state_and_clamp_items(): void {
		$this->chicken( 'Sectioned hen', '2026-10-10 09:00:00' );

		$default = $this->xpath( $this->serve( $this->archive_url() ) );
		$this->assertSame( 1, $default->query( '//section[contains(@class,"pkiw-chicken__month")]/h2' )->length );
		$this->assertSame( 1, $default->query( '//section[contains(@class,"pkiw-chicken__month")]//h3[contains(@class,"pkiw-chicken__title")]' )->length );

		$flat = $this->xpath( $this->render_template_with_block_attrs( [ 'showSections' => false ] ) );
		$this->assertSame( 0, $flat->query( '//section[contains(@class,"pkiw-chicken__month")]' )->length );
		$this->assertSame( 1, $flat->query( '//h2[contains(@class,"pkiw-chicken__title")]' )->length );

		$raised = $this->xpath( $this->render_template_with_block_attrs( [ 'headingLevel' => 3 ] ) );
		$this->assertSame( 1, $raised->query( '//section[contains(@class,"pkiw-chicken__month")]/h3' )->length );
		$this->assertSame( 1, $raised->query( '//section[contains(@class,"pkiw-chicken__month")]//h4[contains(@class,"pkiw-chicken__title")]' )->length );

		$clamped = $this->xpath( $this->render_template_with_block_attrs( [ 'headingLevel' => 6 ] ) );
		$this->assertSame( 1, $clamped->query( '//h6[contains(@class,"pkiw-chicken__title")]' )->length );
	}

	public function test_archive_entry_markup_is_the_single_link_summary_contract(): void {
		$id = $this->chicken( 'Hen at the gate', '2026-10-10 09:00:00', '<!-- wp:paragraph --><p>Excerpt words here.</p><!-- /wp:paragraph -->' );
		update_post_meta( $id, 'geo_address', '12 Example Lane, Sampletown' );
		update_post_meta( $id, 'geo_latitude', '0.5' );
		update_post_meta( $id, 'geo_longitude', '-30.5' );

		$html  = $this->serve( $this->archive_url() );
		$xpath = $this->xpath( $html );
		$item  = $xpath->query( '//ol[contains(@class,"pkiw-chicken__entries")]/li' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $item );

		$this->assertSame( 1, $xpath->query( './/a[@href][not(ancestor-or-self::*[@hidden])]', $item )->length );
		$this->assertSame( 0, $xpath->query( './/a[@tabindex="-1"][not(ancestor-or-self::*[@hidden])]', $item )->length );
		foreach ( [ '//img', 'Excerpt words here', '12 Example Lane', '0.5', 'comment' ] as $absent ) {
			if ( str_starts_with( $absent, '//' ) ) {
				$this->assertSame( 0, $xpath->query( $absent, $item )->length );
				continue;
			}
			$this->assertStringNotContainsString( $absent, $this->node_html( $item ) );
		}
	}

	public function test_unique_duplicate_and_untitled_accessible_names(): void {
		$unique = $this->chicken( 'Coop doorway', '2026-10-10 09:00:00' );
		$first  = $this->chicken( 'Hen at the gate', '2026-10-09 08:00:00' );
		$second = $this->chicken( 'Hen at the gate', '2026-10-08 08:00:00' );
		$early  = $this->chicken( 'Backyard visitor', '2026-10-07 08:00:00' );
		$late   = $this->chicken( 'Backyard visitor', '2026-10-07 17:00:00' );
		$empty  = $this->chicken( '', '2026-10-06 08:00:00' );

		$xpath = $this->xpath( $this->serve( $this->archive_url() ) );

		$this->assertSame( '', $this->entry_link( $xpath, $unique )->getAttribute( 'aria-labelledby' ) );
		foreach ( [ $first, $second ] as $id ) {
			$link  = $this->entry_link( $xpath, $id );
			$names = explode( ' ', $link->getAttribute( 'aria-labelledby' ) );
			$this->assertCount( 2, $names );
			$this->assertSame( $link->getAttribute( 'id' ), $names[0] );
			foreach ( $names as $name ) {
				$this->assertSame( 1, $xpath->query( '//*[@id="' . $name . '"]' )->length );
			}
		}

		$this->assertNotSame( $this->labelled_text( $xpath, $this->entry_link( $xpath, $early ) ), $this->labelled_text( $xpath, $this->entry_link( $xpath, $late ) ) );
		$this->assertSame( 'Chicken', trim( $this->entry_link( $xpath, $empty )->textContent ) );
		$this->assertNotSame( '', $this->entry_link( $xpath, $empty )->getAttribute( 'aria-labelledby' ) );
		$this->assertStringNotContainsString( 'p-name', $this->entry_link( $xpath, $empty )->getAttribute( 'class' ) );
	}

	public function test_duplicate_titles_split_across_pages_do_not_get_aria_labelledby(): void {
		$page_one = $this->chicken( 'Split duplicate', '2026-10-10 10:00:00' );
		foreach ( range( 1, 23 ) as $i ) {
			$this->chicken( 'Filler ' . $i, sprintf( '2026-09-%02d 10:00:00', 28 - $i ) );
		}
		$page_two = $this->chicken( 'Split duplicate', '2026-08-01 10:00:00' );

		$first  = $this->xpath( $this->serve( $this->archive_url() ) );
		$second = $this->xpath( $this->serve( $this->archive_url() . 'page/2/' ) );

		$this->assertSame( '', $this->entry_link( $first, $page_one )->getAttribute( 'aria-labelledby' ) );
		$this->assertSame( '', $this->entry_link( $second, $page_two )->getAttribute( 'aria-labelledby' ) );
	}

	public function test_p_name_follows_the_microformats_map_only_for_titled_posts(): void {
		$titled   = $this->chicken( 'Named chicken', '2026-10-10 10:00:00' );
		$untitled = $this->chicken( '', '2026-10-09 10:00:00' );

		$default = $this->xpath( $this->serve( $this->archive_url() ) );
		$this->assertStringNotContainsString( 'p-name', $this->entry_link( $default, $titled )->getAttribute( 'class' ) );

		$this->add_chicken_p_name_to_microformats();
		$changed = $this->xpath( $this->serve( $this->archive_url() ) );
		$this->assertStringContainsString( 'p-name', $this->entry_link( $changed, $titled )->getAttribute( 'class' ) );
		$this->assertStringNotContainsString( 'p-name', $this->entry_link( $changed, $untitled )->getAttribute( 'class' ) );
	}

	public function test_ids_are_unique_on_a_twenty_four_post_page(): void {
		foreach ( range( 1, 24 ) as $i ) {
			$this->chicken( 'Chicken ' . $i, sprintf( '2026-10-%02d 10:00:00', 25 - $i ) );
		}
		$xpath = $this->xpath( $this->serve( $this->archive_url() ) );
		$ids   = [];
		foreach ( $xpath->query( '//*[@id]' ) as $node ) {
			$ids[] = $node->getAttribute( 'id' );
		}

		$this->assertSame( $ids, array_values( array_unique( $ids ) ) );
	}

	public function test_password_protected_post_prints_only_the_protected_title(): void {
		$id = $this->chicken( 'Locked coop', '2026-10-10 10:00:00', '<!-- wp:paragraph --><p>Hidden feed words.</p><!-- /wp:paragraph -->', [ 'post_password' => 'secret' ] );

		$xpath = $this->xpath( $this->serve( $this->archive_url() ) );
		$link  = $this->entry_link( $xpath, $id );

		$this->assertStringStartsWith( 'Protected: ', trim( $link->textContent ) );
		$this->assertStringNotContainsString( 'Hidden feed words', $xpath->document->textContent );
	}

	public function test_block_renderer_returns_the_month_opener_section_and_continued_placeholder(): void {
		$opener = $this->chicken( 'Month opener', '2026-10-10 10:00:00' );
		$member = $this->chicken( 'Month member', '2026-10-09 10:00:00' );

		$open_html = $this->editor_render( $opener );
		$xpath     = $this->xpath( $open_html );
		$this->assertSame( 1, $xpath->query( '//section[contains(@class,"pkiw-chicken__month")]' )->length );
		$this->assertSame( 2, $xpath->query( '//section[contains(@class,"pkiw-chicken__month")]//li' )->length );
		$this->assertSame( get_post_time( 'c', true, get_post( $member ) ), $xpath->query( '//a[@href="' . get_permalink( $member ) . '"]/ancestor::li[1]//time' )->item( 0 )->getAttribute( 'datetime' ) );

		$continued = $this->editor_render( $member );
		$this->assertStringContainsString( 'pkiw-chicken__continued', $continued );
		$this->assertStringContainsString( 'hidden', $continued );
	}

	public function test_archive_items_parse_as_h_entries_without_nested_root_or_name_when_php_mf2_is_available(): void {
		if ( ! function_exists( '\Mf2\parse' ) ) {
			$this->markTestSkipped( 'php-mf2 is not available.' );
		}
		$author = self::factory()->user->create( [ 'display_name' => 'Fictional Keeper' ] );
		$id     = $this->chicken( 'Mf2 chicken', '2026-10-10 10:00:00', '', [ 'post_author' => $author ] );

		$parsed  = \Mf2\parse( $this->serve( $this->archive_url() ), $this->archive_url() );
		$entries = [];
		foreach ( $parsed['items'] as $item ) {
			if ( in_array( 'h-entry', $item['type'], true ) ) {
				$entries[ $item['properties']['url'][0] ?? '' ] = $item;
			}
		}

		$this->assertArrayHasKey( get_permalink( $id ), $entries );
		$entry = $entries[ get_permalink( $id ) ];
		$this->assertArrayHasKey( 'url', $entry['properties'] );
		$this->assertArrayHasKey( 'published', $entry['properties'] );
		$this->assertArrayHasKey( 'author', $entry['properties'] );
		$this->assertArrayNotHasKey( 'name', $entry['properties'] );
		foreach ( $entry['children'] ?? [] as $child ) {
			$this->assertNotContains( 'h-entry', $child['type'] ?? [] );
		}
	}

	/**
	 * Create a chicken post.
	 *
	 * @param string               $title   Post title.
	 * @param string               $date    Post date.
	 * @param string               $content Post content.
	 * @param array<string, mixed> $args    Extra post args.
	 * @return int
	 */
	private function chicken( string $title, string $date, string $content = '', array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_date'    => $date,
					'post_content' => $content,
				],
				$args
			)
		);
		wp_set_object_terms( $id, 'chicken', Taxonomy::TAXONOMY );

		return (int) $id;
	}

	private function archive_url(): string {
		$url = get_term_link( 'chicken', Taxonomy::TAXONOMY );
		$this->assertSame( 'http://example.org/kind/chicken/', $url );

		return $url;
	}

	private function serve( string $url ): string {
		$this->go_to( $url );
		$template = resolve_block_template( 'taxonomy', [ 'taxonomy-kind-chicken.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ], '' );
		$this->assertNotNull( $template );

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;

		return get_the_block_template_html();
	}

	/**
	 * Render the archive with different chicken-entry attributes.
	 *
	 * @param array<string, mixed> $attrs Attributes.
	 * @return string
	 */
	private function render_template_with_block_attrs( array $attrs ): string {
		$this->go_to( $this->archive_url() );
		$template = resolve_block_template( 'taxonomy', [ 'taxonomy-kind-chicken.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ], '' );
		$content  = preg_replace( '/<!-- wp:post-kinds-indieweb\/chicken-entry \{[^}]*\} \/-->/', '<!-- wp:post-kinds-indieweb/chicken-entry ' . wp_json_encode( array_merge( [ 'linesPerPage' => 24 ], $attrs ) ) . ' /-->', $template->content );

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = (string) $content;

		return get_the_block_template_html();
	}

	private function editor_render( int $post_id ): string {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/' . Chicken_Archive::ENTRY );
		$request->set_param( 'context', 'edit' );
		$request->set_param( 'post_id', $post_id );
		$request->set_param( 'pkiw_kind', 'chicken' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		return (string) $response->get_data()['rendered'];
	}

	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();

		return new DOMXPath( $dom );
	}

	/**
	 * @return string[]
	 */
	private function element_names( DOMElement $node ): array {
		$names = [];
		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof DOMElement ) {
				$names[] = $child->nodeName;
			}
		}
		return $names;
	}

	private function node_html( DOMNode $node ): string {
		return $node->ownerDocument instanceof DOMDocument ? (string) $node->ownerDocument->saveHTML( $node ) : '';
	}

	private function entry_link( DOMXPath $xpath, int $post_id ): DOMElement {
		$link = $xpath->query( '//li[contains(concat(" ",@class," ")," post-' . $post_id . ' ")]//a[contains(concat(" ",@class," ")," u-url ")]' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $link );

		return $link;
	}

	private function labelled_text( DOMXPath $xpath, DOMElement $link ): string {
		$text = '';
		foreach ( explode( ' ', $link->getAttribute( 'aria-labelledby' ) ) as $id ) {
			$node = $xpath->query( '//*[@id="' . $id . '"]' )->item( 0 );
			$text .= $node instanceof DOMNode ? trim( $node->textContent ) : '';
		}
		return $text;
	}

	private function add_chicken_p_name_to_microformats(): void {
		$microformats = Plugin::get_instance()->get_microformats();
		$property     = new ReflectionProperty( $microformats, 'kind_formats' );
		$property->setAccessible( true );
		$formats                     = $property->getValue( $microformats );
		$this->original_kind_formats = $formats;
		$formats['chicken']['properties']['name'] = 'p-name';
		$property->setValue( $microformats, $formats );
	}
}
