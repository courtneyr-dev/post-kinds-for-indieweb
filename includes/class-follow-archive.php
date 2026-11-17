<?php
/**
 * Follow archive, public facts, blocks, and OPML.
 *
 * @package PKIW
 * @since 1.9.2
 */

declare(strict_types=1);

namespace PKIW;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Own the public follow contract. */
final class Follow_Archive {
	// Public method signatures carry the callback contract types.
	// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag
	public const KIND            = 'follow';
	public const ENTRY_BLOCK     = 'post-kinds-indieweb/follow-entry';
	public const LETTERS_BLOCK   = 'post-kinds-indieweb/follow-letters';
	public const VAR_INITIAL     = 'pkiw_follow_initial';
	public const VAR_OPML        = 'pkiw_follow_opml';
	public const PER_PAGE        = 8;
	public const INDEX_TRANSIENT = 'pkiw_follow_index_v1';
	public const INDEX_TTL       = HOUR_IN_SECONDS;
	public const OTHER_INITIAL   = '#';

	/** Wire follow readers and surfaces. */
	public function register(): void {
		Kind_Facts::register( self::KIND, [ self::class, 'facts' ] );
		add_filter( 'query_vars', [ self::class, 'add_query_vars' ] );
		if ( isset( $GLOBALS['wp'] ) && $GLOBALS['wp'] instanceof \WP ) {
			$GLOBALS['wp']->add_query_var( self::VAR_INITIAL );
			$GLOBALS['wp']->add_query_var( self::VAR_OPML );
		}
		add_action( 'pre_get_posts', [ self::class, 'filter_main_query' ] );
		add_filter( 'posts_clauses', [ self::class, 'order_clauses' ], 10, 2 );
		add_filter( 'render_block_core/paragraph', [ self::class, 'unclass_generated_paragraph' ], 10, 2 );
		add_filter( 'get_post_metadata', [ self::class, 'filter_cite_photo' ], 10, 5 );
		add_filter( 'add_post_metadata', [ self::class, 'guard_cite_photo' ], 10, 4 );
		add_filter( 'update_post_metadata', [ self::class, 'guard_cite_photo' ], 10, 4 );
		add_filter( 'update_post_metadata_by_mid', [ self::class, 'guard_cite_photo_by_mid' ], 10, 4 );
		add_action( 'set_object_terms', [ self::class, 'photo_on_kind_change' ], 10, 6 );
		add_filter( 'is_post_embeddable', [ self::class, 'embeddable' ], 20, 2 );
		add_filter( 'webmention_links', [ self::class, 'notify_links' ], 30, 2 );
		add_filter( 'micropub_dynamic_render', [ self::class, 'no_micropub_render' ], 10, 2 );
		add_filter( 'pkiw_public_eligibility_purge_urls', [ self::class, 'purge_urls' ], 10, 2 );
		add_filter( 'pkiw_kind_archive_preview_per_page', [ self::class, 'preview_per_page' ], 10, 2 );
		add_filter( 'pkiw_stream_card_kind_cite', [ self::class, 'stream_cite_map' ] );
		add_filter( 'pkiw_stream_card_kind_render', [ self::class, 'stream_card' ], 5, 4 );
		add_action( 'wp_head', [ self::class, 'opml_alternate_link' ] );
		add_action( 'parse_request', [ self::class, 'serve_opml' ], 10 );

		if ( did_action( 'init' ) ) {
			self::register_route();
			self::register_blocks();
			self::register_rest_hooks();
		} else {
			add_action( 'init', [ self::class, 'register_route' ] );
			add_action( 'init', [ self::class, 'register_blocks' ] );
			add_action( 'init', [ self::class, 'register_rest_hooks' ], 99 );
		}

		foreach ( [ 'save_post', 'before_delete_post', 'deleted_post', 'trashed_post', 'untrashed_post', 'post_updated' ] as $hook ) {
			add_action( $hook, [ self::class, 'flush_index' ] );
		}
		add_action( 'set_object_terms', [ self::class, 'terms_changed' ], 20, 6 );
		add_action( 'deleted_term_relationships', [ self::class, 'term_relationships_deleted' ], 20, 3 );
		foreach ( [ 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ] as $hook ) {
			add_action( $hook, [ self::class, 'meta_changed' ], 20, 4 );
		}
	}

	/** Add follow query variables. */
	public static function add_query_vars( array $vars ): array {
		return array_values( array_unique( array_merge( $vars, [ self::VAR_INITIAL, self::VAR_OPML ] ) ) );
	}

	/** Read the followed site without applying the public gate. */
	public static function target( \WP_Post $post ): array {
		$url = web_url( (string) get_post_meta( $post->ID, '_pkiw_cite_url', true ) );
		if ( '' === $url ) {
			$processor = new \WP_HTML_Tag_Processor( (string) $post->post_content );
			if ( $processor->next_tag(
				[
					'tag_name'   => 'A',
					'class_name' => 'u-follow-of',
				]
			) ) {
				$url = web_url( (string) $processor->get_attribute( 'href' ) );
			}
		}

		$name = trim( (string) get_post_meta( $post->ID, '_pkiw_cite_name', true ) );
		if ( '' !== $name && ! card_name_is_url( $name, $url ) ) {
			return [
				'url'         => $url,
				'name'        => $name,
				'name_source' => 'stored',
			];
		}

		$name = url_host( $url );
		return [
			'url'         => $url,
			'name'        => $name,
			'name_source' => '' !== $name ? 'host' : 'none',
		];
	}

	/** Return public follow facts. */
	public static function facts( int $post_id ): array {
		$post     = $post_id > 0 ? get_post( $post_id ) : null;
		$taxonomy = get_taxonomy( Taxonomy::TAXONOMY );
		$types    = $taxonomy ? $taxonomy->object_type : [ 'post' ];
		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, $types, true ) || ! has_term( self::KIND, Taxonomy::TAXONOMY, $post ) ) {
			return [];
		}
		return self::facts_for( $post, self::KIND );
	}

	/** Apply the single eligibility gate before reading person data. */
	public static function facts_for( \WP_Post $post, string $kind ): array {
		return self::KIND === $kind && Public_Eligibility::is_eligible( $post ) ? self::facts_body( $post ) : [];
	}

	/** Build facts after eligibility has been established. @internal */
	public static function facts_body( \WP_Post $post ): array {
		$target      = self::target( $post );
		$name        = (string) $target['name'];
		$name_source = (string) $target['name_source'];
		if ( '' === $name ) {
			$name = trim( (string) \pkiw_get_safe_title( $post->ID ) );
			if ( '' === $name ) {
				$name = untitled_name( $post, false );
			}
			$name_source = 'title';
		}
		$photo_id  = (int) get_post_thumbnail_id( $post );
		$photo_id  = $photo_id > 0 ? Public_Eligibility::public_image_id( $photo_id ) : 0;
		$photo_alt = $photo_id > 0 ? attachment_alt( $photo_id ) : '';
		if ( 0 === strcasecmp( $photo_alt, $name ) ) {
			$photo_alt = '';
		}
		[ $day, $day_display ] = card_calendar_date( get_the_date( 'Y-m-d', $post ) );
		$title                 = wp_strip_all_tags( get_the_title( $post ) );

		return [
			'post_id'      => $post->ID,
			'name'         => $name,
			'name_source'  => $name_source,
			'url'          => (string) $target['url'],
			'host'         => url_host( (string) $target['url'] ),
			'initial'      => self::initial( $name ),
			'title'        => $title,
			'title_empty'  => '' === trim( (string) $post->post_title ) || stream_card_title_is_cited_url( $post, (string) $post->post_title ),
			'photo_id'     => $photo_id,
			'photo_alt'    => $photo_alt,
			'date_iso'     => get_post_time( 'c', true, $post ),
			'modified_gmt' => (string) $post->post_modified_gmt,
			'day'          => $day,
			'day_display'  => $day_display,
		];
	}

	/** Identify the Micropub-generated follow paragraph. */
	public static function is_generated_paragraph( array $block ): bool {
		if ( 'core/paragraph' !== ( $block['blockName'] ?? '' ) ) {
			return false;
		}
		$html      = (string) ( $block['innerHTML'] ?? '' );
		$processor = new \WP_HTML_Tag_Processor( $html );
		$anchors   = 0;
		$href      = '';
		while ( $processor->next_tag() ) {
			$tag = $processor->get_tag();
			if ( ! in_array( $tag, [ 'P', 'A' ], true ) ) {
				return false;
			}
			if ( 'A' === $tag ) {
				if ( ! $processor->has_class( 'u-follow-of' ) ) {
					return false;
				}
				++$anchors;
				$href = (string) $processor->get_attribute( 'href' );
			}
		}
		$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $html ) ) );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return 1 === $anchors && '' !== $href && __( 'Followed', 'post-kinds-for-indieweb-in-block-themes' ) . ' ' . $href === $text;
	}

	/** Mark and filter the front-end follow archive query. */
	public static function filter_main_query( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_tax( Taxonomy::TAXONOMY, self::KIND ) || $query->is_feed() ) {
			return;
		}
		$query->set( 'pkiw_follow_az', 1 );
		$value = $query->get( self::VAR_INITIAL );
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( '' !== $value ) {
			$letter = self::initial( $value );
			$query->set( 'post__in', self::served_index()['letters'][ $letter ]['ids'] ?? [ 0 ] );
		}
	}

	/** Sort follow archive queries by stored name, then stored host. */
	public static function order_clauses( array $clauses, \WP_Query $query ): array {
		if ( ! $query->get( 'pkiw_follow_az' ) ) {
			return $clauses;
		}
		global $wpdb;
		$name               = "(SELECT pf_n.meta_value FROM {$wpdb->postmeta} pf_n WHERE pf_n.post_id = {$wpdb->posts}.ID AND pf_n.meta_key = '_pkiw_cite_name' ORDER BY pf_n.meta_id ASC LIMIT 1)";
		$host               = "(SELECT pf_h.meta_value FROM {$wpdb->postmeta} pf_h WHERE pf_h.post_id = {$wpdb->posts}.ID AND pf_h.meta_key = '_pkiw_cite_host' ORDER BY pf_h.meta_id ASC LIMIT 1)";
		$key                = "CASE WHEN COALESCE({$name}, '') = '' OR {$name} LIKE 'http://%' OR {$name} LIKE 'https://%' OR {$name} LIKE '//%' THEN COALESCE({$host}, '') ELSE {$name} END";
		$clauses['orderby'] = "({$key} = '') ASC, LOWER({$key}) ASC, {$wpdb->posts}.post_date DESC, {$wpdb->posts}.ID DESC";
		return $clauses;
	}

	/** Query arguments for the public index. */
	public static function index_query_args(): array {
		$taxonomy = get_taxonomy( Taxonomy::TAXONOMY );
		return [
			'post_type'           => $taxonomy ? $taxonomy->object_type : 'post',
			'fields'              => 'ids',
			'posts_per_page'      => -1,
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			'suppress_filters'    => false,
			'pkiw_follow_az'      => 1,
			'pkiw_public_only'    => true,
			'tax_query'           => [
				[
					'taxonomy' => Taxonomy::TAXONOMY,
					'field'    => 'slug',
					'terms'    => self::KIND,
				],
			],
		];
	}

	/** Build the uncached public index. */
	public static function build_index(): array {
		$query   = new \WP_Query( self::index_query_args() );
		$letters = [];
		$follows = [];
		foreach ( array_map( 'intval', $query->posts ) as $post_id ) {
			$facts = self::facts( $post_id );
			if ( [] === $facts ) {
				continue;
			}
			$letter = (string) $facts['initial'];
			if ( ! isset( $letters[ $letter ] ) ) {
				$letters[ $letter ] = [
					'count' => 0,
					'ids'   => [],
				];
			}
			++$letters[ $letter ]['count'];
			$letters[ $letter ]['ids'][] = $post_id;
			$follows[]                   = [
				'post_id'      => $post_id,
				'name'         => (string) $facts['name'],
				'url'          => (string) $facts['url'],
				'host'         => (string) $facts['host'],
				'initial'      => $letter,
				'modified_gmt' => (string) $facts['modified_gmt'],
			];
		}
		uksort( $letters, [ self::class, 'compare_initials' ] );
		return [
			'letters' => $letters,
			'follows' => $follows,
			'total'   => count( $follows ),
		];
	}

	/** Return the cached index. */
	public static function index(): array {
		$cached = get_transient( self::INDEX_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$index = self::build_index();
		set_transient( self::INDEX_TRANSIENT, $index, self::INDEX_TTL );
		return $index;
	}

	/** Recheck every cached row immediately before serving it. */
	public static function served_index(): array {
		$index = self::index();
		$rows  = array_values( array_filter( (array) ( $index['follows'] ?? [] ), 'is_array' ) );
		$ids   = array_map( static fn( array $row ): int => (int) ( $row['post_id'] ?? 0 ), $rows );
		if ( [] !== $ids ) {
			_prime_post_caches( $ids, true, true );
		}
		$follows = [];
		$letters = [];
		foreach ( $rows as $row ) {
			$id   = (int) ( $row['post_id'] ?? 0 );
			$post = $id > 0 ? get_post( $id ) : null;
			if ( ! $post instanceof \WP_Post || ! has_term( self::KIND, Taxonomy::TAXONOMY, $post ) || Public_Eligibility::hides( $post ) ) {
				continue;
			}
			$target = self::target( $post );
			$name   = (string) $target['name'];
			if ( '' === $name ) {
				$name = trim( (string) \pkiw_get_safe_title( $post->ID ) );
				if ( '' === $name ) {
					$name = untitled_name( $post, false );
				}
			}
			$letter         = self::initial( $name );
			$row['post_id'] = $id;
			$row['name']    = $name;
			$row['url']     = (string) $target['url'];
			$row['host']    = url_host( (string) $target['url'] );
			$row['initial'] = $letter;
			$follows[]      = $row;
			if ( ! isset( $letters[ $letter ] ) ) {
				$letters[ $letter ] = [
					'count' => 0,
					'ids'   => [],
				];
			}
			++$letters[ $letter ]['count'];
			$letters[ $letter ]['ids'][] = $id;
		}
		uksort( $letters, [ self::class, 'compare_initials' ] );
		return [
			'letters' => $letters,
			'follows' => $follows,
			'total'   => count( $follows ),
		];
	}

	/** Remove the generated paragraph's duplicate follow-of class on singles. */
	public static function unclass_generated_paragraph( $html, array $block ) {
		if ( ! is_string( $html ) ) {
			return $html;
		}
		if ( ! is_singular() || ! self::is_generated_paragraph( $block ) ) {
			return $html;
		}
		$post_id = get_queried_object_id();
		$facts   = $post_id > 0 ? self::facts( $post_id ) : [];
		if ( '' === ( $facts['url'] ?? '' ) ) {
			return $html;
		}
		$processor = new \WP_HTML_Tag_Processor( $html );
		if ( $processor->next_tag(
			[
				'tag_name'   => 'A',
				'class_name' => 'u-follow-of',
			]
		) ) {
			$processor->remove_class( 'u-follow-of' );
			return $processor->get_updated_html();
		}
		return $html;
	}

	/** Build the hidden follow citation used by generic Stream cards. */
	public static function stream_cite( array $facts, \WP_Post $post ): string {
		$facts = self::facts( $post->ID );
		if ( [] === $facts || '' === (string) $facts['url'] ) {
			return '';
		}
		$photo = (int) $facts['photo_id'] > 0 ? (string) wp_get_attachment_image_url( (int) $facts['photo_id'], 'medium' ) : '';
		$html  = '<span class="u-follow-of h-card" hidden><data class="p-name" value="' . esc_attr( (string) $facts['name'] ) . '"></data><data class="u-url" value="' . esc_url( (string) $facts['url'] ) . '"></data>';
		if ( '' !== $photo ) {
			$html .= '<data class="u-photo" value="' . esc_url( $photo ) . '"></data>';
		}
		return $html . '</span>';
	}

	/** Add follow to the generic Stream citation map. */
	public static function stream_cite_map( array $map ): array {
		$map[ self::KIND ] = [ self::class, 'stream_cite' ];
		return $map;
	}

	/** Stop direct Stream renders from exposing an ineligible follow. */
	public static function stream_card( $html, \WP_Post $post, int $heading_level, array $attributes ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Shared filter signature.
		if ( null !== $html || ! has_term( self::KIND, Taxonomy::TAXONOMY, $post ) ) {
			return $html;
		}
		return [] === self::facts( $post->ID ) ? '' : null;
	}

	/** Register REST response gates for every kind-enabled type. */
	public static function register_rest_hooks(): void {
		$taxonomy = get_taxonomy( Taxonomy::TAXONOMY );
		foreach ( $taxonomy ? $taxonomy->object_type : [ 'post' ] as $type ) {
			add_filter( 'rest_prepare_' . $type, [ self::class, 'rest_meta' ], 21, 3 );
		}
	}

	/** Apply the follow REST gate with the request's editor exemption. */
	public static function rest_meta( $response, $post, $request ) {
		if ( ! $response instanceof \WP_REST_Response || ! $post instanceof \WP_Post || ! $request instanceof \WP_REST_Request ) {
			return $response;
		}
		$editor_edit = 'edit' === (string) $request->get_param( 'context' ) && current_user_can( 'edit_post', $post->ID );
		if ( ! has_term( self::KIND, Taxonomy::TAXONOMY, $post ) ) {
			return $response;
		}
		return self::rest_meta_for( $response, $post, self::KIND, $editor_edit );
	}

	/** Redact hidden follow metadata and reject unsafe stored photos. */
	public static function rest_meta_for( \WP_REST_Response $response, \WP_Post $post, string $kind, bool $editor_edit = false ): \WP_REST_Response {
		if ( self::KIND !== $kind ) {
			return $response;
		}
		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			return $response;
		}
		$meta = isset( $data['meta'] ) && is_array( $data['meta'] ) ? $data['meta'] : [];
		if ( ! Public_Eligibility::is_eligible( $post ) && ! $editor_edit ) {
			foreach ( array_keys( $meta ) as $key ) {
				if ( str_starts_with( (string) $key, '_pkiw_' ) ) {
					unset( $meta[ $key ] );
				}
			}
			$data['featured_media'] = 0;
		}
		if ( isset( $meta['_pkiw_cite_photo'] ) && ! self::photo_ok( (string) $meta['_pkiw_cite_photo'] ) ) {
			$meta['_pkiw_cite_photo'] = '';
		}
		$data['meta'] = $meta;
		$response->set_data( $data );
		return $response;
	}

	/** Whether a follow photo is a permitted media-library image. */
	public static function photo_ok( string $url ): bool {
		if ( '' === $url || ! is_upload_url( $url ) ) {
			return false;
		}
		$attachment_id = attachment_url_to_postid( $url );
		return $attachment_id > 0 && Public_Eligibility::public_image_id( $attachment_id ) === $attachment_id;
	}

	/** Hide unsafe stored cite photos from every metadata reader. */
	public static function filter_cite_photo( $check, $object_id, $meta_key, $single, $meta_type = 'post' ) {
		if ( 'post' !== $meta_type || '_pkiw_cite_photo' !== $meta_key || (int) $object_id < 1 || ! has_term( self::KIND, Taxonomy::TAXONOMY, (int) $object_id ) ) {
			return $check;
		}
		$url = self::raw_cite_photo( (int) $object_id );
		return self::photo_ok( $url ) ? $check : ( $single ? '' : [] );
	}

	/** Refuse unsafe follow cite-photo writes without failing the request. */
	public static function guard_cite_photo( $check, $object_id, $meta_key, $value ) {
		if ( '_pkiw_cite_photo' !== $meta_key || (int) $object_id < 1 || ! has_term( self::KIND, Taxonomy::TAXONOMY, (int) $object_id ) ) {
			return $check;
		}
		if ( ! is_scalar( $value ) ) {
			return true;
		}
		if ( '' === (string) $value ) {
			return $check;
		}
		return self::photo_ok( (string) $value ) ? $check : true;
	}

	/** Refuse unsafe cite-photo updates addressed by metadata row ID. */
	public static function guard_cite_photo_by_mid( $check, $meta_id, $value, $meta_key ) {
		$meta = get_metadata_by_mid( 'post', (int) $meta_id );
		if ( ! $meta ) {
			return $check;
		}
		$key = false === $meta_key ? (string) $meta->meta_key : $meta_key;
		return self::guard_cite_photo( $check, (int) $meta->post_id, $key, $value );
	}

	/** Drop an unsafe cite photo when a post becomes a follow. */
	public static function photo_on_kind_change( int $object_id, $terms, array $tt_ids, string $taxonomy, bool $append, array $old_tt_ids ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Core hook signature.
		if ( Taxonomy::TAXONOMY !== $taxonomy || ! has_term( self::KIND, Taxonomy::TAXONOMY, $object_id ) ) {
			return;
		}
		$url = self::raw_cite_photo( $object_id );
		if ( '' !== $url && ! self::photo_ok( $url ) ) {
			delete_post_meta( $object_id, '_pkiw_cite_photo' );
		}
	}

	/** Hide every governed ineligible post from oEmbed. */
	public static function embeddable( $embeddable, $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return $embeddable;
		}
		return Public_Eligibility::hides( $post ) ? false : (bool) $embeddable;
	}

	/** Add the followed URL to Webmention for an eligible follow. */
	public static function notify_links( $urls, int $post_id ): array {
		$urls  = is_array( $urls ) ? $urls : [];
		$facts = self::facts( $post_id );
		if ( [] !== $facts && '' !== (string) $facts['url'] ) {
			$urls[] = (string) $facts['url'];
		}
		return array_values( array_unique( $urls ) );
	}

	/** Disable Micropub's duplicate renderer for follows. */
	public static function no_micropub_render( $should, $post ): bool {
		$post = get_post( $post );
		return $post instanceof \WP_Post && has_term( self::KIND, Taxonomy::TAXONOMY, $post ) ? false : (bool) $should;
	}

	/** Add every affected letter page to the shared purge list. */
	public static function purge_urls( array $paths, \WP_Post $post ): array {
		if ( ! has_term( self::KIND, Taxonomy::TAXONOMY, $post ) ) {
			return $paths;
		}
		$letters   = array_keys( self::served_index()['letters'] );
		$target    = self::target( $post );
		$letters[] = self::initial( (string) $target['name'] );
		foreach ( array_unique( $letters ) as $letter ) {
			$paths['urls'][] = home_url( '/kind/follow/?' . self::VAR_INITIAL . '=' . rawurlencode( (string) $letter ) );
		}
		$paths['urls'] = array_values( array_unique( $paths['urls'] ) );
		return $paths;
	}

	/** Register the OPML rewrite rule. */
	public static function register_route(): void {
		$taxonomy = get_taxonomy( Taxonomy::TAXONOMY );
		$base     = $taxonomy instanceof \WP_Taxonomy && is_array( $taxonomy->rewrite ) ? (string) ( $taxonomy->rewrite['slug'] ?? 'kind' ) : 'kind';
		add_rewrite_rule( '^' . preg_quote( $base, '#' ) . '/follow/opml/?$', 'index.php?' . self::VAR_OPML . '=1', 'top' );
	}

	/** Serve OPML before the main query and normal headers. */
	public static function serve_opml( $wp ): void {
		if ( ! $wp instanceof \WP || empty( $wp->query_vars[ self::VAR_OPML ] ) ) {
			return;
		}
		if ( ! term_exists( self::KIND, Taxonomy::TAXONOMY ) ) {
			$wp->query_vars = [ 'error' => '404' ];
			add_action( 'send_headers', 'nocache_headers', PHP_INT_MAX );
			return;
		}
		$method   = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		$response = self::opml_response( $method );
		status_header( $response['status'] );
		header_remove( 'Last-Modified' );
		foreach ( $response['headers'] as $name => $value ) {
			header( $name . ': ' . $value );
		}
		echo $response['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Complete escaped XML document.
		exit;
	}

	/** Headers for successful OPML responses. */
	public static function opml_headers(): array {
		$headers = array_filter( wp_get_nocache_headers(), 'is_string' );
		return [
			'Content-Type' => 'text/x-opml; charset=UTF-8',
			'X-Robots-Tag' => 'noindex',
		] + $headers;
	}

	/** Build an HTTP response without sending it. */
	public static function opml_response( string $method ): array {
		if ( isset( $GLOBALS['wp'] ) && $GLOBALS['wp'] instanceof \WP ) {
			$GLOBALS['wp']->add_query_var( self::VAR_INITIAL );
			$GLOBALS['wp']->add_query_var( self::VAR_OPML );
		}
		$headers = self::opml_headers();
		if ( ! term_exists( self::KIND, Taxonomy::TAXONOMY ) ) {
			return [
				'status'  => 404,
				'headers' => $headers,
				'body'    => '',
			];
		}
		if ( ! in_array( $method, [ 'GET', 'HEAD' ], true ) ) {
			$headers['Allow'] = 'GET, HEAD';
			return [
				'status'  => 405,
				'headers' => $headers,
				'body'    => '',
			];
		}
		return [
			'status'  => 200,
			'headers' => $headers,
			'body'    => 'HEAD' === $method ? '' : self::opml_document( self::served_index()['follows'] ),
		];
	}

	/** Build the OPML document from already-gated rows. */
	public static function opml_document( array $follows ): string {
		/* translators: %s: Site name. */
		$title    = sprintf( __( '%s follows', 'post-kinds-for-indieweb-in-block-themes' ), get_bloginfo( 'name' ) );
		$newest   = 0;
		$outlines = '';
		foreach ( $follows as $follow ) {
			$modified = strtotime( (string) ( $follow['modified_gmt'] ?? '' ) );
			$newest   = false !== $modified ? max( $newest, $modified ) : $newest;
			$raw_url  = (string) ( $follow['url'] ?? '' );
			$parts    = wp_parse_url( $raw_url );
			$web_url  = is_array( $parts ) && in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), [ 'http', 'https' ], true ) && '' !== (string) ( $parts['host'] ?? '' );
			$url      = $web_url ? web_url( $raw_url ) : '';
			if ( '' === $url ) {
				continue;
			}
			$name      = self::xml_value( (string) ( $follow['name'] ?? '' ) );
			$url       = self::xml_value( $url );
			$outlines .= '    <outline type="link" text="' . esc_xml( $name ) . '" title="' . esc_xml( $name ) . '" url="' . esc_xml( $url ) . '" htmlUrl="' . esc_xml( $url ) . '"/>' . "\n";
		}
		$xml  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<opml version=\"2.0\">\n  <head>\n";
		$xml .= '    <title>' . esc_xml( self::xml_value( $title ) ) . "</title>\n";
		if ( $newest > 0 ) {
			$xml .= '    <dateModified>' . esc_xml( gmdate( DATE_RSS, $newest ) ) . "</dateModified>\n";
		}
		$xml .= "    <docs>https://opml.org/spec2.opml</docs>\n  </head>\n  <body>\n" . $outlines . "  </body>\n</opml>\n";
		return $xml;
	}

	/** Print the archive's OPML discovery link. */
	public static function opml_alternate_link(): void {
		if ( ! is_tax( Taxonomy::TAXONOMY, self::KIND ) ) {
			return;
		}
		/* translators: %s: Site name. */
		$title = sprintf( __( '%s follows', 'post-kinds-for-indieweb-in-block-themes' ), get_bloginfo( 'name' ) );
		echo '<link rel="alternate" type="text/x-opml" title="' . esc_attr( $title ) . '" href="' . esc_url( self::opml_url() ) . '">' . "\n";
	}

	/** Normalize a name to its public initial. */
	public static function initial( string $name ): string {
		$name = trim( $name );
		if ( class_exists( '\\Normalizer' ) ) {
			$normalized = \Normalizer::normalize( $name, \Normalizer::FORM_C );
			$name       = is_string( $normalized ) ? $normalized : $name;
		}
		if ( '' === $name ) {
			return self::OTHER_INITIAL;
		}
		$first = function_exists( 'grapheme_substr' ) ? (string) grapheme_substr( $name, 0, 1 ) : mb_substr( $name, 0, 1 );
		if ( 1 !== preg_match( '/^\p{L}/u', $first ) ) {
			return self::OTHER_INITIAL;
		}
		$upper = mb_strtoupper( $first );
		return mb_strlen( $upper ) === mb_strlen( $first ) ? $upper : $first;
	}

	/** Use eight items in archive previews. */
	public static function preview_per_page( int $per_page, string $kind ): int {
		return self::KIND === $kind ? self::PER_PAGE : $per_page;
	}

	/** Register both PHP-rendered follow blocks. */
	public static function register_blocks(): void {
		wp_register_script( 'pkiw-follow-entry-editor', PKIW_URL . 'assets/js/follow-entry-editor.js', [ 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ], PKIW_VERSION, true );
		wp_set_script_translations( 'pkiw-follow-entry-editor', 'post-kinds-for-indieweb-in-block-themes' );
		wp_register_style( 'pkiw-follow-archive', PKIW_URL . 'styles/follow-archive.css', [], PKIW_VERSION );

		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( self::ENTRY_BLOCK ) ) {
			/**
			 * Follow entry block arguments.
			 *
			 * @var array<string, mixed> $entry_args
			 */
			$entry_args = [
				'api_version'     => 3,
				'title'           => __( 'Follow entry', 'post-kinds-for-indieweb-in-block-themes' ),
				'category'        => 'post-kinds-indieweb',
				'render_callback' => [ self::class, 'render_entry_block' ],
				'uses_context'    => [ 'postId', 'postType', 'queryId', 'templateSlug' ],
				'attributes'      => [
					'headingLevel' => [
						'type'    => 'integer',
						'default' => 2,
					],
				],
				'supports'        => [
					'html'     => false,
					'reusable' => false,
				],
				'editor_script'   => 'pkiw-follow-entry-editor',
				'style'           => 'pkiw-follow-archive',
				'example'         => [ 'attributes' => [ 'headingLevel' => 2 ] ],
			];
			register_block_type( self::ENTRY_BLOCK, $entry_args );
		}
		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( self::LETTERS_BLOCK ) ) {
			/**
			 * Follow letters block arguments.
			 *
			 * @var array<string, mixed> $letters_args
			 */
			$letters_args = [
				'api_version'     => 3,
				'title'           => __( 'Follow letters', 'post-kinds-for-indieweb-in-block-themes' ),
				'category'        => 'post-kinds-indieweb',
				'render_callback' => [ self::class, 'render_letters_block' ],
				'attributes'      => [
					'label'    => [
						'type'    => 'string',
						'role'    => 'content',
						'default' => __( 'Follows by first letter', 'post-kinds-for-indieweb-in-block-themes' ),
					],
					'opmlLink' => [
						'type'    => 'boolean',
						'default' => true,
					],
				],
				'supports'        => [
					'html'     => false,
					'reusable' => false,
				],
				'editor_script'   => 'pkiw-follow-entry-editor',
				'style'           => 'pkiw-follow-archive',
				'example'         => [ 'attributes' => [] ],
			];
			register_block_type( self::LETTERS_BLOCK, $letters_args );
		}
	}

	/** Render one follow archive entry. */
	public static function render_entry_block( array $attributes = [], string $content = '', ?\WP_Block $block = null ): string {
		$post_id = (int) ( $block?->context['postId'] ?? 0 );
		$facts   = $post_id > 0 ? self::facts( $post_id ) : [];
		if ( [] === $facts ) {
			return '';
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}
		$GLOBALS['pkiw_entry_root_seen'][ $post_id ] = true;
		$level                                       = max( 2, min( 4, (int) ( $attributes['headingLevel'] ?? 2 ) ) );
		$name_id                                     = 'pkiw-follow-' . $post_id . '-name';
		$host_id                                     = 'pkiw-follow-' . $post_id . '-host';
		$host                                        = (string) $facts['host'];
		$name_repeats                                = self::name_repeats( (string) $facts['name'], $post_id );
		$show_host                                   = '' !== $host && ( 'host' !== $facts['name_source'] || $name_repeats );
		$labelled                                    = $show_host && $name_repeats ? ' aria-labelledby="' . esc_attr( $name_id . ' ' . $host_id ) . '"' : '';
		$html                                        = '<article class="pkiw-follow-entry h-entry" data-pkiw-follow-initial="' . esc_attr( (string) $facts['initial'] ) . '">';
		if ( (int) $facts['photo_id'] > 0 ) {
			$high  = self::is_first_four( $post_id );
			$html .= '<figure class="pkiw-follow-entry__photo" data-cr-cover-fallback aria-hidden="true">' . wp_get_attachment_image(
				(int) $facts['photo_id'],
				'medium',
				false,
				[
					'alt'           => '',
					'class'         => 'pkiw-follow-entry__image',
					'loading'       => $high ? 'eager' : 'lazy',
					'fetchpriority' => $high ? 'high' : 'auto',
				]
			) . '</figure>';
		}
		$html .= '<span class="pkiw-follow-entry__initial" aria-hidden="true">' . esc_html( (string) $facts['initial'] ) . '</span>';
		$html .= '<h' . $level . ' class="pkiw-follow-entry__name"><a class="u-url" id="' . esc_attr( $name_id ) . '" href="' . esc_url( (string) get_permalink( $post ) ) . '"' . $labelled . '>' . esc_html( (string) $facts['name'] ) . '</a></h' . $level . '>';
		if ( $show_host ) {
			$html .= '<p class="pkiw-follow-entry__host" id="' . esc_attr( $host_id ) . '">' . esc_html( $host ) . '</p>';
		}
		$html  .= '<time class="dt-published" datetime="' . esc_attr( (string) $facts['date_iso'] ) . '" hidden></time>';
		$author = entry_author_html( $post );
		if ( '' !== $author ) {
			$html .= '<span hidden>' . $author . '</span>';
		}
		if ( '' !== (string) $facts['url'] ) {
			$html .= '<data class="u-follow-of" value="' . esc_url( (string) $facts['url'] ) . '" hidden></data>';
		}
		return $html . '</article>';
	}

	/** Render the A-Z navigation and visible OPML link. */
	public static function render_letters_block( array $attributes = [] ): string {
		$index = self::served_index();
		if ( [] === $index['letters'] ) {
			return '';
		}
		$link = get_term_link( self::KIND, Taxonomy::TAXONOMY );
		if ( is_wp_error( $link ) ) {
			return '';
		}
		$active = Grouped_Archive::is_block_preview() ? '' : get_query_var( self::VAR_INITIAL );
		$active = is_scalar( $active ) ? trim( (string) $active ) : '';
		$active = '' !== $active ? self::initial( $active ) : '';
		$label  = (string) ( $attributes['label'] ?? __( 'Follows by first letter', 'post-kinds-for-indieweb-in-block-themes' ) );
		$html   = '<nav class="pkiw-follow-letters" aria-label="' . esc_attr( $label ) . '"><ul>';
		if ( '' !== $active ) {
			$html .= '<li><a href="' . esc_url( $link ) . '">' . esc_html__( 'All', 'post-kinds-for-indieweb-in-block-themes' ) . '</a></li>';
		}
		foreach ( array_keys( $index['letters'] ) as $letter ) {
			$url   = add_query_arg( self::VAR_INITIAL, '__pkiw_follow_initial__', $link );
			$url   = str_replace( '__pkiw_follow_initial__', rawurlencode( (string) $letter ), $url );
			$html .= '<li><a href="' . esc_url( $url ) . '"' . ( $letter === $active ? ' aria-current="page"' : '' ) . '>' . esc_html( (string) $letter ) . '</a></li>';
		}
		$html .= '</ul></nav>';
		if ( ! array_key_exists( 'opmlLink', $attributes ) || $attributes['opmlLink'] ) {
			$html .= '<p class="pkiw-follow-letters__opml"><a href="' . esc_url( self::opml_url() ) . '" type="text/x-opml">' . esc_html__( 'Follows as OPML', 'post-kinds-for-indieweb-in-block-themes' ) . '</a></p>';
		}
		return $html;
	}

	/** Delete the cached index. */
	public static function flush_index(): void {
		delete_transient( self::INDEX_TRANSIENT );
	}

	/** Flush after any kind relationship changes. */
	public static function terms_changed( int $object_id, $terms, array $tt_ids, string $taxonomy, bool $append, array $old_tt_ids ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Core hook signature.
		if ( Taxonomy::TAXONOMY === $taxonomy ) {
			self::flush_index();
		}
	}

	/** Flush after deleting a kind relationship. */
	public static function term_relationships_deleted( int $object_id, array $tt_ids, string $taxonomy ): void {
		if ( Taxonomy::TAXONOMY === $taxonomy ) {
			self::flush_index();
		}
	}

	/** Flush when a follow identity or image key changes. */
	public static function meta_changed( $meta_id, int $object_id, string $meta_key, $meta_value = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Metadata hook signature.
		if ( in_array( $meta_key, [ '_pkiw_cite_name', '_pkiw_cite_url', '_pkiw_cite_host', '_thumbnail_id' ], true ) ) {
			self::flush_index();
		}
	}

	/** Compare public letter keys with the catch-all last. */
	private static function compare_initials( string $a, string $b ): int {
		if ( self::OTHER_INITIAL === $a || self::OTHER_INITIAL === $b ) {
			return self::OTHER_INITIAL === $a ? ( self::OTHER_INITIAL === $b ? 0 : 1 ) : -1;
		}
		$folded = strcmp( mb_strtolower( remove_accents( $a ) ), mb_strtolower( remove_accents( $b ) ) );
		return 0 !== $folded ? $folded : strcmp( $a, $b );
	}

	/** OPML endpoint URL. */
	private static function opml_url(): string {
		$link = get_term_link( self::KIND, Taxonomy::TAXONOMY );
		return is_wp_error( $link ) ? '' : trailingslashit( $link ) . 'opml/';
	}

	/** Strip bytes XML 1.0 cannot represent. */
	private static function xml_value( string $value ): string {
		return (string) preg_replace( '/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value );
	}

	/** Whether this name repeats in the current page of posts. */
	private static function name_repeats( string $name, int $post_id ): bool {
		$query = $GLOBALS['wp_query'] ?? null;
		if ( ! $query instanceof \WP_Query ) {
			return false;
		}
		$count = 0;
		foreach ( (array) $query->posts as $candidate ) {
			$id = $candidate instanceof \WP_Post ? $candidate->ID : (int) $candidate;
			if ( $id < 1 ) {
				continue;
			}
			$facts = self::facts( $id );
			if ( [] !== $facts && mb_strtolower( (string) $facts['name'] ) === mb_strtolower( $name ) ) {
				++$count;
			}
		}
		return $count > 1 && $post_id > 0;
	}

	/** Whether an image is in the first four posts on this page. */
	private static function is_first_four( int $post_id ): bool {
		$query = $GLOBALS['wp_query'] ?? null;
		if ( ! $query instanceof \WP_Query ) {
			return false;
		}
		if ( (int) $query->get( 'paged' ) > 1 ) {
			return false;
		}
		$ids = array_map( static fn( $post ): int => $post instanceof \WP_Post ? $post->ID : (int) $post, array_slice( (array) $query->posts, 0, 4 ) );
		return in_array( $post_id, $ids, true );
	}

	/** Read the stored cite photo without the public metadata filter. */
	private static function raw_cite_photo( int $post_id ): string {
		remove_filter( 'get_post_metadata', [ self::class, 'filter_cite_photo' ], 10 );
		try {
			$value = get_metadata_raw( 'post', $post_id, '_pkiw_cite_photo', true );
			return is_string( $value ) ? $value : '';
		} finally {
			add_filter( 'get_post_metadata', [ self::class, 'filter_cite_photo' ], 10, 5 );
		}
	}
}
