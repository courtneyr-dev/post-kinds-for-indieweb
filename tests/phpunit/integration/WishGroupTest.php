<?php
/**
 * Wish archive grouping, ordering, privacy and pagination.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

use PKIW\Grouped_Archive;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;
use PKIW\Wish_Archive;

/**
 * Exercises the real wish Meta_Source through PHP, SQL, REST and block rendering.
 *
 * @since 1.9.0
 *
 * @group integration
 */
final class WishGroupTest extends WP_UnitTestCase {

	/**
	 * Archive sections block name.
	 *
	 * @since 1.9.0
	 *
	 * @var string
	 */
	private const MARKER = 'post-kinds-indieweb/archive-sections';

	/**
	 * Stylesheet active before each test.
	 *
	 * @since 1.9.0
	 *
	 * @var string
	 */
	private string $original_stylesheet = '';

	/**
	 * Prepare the real wish archive request environment.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		update_option( 'posts_per_page', 10 );
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
		add_filter( 'pre_http_request', '__return_empty_array' );
	}

	/**
	 * Restore shared request, rewrite and theme state.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		$this->set_permalink_structure( '' );
		switch_theme( $this->original_stylesheet );
		parent::tear_down();
	}

	/**
	 * Serialize one wish card comment.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, mixed> $attrs Card attributes.
	 * @return string Block comment.
	 */
	private function card( array $attrs ): string {
		return '<!-- wp:post-kinds-indieweb/wish-card ' . serialize_block_attributes( $attrs ) . ' /-->';
	}

	/**
	 * Create one kind post with optional raw meta rows.
	 *
	 * @since 1.9.0
	 *
	 * @param string                $title   Post title.
	 * @param string                $date    Post date.
	 * @param array<string, string> $meta    Full meta key to value map.
	 * @param array<string, mixed>  $args    Extra post arguments.
	 * @param string                $kind    Kind slug.
	 * @return int Post ID.
	 */
	private function post( string $title, string $date, array $meta = [], array $args = [], string $kind = 'wish' ): int {
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
		foreach ( $meta as $key => $value ) {
			delete_post_meta( $id, $key );
			add_post_meta( $id, $key, $value );
		}

		return $id;
	}

	/**
	 * An inheriting Query Loop with the marker before each title.
	 *
	 * @since 1.9.0
	 *
	 * @param int  $lines_per_page Marker page size.
	 * @param bool $stream         Render Stream cards instead of core titles.
	 * @return string Template content.
	 */
	private function template( int $lines_per_page = 20, bool $stream = false ): string {
		$entry = $stream
			? '<!-- wp:post-kinds-indieweb/stream-card {"headingLevel":3} /-->'
			: '<!-- wp:post-title {"level":3} /-->';

		return '<!-- wp:query {"queryId":238,"query":{"inherit":true}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:'
			. self::MARKER . ' {"linesPerPage":' . $lines_per_page . '} /-->' . $entry . '<!-- /wp:post-template --></div><!-- /wp:query -->';
	}

	/**
	 * Serve the wish archive through a supplied block template.
	 *
	 * @since 1.9.0
	 *
	 * @param string $template Template content.
	 * @param int    $page     Page number.
	 * @return string Rendered HTML.
	 */
	private function serve( string $template, int $page = 1 ): string {
		$swap = static function ( $templates ) use ( $template ) {
			foreach ( $templates as $found ) {
				if ( in_array( $found->slug, [ 'taxonomy-kind', 'taxonomy-kind-wish' ], true ) ) {
					$found->content = $template;
				}
			}

			return $templates;
		};
		add_filter( 'get_block_templates', $swap );
		$url = get_term_link( 'wish', Taxonomy::TAXONOMY );
		$this->assertIsString( $url );
		$this->go_to( 1 < $page ? add_query_arg( 'paged', $page, $url ) : $url );
		$html = do_blocks( $template );
		remove_filter( 'get_block_templates', $swap );

		return $html;
	}

	/**
	 * Section heading and title lists from rendered archive HTML.
	 *
	 * @since 1.9.0
	 *
	 * @param string $html Rendered archive HTML.
	 * @return array<int, array{heading:string, titles:string[]}>
	 */
	private function sections( string $html ): array {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();
		$xpath = new DOMXPath( $dom );
		$out   = [];
		foreach ( $xpath->query( '//section[@data-pkiw-sections]' ) as $section ) {
			$titles = [];
			foreach ( $xpath->query( './/*[contains(concat(" ", normalize-space(@class), " "), " wp-block-post-title ") or contains(concat(" ", normalize-space(@class), " "), " pk-title ")]', $section ) as $title ) {
				$titles[] = trim( $title->textContent );
			}
			$heading = $section->firstChild;
			$out[]   = [
				'heading' => $heading instanceof DOMElement ? $heading->nodeName . ':' . trim( $heading->textContent ) : '',
				'titles'  => $titles,
			];
		}

		return $out;
	}

	/**
	 * Post IDs from a direct query using the registered wish source.
	 *
	 * @since 1.9.0
	 *
	 * @return int[] Ordered post IDs.
	 */
	private function ordered_ids(): array {
		$query = new WP_Query(
			[
				'post_type'         => 'post',
				'post_status'       => 'publish',
				'posts_per_page'    => -1,
				'fields'            => 'ids',
				'pkiw_group_source' => Wish_Archive::SOURCE,
				'tax_query'         => [ [ 'taxonomy' => Taxonomy::TAXONOMY, 'field' => 'slug', 'terms' => 'wish' ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			]
		);

		return array_map( 'intval', $query->posts );
	}

	/**
	 * Ordered post IDs from the public REST orderby route.
	 *
	 * @since 1.9.0
	 *
	 * @return int[] Ordered post IDs.
	 */
	private function rest_ids(): array {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$term = get_term_by( 'slug', 'wish', Taxonomy::TAXONOMY );
		$this->assertInstanceOf( WP_Term::class, $term );
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$request->set_param( 'kind', [ $term->term_id ] );
		$request->set_param( 'orderby', 'pkiw_group' );
		$request->set_param( 'per_page', 100 );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$GLOBALS['wp_rest_server'] = null;

		return array_map( 'intval', wp_list_pluck( $response->get_data(), 'id' ) );
	}

	/**
	 * Render the archive-sections marker for one Site Editor loop item.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Post ID.
	 * @param int $lines   Marker page size.
	 * @return string Rendered marker HTML.
	 */
	private function editor_marker( int $post_id, int $lines = 20 ): string {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/' . self::MARKER );
		$request->set_param( 'context', 'edit' );
		$request->set_param( 'attributes', [ 'linesPerPage' => $lines ] );
		$request->set_param( 'post_id', $post_id );
		$request->set_param( 'pkiw_kind', 'wish' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$GLOBALS['wp_rest_server'] = null;

		return (string) ( $response->get_data()['rendered'] ?? '' );
	}

	/**
	 * Mapped spellings produce identical PHP and SQL groups.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_stored_spellings_group_in_php_sql_front_end_and_preview(): void {
		$stored = [ 'media', 'movie', 'Media', ' MEDIA ', 'other', 'Other', '', '   ', null, 'gizmo', 'tech', 'book' ];
		$posts  = [];
		foreach ( $stored as $at => $value ) {
			$meta = null === $value ? [] : [ '_pkiw_wish_type' => $value ];
			$posts[ (string) $at ] = $this->post( 'Shape ' . $at, sprintf( '2026-01-%02d 10:00:00', 12 - $at ), $meta );
		}

		$expected_by_input = [ 'movie', 'movie', 'movie', 'movie', '', '', '', '', '', '', 'tech', 'book' ];
		foreach ( array_values( $posts ) as $at => $id ) {
			$group = Grouped_Archive::group_of_post( 'wish', $id );
			$this->assertNotNull( $group );
			$this->assertSame( $expected_by_input[ $at ], $group->key(), (string) ( $stored[ $at ] ?? 'no row' ) );
		}

		$ids  = $this->ordered_ids();
		$keys = array_map( static fn( int $id ): string => Grouped_Archive::group_of_post( 'wish', $id )->key(), $ids );
		$this->assertSame( [ 'book', 'movie', 'movie', 'movie', 'movie', 'tech', '', '', '', '', '', '' ], $keys );

		$html = $this->serve( $this->template() );
		$this->assertSame( [ 'h2:Book', 'h2:Movie/Show', 'h2:Tech', 'h2:Other' ], array_column( $this->sections( $html ), 'heading' ) );
		$this->assertSame( 1, substr_count( $html, '>Movie/Show</h2>' ) );
		$this->assertSame( 1, substr_count( $html, '>Other</h2>' ) );

		$preview_headings = [];
		foreach ( $ids as $id ) {
			$marker = $this->editor_marker( $id );
			if ( preg_match( '/<h2[^>]*>([^<]+)<\/h2>/', $marker, $match ) ) {
				$preview_headings[] = $match[1];
			}
		}
		$this->assertSame( [ 'Book', 'Movie/Show', 'Tech', 'Other' ], $preview_headings );
	}

	/**
	 * Priority rank precedes date and higher ID in every ordered surface.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_priority_order_matches_front_end_rest_and_preview(): void {
		$high = $this->post( 'W01 high', '2026-01-01 10:00:00', [ '_pkiw_wish_type' => 'book', '_pkiw_wish_priority' => 'high' ] );
		$low  = $this->post( 'W03 low', '2026-01-31 10:00:00', [ '_pkiw_wish_type' => 'book', '_pkiw_wish_priority' => 'low' ] );
		$explicit = $this->post( 'W02 explicit medium', '2026-01-02 10:00:00', [ '_pkiw_wish_type' => 'book', '_pkiw_wish_priority' => 'medium' ] );
		$sidebar  = $this->post( 'W04 sidebar unset', '2026-01-06 10:00:00', [ '_pkiw_wish_type' => 'book' ] );
		$card_default = $this->post(
			'W05 card default',
			'2026-01-05 10:00:00',
			[],
			[ 'post_content' => $this->card( [ 'title' => 'W05 card default', 'wishType' => 'book' ] ) ]
		);
		$deleted = $this->post(
			'W06 deleted row',
			'2026-01-05 10:00:00',
			[],
			[ 'post_content' => $this->card( [ 'title' => 'W06 deleted row', 'wishType' => 'book' ] ) ]
		);
		delete_post_meta( $deleted, '_pkiw_wish_priority' );
		$urgent = $this->post( 'W07 unknown', '2026-01-04 10:00:00', [ '_pkiw_wish_type' => 'book', '_pkiw_wish_priority' => 'urgent' ] );
		$expected = [ $high, $sidebar, $deleted, $card_default, $urgent, $explicit, $low ];

		$this->assertSame( $expected, $this->ordered_ids() );
		$this->assertSame( $expected, $this->rest_ids() );
		wp_set_current_user( 0 );
		$sections = $this->sections( $this->serve( $this->template() ) );
		$this->assertSame( [ 'W01 high', 'W04 sidebar unset', 'W06 deleted row', 'W05 card default', 'W07 unknown', 'W02 explicit medium', 'W03 low' ], $sections[0]['titles'] );

		$preview = [];
		foreach ( $expected as $id ) {
			$preview[] = preg_match( '/<h2[^>]*>Book<\/h2>/', $this->editor_marker( $id ) ) ? 'H:' . get_the_title( $id ) : get_the_title( $id );
		}
		$this->assertSame( [ 'H:W01 high', 'W04 sidebar unset', 'W06 deleted row', 'W05 card default', 'W07 unknown', 'W02 explicit medium', 'W03 low' ], $preview );
	}

	/**
	 * Protected type and priority stay private while the title remains listed.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_protected_wish_files_under_other_at_medium_rank(): void {
		$high = $this->post( 'Open high', '2026-02-01 10:00:00', [ '_pkiw_wish_type' => 'other', '_pkiw_wish_priority' => 'high' ] );
		$medium = $this->post( 'Open medium', '2026-02-02 10:00:00', [ '_pkiw_wish_type' => 'other', '_pkiw_wish_priority' => 'medium' ] );
		$protected = $this->post(
			'Protected tech',
			'2026-02-03 10:00:00',
			[ '_pkiw_wish_type' => 'tech', '_pkiw_wish_priority' => 'high' ],
			[ 'post_password' => 'secret' ]
		);
		$this->assertTrue( Grouped_Archive::group_of_post( 'wish', $protected )->is_empty() );
		$this->assertSame( [ $high, $protected, $medium ], $this->ordered_ids() );

		$html = $this->serve( $this->template() );
		$this->assertStringNotContainsString( '>Tech</h2>', $html );
		$this->assertSame( [ 'h2:Other' ], array_column( $this->sections( $html ), 'heading' ) );
		$this->assertSame( [ 'Open high', 'Protected: Protected tech', 'Open medium' ], $this->sections( $html )[0]['titles'] );
		$this->assertMatchesRegularExpression( '/>Other<\/h2>/', $this->editor_marker( $high ) );
		$this->assertStringContainsString( 'hidden', $this->editor_marker( $protected ) );
	}

	/**
	 * Saving a card without wishType replaces stale meta with the Item default.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_card_save_default_files_under_item_over_stale_meta(): void {
		$id = $this->post( 'Default item', '2026-03-01 10:00:00', [ '_pkiw_wish_type' => 'tech' ] );
		wp_update_post( [ 'ID' => $id, 'post_content' => $this->card( [ 'title' => 'Default item' ] ) ] );

		$this->assertSame( 'item', get_post_meta( $id, '_pkiw_wish_type', true ) );
		$this->assertSame( 'item', Grouped_Archive::group_of_post( 'wish', $id )->key() );
	}

	/**
	 * The first card and first meta row control grouping, and other kinds stay out.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_first_card_first_meta_row_and_kind_filter_are_controls(): void {
		$two_cards = $this->post(
			'C05 two cards',
			'2026-04-03 10:00:00',
			[],
			[ 'post_content' => $this->card( [ 'title' => 'First card', 'wishType' => 'book' ] ) . $this->card( [ 'title' => 'Second card', 'wishType' => 'tech' ] ) ]
		);
		$two_rows = $this->post( 'C06 two rows', '2026-04-02 10:00:00' );
		add_post_meta( $two_rows, '_pkiw_wish_type', 'book' );
		add_post_meta( $two_rows, '_pkiw_wish_type', 'tech' );
		$untitled = $this->post(
			'',
			'2026-04-01 10:00:00',
			[],
			[ 'post_content' => $this->card( [ 'title' => 'Fictional Lantern', 'wishType' => 'tech' ] ) ]
		);
		$this->post( 'Stray note one', '2026-04-05 10:00:00', [ '_pkiw_wish_type' => 'book' ], [], 'note' );
		$this->post( 'Stray note two', '2026-04-04 10:00:00', [ '_pkiw_wish_priority' => 'high' ], [], 'note' );

		$this->assertSame( 'book', Grouped_Archive::group_of_post( 'wish', $two_cards )->key() );
		$this->assertSame( 'First card', Wish_Archive::facts( $two_cards )['title'] );
		$this->assertSame( 'book', Grouped_Archive::group_of_post( 'wish', $two_rows )->key() );
		$this->assertSame( 'Fictional Lantern', Wish_Archive::facts( $untitled )['name'] );

		$html = $this->serve( $this->template( 20, true ) );
		$this->assertStringContainsString( 'Fictional Lantern', $html );
		$this->assertStringNotContainsString( 'Stray note one', $html );
		$this->assertStringNotContainsString( 'Stray note two', $html );
	}

	/**
	 * Marker page size wins, continuation headings repeat and overflow is 404.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_pagination_uses_marker_size_and_repeats_continuation_heading(): void {
		for ( $at = 1; $at <= 13; $at++ ) {
			$this->post( 'Paged wish ' . $at, sprintf( '2026-05-%02d 10:00:00', $at ), [ '_pkiw_wish_type' => 'book' ] );
		}
		$this->serve( $this->template( 12 ) );
		$this->assertSame( 12, (int) $GLOBALS['wp_query']->get( 'posts_per_page' ) );
		$this->assertSame( 12, $GLOBALS['wp_query']->post_count );

		$page_two = $this->sections( $this->serve( $this->template( 12 ), 2 ) );
		$this->assertSame( [ 'h2:Book' ], array_column( $page_two, 'heading' ) );
		$this->assertCount( 1, $page_two[0]['titles'] );
		$this->serve( $this->template( 12 ), 3 );
		$this->assertTrue( is_404() );

		foreach ( get_posts( [ 'post_type' => 'post', 'posts_per_page' => -1, 'fields' => 'ids' ] ) as $id ) {
			wp_delete_post( (int) $id, true );
		}
		for ( $at = 1; $at <= 4; $at++ ) {
			$this->post( 'Continuation ' . $at, sprintf( '2026-06-%02d 10:00:00', $at ), [ '_pkiw_wish_type' => 'book' ] );
		}
		$this->assertSame( [ 'h2:Book' ], array_column( $this->sections( $this->serve( $this->template( 3 ), 2 ) ), 'heading' ) );
	}

	/**
	 * Private and draft wishes stay out of a visitor archive query.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_private_and_draft_wishes_are_absent_for_visitors(): void {
		$public = $this->post( 'Public wish', '2026-07-03 10:00:00', [ '_pkiw_wish_type' => 'book' ] );
		$this->post( 'Private wish', '2026-07-02 10:00:00', [ '_pkiw_wish_type' => 'book' ], [ 'post_status' => 'private' ] );
		$this->post( 'Draft wish', '2026-07-01 10:00:00', [ '_pkiw_wish_type' => 'book' ], [ 'post_status' => 'draft' ] );
		wp_set_current_user( 0 );

		$this->serve( $this->template() );
		$this->assertSame( [ $public ], array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) ) );
	}
}
