<?php
/**
 * The review archive: category groups and review entries (issue 252).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Grouped_Archive;
use PKIW\Meta_Fields;
use PKIW\Review_Archive;
use PKIW\Taxonomy;

/**
 * /kind/review/ is served by the plugin's taxonomy-kind-review template.
 *
 * @group integration
 */
final class ReviewArchiveTest extends WP_UnitTestCase {

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
		update_option( 'posts_per_page', 20 );
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
	}

	public function tear_down(): void {
		remove_all_filters( 'the_content', 11 );
		remove_all_filters( 'the_content', 12 );
		switch_theme( $this->original_stylesheet );
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	public function test_sections_run_by_category_name_with_default_category_last(): void {
		$terms = $this->categories();
		$this->review( 'Alpha Review', '2026-01-05 10:00:00', [ 'Alpha Books' ] );
		$this->review( 'Beta Review', '2026-01-04 10:00:00', [ 'beta Places' ] );
		$this->review( 'Gamma Review', '2026-01-03 10:00:00', [ 'Gamma Software' ] );
		$this->review( 'Zeta Review', '2026-01-02 10:00:00', [ 'Zeta Music' ] );
		$this->review( 'Default Review', '2026-01-01 10:00:00' );

		$sections = $this->sections( $this->serve( $this->archive_url() ) );

		$this->assertSame( [ 'h2:Alpha Books', 'h2:beta Places', 'h2:Gamma Software', 'h2:Zeta Music', 'h2:' . $terms['default']->name ], array_column( $sections, 'heading' ) );
	}

	public function test_posts_file_under_the_first_category_default_category_is_last_and_empty_is_other(): void {
		$this->categories();
		$this->review( 'Alpha and Zeta', '2026-01-04 10:00:00', [ 'Zeta Music', 'Alpha Books' ] );
		$this->review( 'Default and Alpha', '2026-01-03 10:00:00', [ 'Default', 'Alpha Books' ] );
		$empty = $this->review( 'No Category', '2026-01-02 10:00:00' );
		wp_delete_object_term_relationships( $empty, [ 'category' ] );

		$sections = $this->sections( $this->serve( $this->archive_url() ) );

		$this->assertSame( [ 'h2:Alpha Books', 'h2:Other' ], array_column( $sections, 'heading' ) );
		$this->assertSame( [ 'Alpha and Zeta', 'Default and Alpha' ], $sections[0]['titles'] );
		$this->assertSame( [ 'No Category' ], $sections[1]['titles'] );
	}

	public function test_password_protected_review_files_under_other_and_prints_only_title(): void {
		wp_set_current_user( 0 );
		$this->categories();
		$locked = $this->review(
			'Space Locked',
			'2026-01-02 10:00:00',
			[ 'Alpha Books' ],
			[
				'item_name' => 'Hidden Item',
			],
			'',
			[ 'post_password' => '  ' ]
		);

		$html  = $this->serve( $this->archive_url() );
		$xpath = $this->xpath( $html );

		$this->assertTrue( Grouped_Archive::group_of_post( 'review', $locked )->is_empty() );
		$this->assertSame( [ 'h2:Other' ], array_column( $this->sections( $html ), 'heading' ) );
		$this->assertSame( 1, $xpath->query( '//h3/a[text()="Protected: Space Locked"]' )->length );
		$this->assertStringNotContainsString( 'Hidden Item', $html );
		$this->assertSame( 0, $xpath->query( '//img' )->length );
	}

	public function test_nine_reviews_fill_a_page_counts_stay_ungrouped_and_crossing_section_repeats(): void {
		$this->categories();
		foreach ( range( 1, 12 ) as $i ) {
			$this->review(
				'Alpha Review ' . $i,
				sprintf( '2026-01-%02d 10:00:00', 20 - $i ),
				[ $i <= 10 ? 'Alpha Books' : 'Gamma Software' ]
			);
		}

		$first = $this->sections( $this->serve( $this->archive_url() ) );
		global $wp_query;
		$this->assertSame( 9, (int) $wp_query->get( 'posts_per_page' ) );
		$this->assertSame( 12, $wp_query->found_posts );
		$this->assertSame( 2, $wp_query->max_num_pages );
		$this->assertSame( 9, $wp_query->post_count );

		$count_query = new WP_Query(
			[
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'tax_query'      => [ [ 'taxonomy' => Taxonomy::TAXONOMY, 'field' => 'slug', 'terms' => 'review' ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			]
		);
		$this->assertSame( $count_query->found_posts, $wp_query->found_posts );
		wp_reset_postdata();

		$second = $this->sections( $this->serve( $this->archive_url() . 'page/2/' ) );
		$this->assertSame( 3, $GLOBALS['wp_query']->post_count );
		$this->assertSame( 'h2:Alpha Books', $first[0]['heading'] );
		$this->assertSame( 'h2:Alpha Books', $second[0]['heading'], 'The category crossing the page break opens again on page 2.' );
	}

	public function test_section_headings_are_raw_term_names_with_unique_ids_and_section_counts(): void {
		$this->categories();
		$this->review( 'Alpha Review', '2026-01-02 10:00:00', [ 'Alpha Books' ] );
		$this->review( 'Beta Review', '2026-01-01 10:00:00', [ 'beta Places' ] );

		$sections = $this->sections( $this->serve( $this->archive_url() ) );

		$this->assertSame( [ 'h2:Alpha Books', 'h2:beta Places' ], array_column( $sections, 'heading' ) );
		$this->assertSame( [ 'pkiw-group-alpha-books', 'pkiw-group-beta-places' ], array_column( $sections, 'id' ) );
		$this->assertSame( [ '2', '2' ], array_column( $sections, 'count' ) );
	}

	public function test_review_entry_markup_contains_only_the_archive_summary_contract(): void {
		$this->categories();
		$id         = $this->review(
			'Lantern Review',
			'2026-01-02 10:00:00',
			[ 'Alpha Books' ],
			[
				'item_name' => 'Fictional Lantern',
				'item_url'  => 'https://items.example.com/lantern',
				'rating'    => 4,
			],
			'<!-- wp:pullquote --><figure class="wp-block-pullquote"><blockquote><p>Pull quote text.</p></blockquote></figure><!-- /wp:pullquote -->'
		);
		$attachment = self::factory()->attachment->create_object( 'lantern.png', 0, [ 'post_mime_type' => 'image/png' ] );
		set_post_thumbnail( $id, $attachment );

		$html  = $this->serve( $this->archive_url() );
		$xpath = $this->xpath( $html );
		$item  = $xpath->query( '//section[@data-pkiw-sections]/ul/li' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $item );

		$link = $xpath->query( './/h3[contains(@class,"pkiw-review-entry__title") and contains(@class,"p-name")]/a[contains(@class,"u-url")]', $item )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $link );
		$this->assertSame( get_permalink( $id ), $link->getAttribute( 'href' ) );
		$this->assertSame( 'Lantern Review', trim( $link->textContent ) );
		$this->assertSame( 'Fictional Lantern', trim( $xpath->query( './/p[contains(@class,"pkiw-review-entry__item")]', $item )->item( 0 )->textContent ) );
		$this->assertSame( '', $xpath->query( './/img[contains(@class,"pkiw-review-entry__cover")]', $item )->item( 0 )->getAttribute( 'alt' ) );
		$this->assertSame( 1, $xpath->query( './/data[contains(@class,"dt-published") and @hidden]', $item )->length );
		$this->assertSame( 1, $xpath->query( './/span[@hidden]//*[contains(@class,"p-author")]', $item )->length );

		$item_html = $this->node_html( $item );
		foreach ( [ 'Rated', 'Pull quote text', 'https://items.example.com/lantern', '2026', 'Alpha Books' ] as $absent ) {
			$this->assertStringNotContainsString( $absent, $item_html );
		}
		$this->assertStringNotContainsString( 'pk-stars', $item_html );
		$this->assertSame( '', trim( $xpath->query( './/span[@hidden]', $item )->item( 0 )->textContent ), 'The author is present only in a hidden wrapper.' );
	}

	public function test_untitled_review_heading_uses_synthetic_name_without_p_name(): void {
		$id = $this->review( '', '2026-01-02 10:00:00', [ 'Alpha Books' ] );

		$html = $this->serve( $this->archive_url() );
		$name = \PKIW\untitled_name( get_post( $id ) );

		$this->assertStringContainsString( '>' . esc_html( $name ) . '</a>', $html );
		$this->assertStringNotContainsString( 'pkiw-review-entry__title p-name', $html );
	}

	public function test_editor_preview_returns_a_section_for_the_review_entry_block(): void {
		$this->categories();
		$id = $this->review( 'Alpha Review', '2026-01-02 10:00:00', [ 'Alpha Books' ] );

		$this->assertStringContainsString( '<section', $this->editor_render( $id ) );
	}

	public function test_the_review_feed_stays_newest_first_without_group_query_vars(): void {
		$new = $this->review( 'New Review', '2026-01-03 10:00:00', [ 'Zeta Music' ] );
		$old = $this->review( 'Old Review', '2026-01-01 10:00:00', [ 'Alpha Books' ] );

		$this->go_to( get_term_feed_link( get_term_by( 'slug', 'review', Taxonomy::TAXONOMY )->term_id, Taxonomy::TAXONOMY ) );

		$this->assertTrue( is_feed() );
		$this->assertSame( [ $new, $old ], array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) ) );
		$this->assertSame( '', (string) $GLOBALS['wp_query']->get( 'pkiw_group_source' ) );
	}

	public function test_star_rating_guard_removes_microformat_classes_only_for_review_posts(): void {
		$review = $this->review( 'Star Review', '2026-01-02 10:00:00' );
		$watch  = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Watch Post' ] );
		wp_set_object_terms( $watch, 'watch', Taxonomy::TAXONOMY );

		$current = '<div class="star-rating-block size-medium style-stars"><div class="star-rating-inner h-review"><span class="screen-reader-text">Rating: 4 of 5</span><div class="rating-display">★★★★☆</div><span class="rating-value">4 / 5</span><data class="p-rating" value="4"><data class="p-best" value="5" hidden></data><data class="p-worst" value="0" hidden></data></data><span class="p-item h-product" hidden><a href="https://items.example.com/one" class="p-name u-url">Fictional Lamp</a></span></div></div>';
		$legacy  = '<div class="star-rating-block size-medium style-stars"><div class="star-rating-inner h-review" aria-label="Rating: 4 out of 5"><div class="rating-display">★★★★☆</div><span class="rating-value">4 / 5</span><data class="p-rating" value="4"><data class="p-best" value="5" hidden></data><data class="p-worst" value="0" hidden></data></data><span class="p-item h-product" hidden><span class="p-name">Fictional Lamp</span></span></div></div>';

		foreach ( [ $current, $legacy ] as $markup ) {
			$out = $this->render_star_rating_for_post( $review, $markup );
			foreach ( [ 'h-review', 'p-rating', 'p-best', 'p-worst', 'p-item', 'h-product', 'p-name', 'u-url' ] as $class ) {
				$this->assertSame( 0, preg_match( '/class="[^"]*\b' . preg_quote( $class, '/' ) . '\b/', $out ), $class );
			}
			$this->assertStringContainsString( 'rating-display', $out );
			$this->assertStringContainsString( 'Fictional Lamp', $out );
		}

		$this->assertSame( $current, $this->render_star_rating_for_post( $watch, $current ) );
	}

	public function test_simple_location_content_callbacks_are_suppressed_only_on_review_singles(): void {
		require_once dirname( __DIR__ ) . '/fixtures/Geo_Data_Stub.php';
		$review = $this->review( 'Map Review', '2026-01-02 10:00:00' );
		$watch  = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Map Watch' ] );
		wp_set_object_terms( $watch, 'watch', Taxonomy::TAXONOMY );

		$this->hook_geo_data( [ 'Geo_Data', 'content_map' ], [ 'Geo_Data', 'location_content' ] );
		$this->go_to( get_permalink( $review ) );
		( new Review_Archive() )->suppress_simple_location();
		$this->assertFalse( has_filter( 'the_content', [ 'Geo_Data', 'content_map' ] ) );
		$this->assertFalse( has_filter( 'the_content', [ 'Geo_Data', 'location_content' ] ) );

		$map      = [ new Geo_Data(), 'content_map' ];
		$location = [ new Geo_Data(), 'location_content' ];
		$this->hook_geo_data( $map, $location );
		$this->go_to( get_permalink( $review ) );
		( new Review_Archive() )->suppress_simple_location();
		$this->assertFalse( has_filter( 'the_content', $map ) );
		$this->assertFalse( has_filter( 'the_content', $location ) );

		$this->hook_geo_data( [ 'Geo_Data', 'content_map' ], [ 'Geo_Data', 'location_content' ] );
		$this->go_to( get_permalink( $watch ) );
		( new Review_Archive() )->suppress_simple_location();
		$this->assertNotFalse( has_filter( 'the_content', [ 'Geo_Data', 'content_map' ] ) );
		$this->assertNotFalse( has_filter( 'the_content', [ 'Geo_Data', 'location_content' ] ) );

		$this->go_to( $this->archive_url() );
		( new Review_Archive() )->suppress_simple_location();
		$this->assertNotFalse( has_filter( 'the_content', [ 'Geo_Data', 'content_map' ] ) );
		$this->assertNotFalse( has_filter( 'the_content', [ 'Geo_Data', 'location_content' ] ) );
	}

	public function test_review_entry_block_registration(): void {
		$block = WP_Block_Type_Registry::get_instance()->get_registered( Review_Archive::ENTRY_BLOCK );

		$this->assertNotNull( $block );
		$this->assertTrue( Grouped_Archive::is_entry_block( Review_Archive::ENTRY_BLOCK ) );
		$this->assertContains( 'pkiw-review-entry-editor', $block->editor_script_handles );
		$this->assertTrue( wp_script_is( 'pkiw-review-entry-editor', 'registered' ) );
		$this->assertSame( 9, $block->attributes['linesPerPage']['default'] );
	}

	/**
	 * Create the fixture categories.
	 *
	 * @return array<string, WP_Term>
	 */
	private function categories(): array {
		$default = get_term( (int) get_option( 'default_category' ), 'category' );
		$terms   = [ 'default' => $default ];
		foreach ( [ 'Alpha Books', 'beta Places', 'Gamma Software', 'Zeta Music' ] as $name ) {
			$terms[ $name ] = self::factory()->category->create_and_get(
				[
					'name' => $name,
					'slug' => sanitize_title( $name ),
				]
			);
		}

		return $terms;
	}

	/**
	 * Create a review post.
	 *
	 * @param string               $title      Post title.
	 * @param string               $date       Post date.
	 * @param string[]             $categories Category names.
	 * @param array<string, mixed> $meta       Review meta suffix => value.
	 * @param string               $content    Post content.
	 * @param array<string, mixed> $post       Extra post fields.
	 * @return int
	 */
	private function review( string $title, string $date = '2026-01-01 10:00:00', array $categories = [], array $meta = [], string $content = '', array $post = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_date'    => $date,
					'post_content' => $content,
				],
				$post
			)
		);
		wp_set_object_terms( $id, 'review', Taxonomy::TAXONOMY );
		if ( [] !== $categories ) {
			$term_ids = [];
			foreach ( $categories as $name ) {
				if ( 'Default' === $name ) {
					$term_ids[] = (int) get_option( 'default_category' );
					continue;
				}
				$term = get_term_by( 'name', $name, 'category' );
				if ( $term instanceof WP_Term ) {
					$term_ids[] = (int) $term->term_id;
				}
			}
			wp_set_post_categories( $id, $term_ids );
		}
		foreach ( $meta as $suffix => $value ) {
			update_post_meta( $id, Meta_Fields::PREFIX . 'review_' . $suffix, $value );
		}

		return $id;
	}

	private function archive_url(): string {
		$url = get_term_link( 'review', Taxonomy::TAXONOMY );
		$this->assertSame( 'http://example.org/kind/review/', $url );

		return $url;
	}

	/**
	 * Render the archive URL through the resolved plugin template.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function serve( string $url ): string {
		$this->go_to( $url );
		$template = resolve_block_template( 'taxonomy', [ 'taxonomy-kind-review.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ], '' );
		$this->assertNotNull( $template );

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;

		return get_the_block_template_html();
	}

	/**
	 * Parse HTML.
	 *
	 * @param string $html HTML.
	 * @return DOMXPath
	 */
	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();

		return new DOMXPath( $dom );
	}

	/**
	 * Serialize a parsed node.
	 *
	 * @param DOMNode $node Node.
	 * @return string
	 */
	private function node_html( DOMNode $node ): string {
		return $node->ownerDocument instanceof DOMDocument ? (string) $node->ownerDocument->saveHTML( $node ) : '';
	}

	/**
	 * Review sections in document order.
	 *
	 * @param string $html HTML.
	 * @return array<int, array{heading:string,id:string,count:string,titles:string[]}>
	 */
	private function sections( string $html ): array {
		$xpath = $this->xpath( $html );
		$out   = [];
		foreach ( $xpath->query( '//section[@data-pkiw-sections]' ) as $section ) {
			$heading = $xpath->query( './*[1]', $section )->item( 0 );
			$titles  = [];
			foreach ( $xpath->query( './/h3/a', $section ) as $title ) {
				$titles[] = trim( $title->textContent );
			}
			$out[] = [
				'heading' => $heading instanceof DOMElement ? $heading->nodeName . ':' . trim( $heading->textContent ) : '',
				'id'      => $heading instanceof DOMElement ? $heading->getAttribute( 'id' ) : '',
				'count'   => $section instanceof DOMElement ? $section->getAttribute( 'data-pkiw-sections' ) : '',
				'titles'  => $titles,
			];
		}

		return $out;
	}

	/**
	 * Render one review-entry block through the block renderer.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function editor_render( int $post_id ): string {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/' . Review_Archive::ENTRY_BLOCK );
		$request->set_param( 'context', 'edit' );
		$request->set_param( 'post_id', $post_id );
		$request->set_param( 'pkiw_kind', 'review' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		return (string) $response->get_data()['rendered'];
	}

	/**
	 * Render saved star-rating markup for one post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $markup  Saved block markup.
	 * @return string
	 */
	private function render_star_rating_for_post( int $post_id, string $markup ): string {
		global $post;
		$post = get_post( $post_id );
		setup_postdata( $post );
		try {
			return render_block(
				[
					'blockName'    => 'post-kinds-indieweb/star-rating',
					'attrs'        => [],
					'innerBlocks'  => [],
					'innerHTML'    => $markup,
					'innerContent' => [ $markup ],
				]
			);
		} finally {
			wp_reset_postdata();
		}
	}

	/**
	 * Hook Simple Location callbacks at the priorities this lane suppresses.
	 *
	 * @param callable $map      Map callback.
	 * @param callable $location Location callback.
	 * @return void
	 */
	private function hook_geo_data( callable $map, callable $location ): void {
		remove_all_filters( 'the_content', 11 );
		remove_all_filters( 'the_content', 12 );
		add_filter( 'the_content', $map, 11 );
		add_filter( 'the_content', $location, 12 );
	}
}
