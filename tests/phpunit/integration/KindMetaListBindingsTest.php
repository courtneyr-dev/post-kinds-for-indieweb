<?php
/**
 * List Item bindings to kind meta: the Read and Watch summary patterns.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Block_Bindings;
use PKIW\Block_Bindings_Formats;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/** @group integration */
final class KindMetaListBindingsTest extends WP_UnitTestCase {

	/**
	 * The post-formats source registered before the test, restored after.
	 *
	 * @var WP_Block_Bindings_Source|null
	 */
	private ?WP_Block_Bindings_Source $formats_source = null;

	public function set_up(): void {
		parent::set_up();
		if ( ! in_array( 'content', get_block_bindings_supported_attributes( 'core/list-item' ), true ) ) {
			$this->markTestSkipped( 'List Item bindings need WordPress 7.1 or later.' );
		}
		( new Taxonomy() )->create_default_terms();
		// The test framework wipes registered meta between tests; the
		// read_status and watch_status defaults are what these tests guard.
		( new Meta_Fields() )->register_meta_fields();
		$this->formats_source = get_block_bindings_source( Block_Bindings_Formats::SOURCE_NAME );
	}

	public function tear_down(): void {
		$registry = WP_Block_Bindings_Registry::get_instance();
		if ( $registry->is_registered( Block_Bindings_Formats::SOURCE_NAME ) ) {
			$registry->unregister( Block_Bindings_Formats::SOURCE_NAME );
		}
		if ( null !== $this->formats_source ) {
			// Core keeps the callback private, so put the original object back.
			$sources = new ReflectionProperty( WP_Block_Bindings_Registry::class, 'sources' );
			$all = $sources->getValue( $registry );
			$all[ Block_Bindings_Formats::SOURCE_NAME ] = $this->formats_source;
			$sources->setValue( $registry, $all );
		}
		parent::tear_down();
	}

	/**
	 * A published post of the given kind with the given kind meta.
	 *
	 * @param string                $kind   Kind slug.
	 * @param array<string, string> $meta   Field => value, without the prefix.
	 * @param array<string, mixed>  $args   Extra post arguments.
	 * @return int Post ID.
	 */
	private function kind_post( string $kind, array $meta, array $args = [] ): int {
		$id = self::factory()->post->create( array_merge( [ 'post_status' => 'publish' ], $args ) );
		wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY );
		foreach ( $meta as $field => $value ) {
			update_post_meta( $id, '_pkiw_' . $field, $value );
		}
		return $id;
	}

	/**
	 * Render a registered pattern's content in the context of a post.
	 *
	 * @param string $pattern Pattern name.
	 * @param int    $post_id Post ID.
	 * @return string Rendered HTML.
	 */
	private function render_pattern_for( string $pattern, int $post_id ): string {
		$registered = WP_Block_Patterns_Registry::get_instance()->get_registered( $pattern );
		$this->assertIsArray( $registered, "Pattern {$pattern} must be registered." );

		$html = '';
		foreach ( parse_blocks( $registered['content'] ) as $parsed ) {
			if ( empty( $parsed['blockName'] ) ) {
				continue;
			}
			$html .= ( new WP_Block( $parsed, [ 'postId' => $post_id, 'postType' => 'post' ] ) )->render();
		}
		return $html;
	}

	/**
	 * Assert the HTML has a list row whose text is exactly $text.
	 *
	 * @param string $text Row text as it appears in the HTML.
	 * @param string $html Rendered HTML.
	 * @param string $message Failure message.
	 */
	private function assertRow( string $text, string $html, string $message = '' ): void {
		$this->assertMatchesRegularExpression( '#<li(?:\s[^>]*)?>' . preg_quote( $text, '#' ) . '</li>#', $html, $message );
	}

	public function test_read_and_watch_patterns_bind_three_list_items_to_approved_fields_and_round_trip(): void {
		$expected = [
			'post-kinds-indieweb/read-summary-list'  => [ 'read_title', 'read_author', 'read_status' ],
			'post-kinds-indieweb/watch-summary-list' => [ 'watch_title', 'watch_year', 'watch_status' ],
		];

		foreach ( $expected as $name => $keys ) {
			$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( $name );
			$this->assertIsArray( $pattern, "{$name} is registered on a core that binds List Items." );

			$blocks = array_values( array_filter( parse_blocks( $pattern['content'] ), static fn( $b ) => ! empty( $b['blockName'] ) ) );
			$this->assertCount( 1, $blocks );
			$this->assertSame( 'core/list', $blocks[0]['blockName'] );

			$items = array_values( array_filter( $blocks[0]['innerBlocks'], static fn( $b ) => 'core/list-item' === $b['blockName'] ) );
			$this->assertCount( 3, $items, "{$name} has three rows." );
			$bound_keys = [];
			foreach ( $items as $item ) {
				$binding = $item['attrs']['metadata']['bindings']['content'] ?? [];
				$this->assertSame( Block_Bindings::SOURCE_NAME, $binding['source'] ?? null );
				$bound_keys[] = $binding['args']['key'] ?? '';
			}
			$this->assertSame( $keys, $bound_keys );
			$this->assertSame( [], array_diff( $bound_keys, array_keys( Block_Bindings::LIST_FIELDS ) ), 'Every bound key is on the approved list.' );

			$this->assertSame( trim( $pattern['content'] ), trim( serialize_blocks( parse_blocks( $pattern['content'] ) ) ), "{$name} saved markup survives a parse and serialize round trip." );
		}
	}

	public function test_bound_rows_render_the_post_values_with_status_labels(): void {
		global $wpdb;
		$read = $this->kind_post( 'read', [ 'read_author' => 'Octavia E. Butler', 'read_status' => 'finished' ] );
		// A raw row the write sanitizer never saw, so output escaping is what's tested.
		$wpdb->insert( $wpdb->postmeta, [ 'post_id' => $read, 'meta_key' => '_pkiw_read_title', 'meta_value' => 'Kindred <img src=x onerror=alert(1)>' ] );
		wp_cache_delete( $read, 'post_meta' );
		$watch = $this->kind_post( 'watch', [ 'watch_title' => 'Arrival', 'watch_year' => '2016', 'watch_status' => 'watched' ] );

		$html = $this->render_pattern_for( 'post-kinds-indieweb/read-summary-list', $read );
		$this->assertStringNotContainsString( '<img', $html, 'A bound value never prints markup.' );
		$this->assertStringContainsString( 'Kindred', $html );
		$this->assertRow( 'Octavia E. Butler', $html );
		$this->assertRow( 'Finished', $html, 'Status prints its label, not the slug.' );

		$html = $this->render_pattern_for( 'post-kinds-indieweb/watch-summary-list', $watch );
		$this->assertRow( 'Arrival', $html );
		$this->assertRow( '2016', $html );
		$this->assertRow( 'Watched', $html );
	}

	public function test_empty_fields_drop_their_rows_and_never_print_the_pattern_fallback_text(): void {
		$partial = $this->kind_post( 'read', [ 'read_title' => 'Parable of the Sower' ] );
		$html    = $this->render_pattern_for( 'post-kinds-indieweb/read-summary-list', $partial );
		$this->assertRow( 'Parable of the Sower', $html );
		$this->assertSame( 1, substr_count( $html, '<li' ), 'Only the row with data renders.' );
		$this->assertStringNotContainsString( 'Book author', $html, 'The fallback label stays in the editor.' );

		$empty = $this->kind_post( 'read', [] );
		$this->assertSame( '', trim( $this->render_pattern_for( 'post-kinds-indieweb/read-summary-list', $empty ) ), 'A summary with no data renders nothing, not an empty list.' );
	}

	public function test_fields_resolve_only_on_posts_of_their_kind(): void {
		// read_status has a registered default, so every post "has" one.
		$note = $this->kind_post( 'note', [ 'read_title' => 'Stray read meta' ] );
		$this->assertSame( '', trim( $this->render_pattern_for( 'post-kinds-indieweb/read-summary-list', $note ) ), 'A Read summary on a note renders nothing.' );

		$read = $this->kind_post( 'read', [] );
		$this->assertSame( '', trim( $this->render_pattern_for( 'post-kinds-indieweb/watch-summary-list', $read ) ), 'A Watch summary on a Read post renders nothing.' );

		// A post with no kind term prints only stored values, never a default.
		$kindless = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_delete_object_term_relationships( $kindless, Taxonomy::TAXONOMY );
		$this->assertSame( '', trim( $this->render_pattern_for( 'post-kinds-indieweb/read-summary-list', $kindless ) ), 'No row comes from a registered default.' );
		update_post_meta( $kindless, '_pkiw_read_title', 'Legacy title' );
		$html = $this->render_pattern_for( 'post-kinds-indieweb/read-summary-list', $kindless );
		$this->assertRow( 'Legacy title', $html );
		$this->assertSame( 1, preg_match_all( '#<li#', $html ), 'Only the stored row renders.' );
	}

	public function test_protected_and_private_posts_print_no_bound_values_to_visitors(): void {
		$protected = $this->kind_post( 'read', [ 'read_title' => 'Protected sentinel' ], [ 'post_password' => 'secret' ] );
		$private   = $this->kind_post( 'read', [ 'read_title' => 'Private sentinel' ], [ 'post_status' => 'private' ] );
		wp_set_current_user( 0 );

		$this->assertStringNotContainsString( 'Protected sentinel', $this->render_pattern_for( 'post-kinds-indieweb/read-summary-list', $protected ) );
		$this->assertStringNotContainsString( 'Private sentinel', $this->render_pattern_for( 'post-kinds-indieweb/read-summary-list', $private ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertStringContainsString( 'Private sentinel', $this->render_pattern_for( 'post-kinds-indieweb/read-summary-list', $private ), 'A reader who may read the post still sees it.' );
	}

	public function test_query_loop_rows_use_each_posts_own_values(): void {
		$first  = $this->kind_post( 'read', [ 'read_title' => 'Loop first' ] );
		$second = $this->kind_post( 'read', [ 'read_title' => 'Loop second', 'read_author' => 'Loop author' ] );
		$list   = WP_Block_Patterns_Registry::get_instance()->get_registered( 'post-kinds-indieweb/read-summary-list' )['content'];

		$query = '<!-- wp:query {"queryId":7,"query":{"perPage":10,"postType":"post","include":[' . $first . ',' . $second . '],"orderBy":"title","order":"asc","inherit":false}} -->'
			. '<div class="wp-block-query"><!-- wp:post-template -->' . $list . '<!-- /wp:post-template --></div><!-- /wp:query -->';
		$html  = do_blocks( $query );

		$this->assertRow( 'Loop first', $html );
		$this->assertRow( 'Loop second', $html );
		$this->assertSame( 1, preg_match_all( '#<li[^>]*>Loop author</li>#', $html ), 'Each item reads its own post.' );
	}

	public function test_a_co_installed_post_formats_source_keeps_its_own_values(): void {
		$registry = WP_Block_Bindings_Registry::get_instance();
		if ( $registry->is_registered( Block_Bindings_Formats::SOURCE_NAME ) ) {
			$registry->unregister( Block_Bindings_Formats::SOURCE_NAME );
		}
		// Post Formats for Block Themes registers first (after_setup_theme).
		register_block_bindings_source(
			Block_Bindings_Formats::SOURCE_NAME,
			[
				'label'              => 'Post Formats for Block Themes',
				'get_value_callback' => static fn( array $args ) => 'pfbt:' . ( $args['key'] ?? '' ),
				'uses_context'       => [ 'postId' ],
			]
		);
		( new Block_Bindings_Formats() )->register_source();

		$id   = $this->kind_post( 'read', [ 'read_title' => 'Shared list' ] );
		$list = '<!-- wp:list --><ul class="wp-block-list">'
			. '<!-- wp:list-item {"metadata":{"bindings":{"content":{"source":"post-formats/format-data","args":{"key":"format_label"}}}}} --><li>Format</li><!-- /wp:list-item -->'
			. '<!-- wp:list-item {"metadata":{"bindings":{"content":{"source":"post-kinds-indieweb/kind-meta","args":{"key":"read_title"}}}}} --><li>Book title</li><!-- /wp:list-item -->'
			. '</ul><!-- /wp:list -->';
		$html = ( new WP_Block( parse_blocks( $list )[0], [ 'postId' => $id, 'postType' => 'post' ] ) )->render();

		$this->assertRow( 'pfbt:format_label', $html, 'The Post Formats source answers its own rows.' );
		$this->assertRow( 'Shared list', $html );
		$this->assertSame( 'Post Formats for Block Themes', get_block_bindings_source( Block_Bindings_Formats::SOURCE_NAME )->label, 'Post Kinds did not replace the Post Formats source.' );
	}

	public function test_editor_field_list_names_only_approved_public_fields(): void {
		$fields = Block_Bindings::get_editor_fields();
		$keys   = array_column( $fields, 'key' );

		$this->assertSame( array_keys( Block_Bindings::LIST_FIELDS ), $keys );
		foreach ( $fields as $field ) {
			$this->assertStringStartsWith( '_pkiw_', $field['metaKey'] );
			$this->assertSame( strtok( $field['key'], '_' ), $field['kind'] );
			$this->assertNotSame( '', $field['label'] );
			$this->assertDoesNotMatchRegularExpression( '/checkin|geo|address|location/', $field['key'], 'No location field is offered.' );
		}
		$this->assertSame( 'Finished', $fields[ array_search( 'read_status', $keys, true ) ]['labels']['finished'] );

		// Every stored Watch status, including the registered default, has a label.
		$watch_labels = $fields[ array_search( 'watch_status', $keys, true ) ]['labels'];
		foreach ( [ 'to-watch', 'watching', 'watched', 'rewatching', 'abandoned' ] as $status ) {
			$this->assertArrayHasKey( $status, $watch_labels );
		}
	}

	/**
	 * Render one parsed block in the context of a post.
	 *
	 * @param string $markup  Block markup.
	 * @param int    $post_id Post ID.
	 * @return string Rendered HTML.
	 */
	private function render_for( string $markup, int $post_id ): string {
		return ( new WP_Block( parse_blocks( $markup )[0], [ 'postId' => $post_id, 'postType' => 'post' ] ) )->render();
	}

	public function test_list_items_resolve_only_allowlisted_keys_even_for_public_locations(): void {
		$id = $this->kind_post(
			'checkin',
			[
				'checkin_name'    => 'Location sentinel',
				'checkin_address' => '1 Sentinel Street',
				'geo_privacy'     => 'public',
				'read_title'      => 'Allowed title',
			]
		);
		wp_delete_object_term_relationships( $id, Taxonomy::TAXONOMY );
		$list = '<!-- wp:list --><ul class="wp-block-list">'
			. '<!-- wp:list-item {"metadata":{"bindings":{"content":{"source":"post-kinds-indieweb/kind-meta","args":{"key":"checkin_name"}}}}} --><li>Venue</li><!-- /wp:list-item -->'
			. '<!-- wp:list-item {"metadata":{"bindings":{"content":{"source":"post-kinds-indieweb/kind-meta","args":{"key":"checkin_address"}}}}} --><li>Address</li><!-- /wp:list-item -->'
			. '<!-- wp:list-item {"metadata":{"bindings":{"content":{"source":"post-kinds-indieweb/kind-meta","args":{"key":"read_title"}}}}} --><li>Book title</li><!-- /wp:list-item -->'
			. '</ul><!-- /wp:list -->';
		$html = $this->render_for( $list, $id );

		$this->assertStringNotContainsString( 'Location sentinel', $html );
		$this->assertStringNotContainsString( 'Sentinel Street', $html );
		$this->assertStringNotContainsString( 'Venue', $html, 'An off-list row prints neither its value nor its label.' );
		$this->assertRow( 'Allowed title', $html, 'Positive control: an allowlisted key still resolves.' );

		// Positive control: the same location key still resolves for a paragraph.
		$paragraph = '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"post-kinds-indieweb/kind-meta","args":{"key":"checkin_name"}}}}} --><p>Venue</p><!-- /wp:paragraph -->';
		$this->assertStringContainsString( 'Location sentinel', $this->render_for( $paragraph, $id ) );
	}

	public function test_a_matching_kind_summary_never_prints_a_default_status(): void {
		$id = $this->kind_post( 'read', [ 'read_title' => 'Title only' ] );
		$this->assertSame( 'reading', get_post_meta( $id, '_pkiw_read_status', true ), 'Precondition: the registered default is live.' );
		$html = $this->render_pattern_for( 'post-kinds-indieweb/read-summary-list', $id );
		$this->assertRow( 'Title only', $html );
		$this->assertStringNotContainsString( 'Currently Reading', $html, 'The registered read_status default prints no row.' );

		$watch = $this->kind_post( 'watch', [ 'watch_title' => 'Arrival' ] );
		$this->assertStringNotContainsString( 'To Watch', $this->render_pattern_for( 'post-kinds-indieweb/watch-summary-list', $watch ) );

		update_post_meta( $id, '_pkiw_read_status', 'reading' );
		$this->assertRow( 'Currently Reading', $this->render_pattern_for( 'post-kinds-indieweb/read-summary-list', $id ), 'A stored status equal to the default still prints.' );

		$this->assertSame( [ 'read_title', 'read_status' ], Block_Bindings::stored_list_fields( $id ), 'The editor learns the same presence rule.' );
	}

	public function test_saving_a_title_only_edit_with_the_full_meta_object_stores_no_default_status(): void {
		$id = $this->kind_post( 'read', [ 'read_title' => 'Kindred' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		// The editor sends every meta key once any one changes, so the
		// read_status default rides along with the new title.
		$request = new WP_REST_Request( 'POST', "/wp/v2/posts/{$id}" );
		$request->set_body_params(
			[
				'meta' => [
					'_pkiw_read_title'  => 'Kindred, revised',
					'_pkiw_read_status' => 'reading',
				],
			]
		);
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Kindred, revised', get_post_meta( $id, '_pkiw_read_title', true ), 'Positive control: the edited title saved.' );
		$this->assertFalse( metadata_exists( 'post', $id, '_pkiw_read_status' ), 'The untouched default status is not stored.' );
		$this->assertSame( [ 'read_title' ], $response->get_data()[ Block_Bindings::STORED_FIELDS ] );
		$this->assertStringNotContainsString( 'Currently Reading', $this->render_pattern_for( 'post-kinds-indieweb/read-summary-list', $id ) );

		// Positive controls: a changed status saves, and a stored row
		// accepts the default value back.
		$request->set_body_params( [ 'meta' => [ '_pkiw_read_status' => 'finished' ] ] );
		rest_do_request( $request );
		$this->assertSame( 'finished', get_post_meta( $id, '_pkiw_read_status', true ) );
		$request->set_body_params( [ 'meta' => [ '_pkiw_read_status' => 'reading' ] ] );
		rest_do_request( $request );
		$this->assertSame( 'reading', get_post_meta( $id, '_pkiw_read_status', true ) );
	}

	public function test_the_stored_fields_rest_field_needs_edit_post_even_in_a_delete_response(): void {
		$id = $this->kind_post( 'read', [ 'read_title' => 'Kindred' ], [ 'post_author' => self::factory()->user->create( [ 'role' => 'author' ] ) ] );
		add_role( 'pkiw_deleter', 'Deleter', [ 'read' => true, 'delete_posts' => true, 'delete_others_posts' => true, 'delete_published_posts' => true ] );
		$deleter = self::factory()->user->create( [ 'role' => 'pkiw_deleter' ] );

		try {
			wp_set_current_user( $deleter );
			$this->assertTrue( current_user_can( 'delete_post', $id ), 'Precondition: the role can delete this post.' );
			$this->assertFalse( current_user_can( 'edit_post', $id ), 'Precondition: the role cannot edit it.' );

			// Core prepares the trashed post in edit context after checking only delete_post.
			$response = rest_do_request( new WP_REST_Request( 'DELETE', "/wp/v2/posts/{$id}" ) );
			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( [], $response->get_data()[ Block_Bindings::STORED_FIELDS ] );

			// Positive control: an editor of the post still sees the stored fields.
			wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
			$request = new WP_REST_Request( 'GET', "/wp/v2/posts/{$id}" );
			$request->set_param( 'context', 'edit' );
			$this->assertSame( [ 'read_title' ], rest_do_request( $request )->get_data()[ Block_Bindings::STORED_FIELDS ] );
		} finally {
			remove_role( 'pkiw_deleter' );
		}
	}

	public function test_legacy_paragraph_and_image_bindings_keep_explicit_values_on_any_kind(): void {
		global $wpdb;
		$note = $this->kind_post( 'note', [ 'watch_poster' => '' ] );
		$wpdb->insert( $wpdb->postmeta, [ 'post_id' => $note, 'meta_key' => '_pkiw_read_title', 'meta_value' => 'Note <b>title</b>' ] );
		update_post_meta( $note, '_pkiw_watch_title', 'Arrival alt' );
		wp_cache_delete( $note, 'post_meta' );

		$paragraph = $this->render_for( '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"post-kinds-indieweb/kind-meta","args":{"key":"read_title"}}}}} --><p>Fallback</p><!-- /wp:paragraph -->', $note );
		$this->assertStringContainsString( 'Note &lt;b&gt;title&lt;/b&gt;', $paragraph, 'A paragraph keeps its value on another kind, still escaped.' );

		$heading = $this->render_for( '<!-- wp:heading {"metadata":{"bindings":{"content":{"source":"post-kinds-indieweb/kind-meta","args":{"key":"watch_title"}}}}} --><h2 class="wp-block-heading">Fallback</h2><!-- /wp:heading -->', $note );
		$this->assertStringContainsString( 'Arrival alt', $heading );

		$image = $this->render_for( '<!-- wp:image {"metadata":{"bindings":{"alt":{"source":"post-kinds-indieweb/kind-meta","args":{"key":"watch_title"}}}}} --><figure class="wp-block-image"><img src="https://example.com/a.jpg" alt=""/></figure><!-- /wp:image -->', $note );
		$this->assertStringContainsString( 'alt="Arrival alt"', $image );

		// The read gate still applies to every block type.
		$private = $this->kind_post( 'note', [ 'read_title' => 'Private paragraph' ], [ 'post_status' => 'private' ] );
		wp_set_current_user( 0 );
		$this->assertStringNotContainsString( 'Private paragraph', $this->render_for( '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"post-kinds-indieweb/kind-meta","args":{"key":"read_title"}}}}} --><p>Fallback</p><!-- /wp:paragraph -->', $private ) );
	}

	public function test_the_bindings_source_loads_in_the_site_editor(): void {
		global $wp_scripts;
		$saved      = $wp_scripts;
		$wp_scripts = new WP_Scripts();
		wp_default_scripts( $wp_scripts );
		set_current_screen( 'site-editor' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		do_action( 'enqueue_block_editor_assets' );

		$this->assertTrue( wp_script_is( Block_Bindings::EDITOR_HANDLE, 'enqueued' ), 'The field picker loads in the Site Editor.' );
		$this->assertFalse( wp_script_is( 'post-kinds-indieweb-editor', 'enqueued' ), 'The post-only panels still stay out of the Site Editor.' );
		$inline = implode( '', (array) $wp_scripts->get_data( Block_Bindings::EDITOR_HANDLE, 'before' ) );
		$this->assertStringContainsString( 'window.pkiwKindMetaFields', $inline );
		$this->assertStringContainsString( '"read_title"', $inline );
		set_current_screen( 'front' );
		// Later classes read blocks' registered editor scripts from the global.
		$wp_scripts = $saved;
	}

	public function test_row_removal_uses_the_resolved_value_and_keeps_child_lists(): void {
		$id = $this->kind_post( 'read', [ 'read_title' => 'Parent title' ] );

		$filter = static function ( $value, $source, $args ) {
			return Block_Bindings::SOURCE_NAME === $source && 'read_author' === ( $args['key'] ?? '' ) ? 'Filtered author' : $value;
		};
		add_filter( 'block_bindings_source_value', $filter, 10, 3 );
		$html = $this->render_pattern_for( 'post-kinds-indieweb/read-summary-list', $id );
		remove_filter( 'block_bindings_source_value', $filter, 10 );
		$this->assertRow( 'Filtered author', $html, 'A source-value filter result is kept, not resolved again.' );

		$nested = '<!-- wp:list --><ul class="wp-block-list">'
			. '<!-- wp:list-item {"metadata":{"bindings":{"content":{"source":"post-kinds-indieweb/kind-meta","args":{"key":"read_author"}}}}} --><li>Book author'
			. '<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li>Child note</li><!-- /wp:list-item --></ul><!-- /wp:list -->'
			. '</li><!-- /wp:list-item -->'
			. '</ul><!-- /wp:list -->';
		$html = $this->render_for( $nested, $id );
		$this->assertStringContainsString( 'Child note', $html, 'An empty row with a nested list keeps the nested list.' );
		$this->assertStringNotContainsString( 'Book author', $html, 'The fallback label still never prints.' );
	}
}
