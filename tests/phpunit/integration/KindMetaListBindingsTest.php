<?php
/**
 * List Item bindings to kind meta: the Read and Watch summary patterns.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Block_Bindings;
use PKIW\Block_Bindings_Formats;
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
			$sources->setAccessible( true );
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
}
