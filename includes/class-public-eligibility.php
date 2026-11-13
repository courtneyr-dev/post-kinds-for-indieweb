<?php
/**
 * Shared public eligibility for person-bearing post kinds.
 *
 * @package PKIW
 * @since 1.9.2
 */

declare(strict_types=1);

namespace PKIW;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keep every public surface on one status and password rule. */
final class Public_Eligibility {

	public const KINDS = [ 'tag', 'follow' ];

	/**
	 * Active REST request contexts, including nested requests.
	 *
	 * @var string[]
	 */
	private static array $rest_contexts = [];

	/**
	 * Test-only interactive CLI override.
	 *
	 * @var bool|null
	 */
	private static ?bool $cli_override = null;

	/**
	 * Whether hooks have already been registered.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	// The public API types below are the contract; repeating them in every docblock adds no information.
	// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag

	/** Register public-surface and outbound-contact gates. */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_filter( 'posts_where', [ self::class, 'filter_where' ], 20, 2 );
		add_filter( 'get_previous_post_where', [ self::class, 'filter_adjacent_where' ], 20 );
		add_filter( 'get_next_post_where', [ self::class, 'filter_adjacent_where' ], 20 );
		add_filter( 'comment_feed_where', [ self::class, 'filter_comment_feed_where' ], 20, 2 );
		add_filter( 'rest_request_before_callbacks', [ self::class, 'rest_request_before' ], 10, 3 );
		add_filter( 'rest_request_after_callbacks', [ self::class, 'rest_request_after' ], 10, 3 );
		add_action( 'template_redirect', [ self::class, 'refuse_hidden_request' ], 1 );
		add_filter( 'redirect_canonical', [ self::class, 'filter_redirect_canonical' ], 1, 2 );
		add_filter( 'old_slug_redirect_post_id', [ self::class, 'filter_old_slug_redirect' ], 1 );
		add_filter( 'get_post_metadata', [ self::class, 'filter_webmention_gate' ], 10, 5 );
		add_filter( 'webmention_links', [ self::class, 'filter_webmention_links' ], 10, 2 );
		add_action( 'pre_ping', [ self::class, 'filter_pre_ping' ], 10, 3 );
		add_filter( 'enclosure_links', [ self::class, 'filter_enclosure_links' ], 10, 2 );
		add_action( 'init', [ self::class, 'replace_webmention_reply_callback' ], 100 );
		add_action( 'save_post', [ self::class, 'purge_after_save' ], 30, 2 );
		add_action( 'transition_post_status', [ self::class, 'purge_after_transition' ], 30, 3 );
		add_action( 'post_updated', [ self::class, 'purge_after_update' ], 30, 3 );
	}

	/** Whether a post may appear on any public surface. */
	public static function is_eligible( \WP_Post|int|null $post ): bool {
		$post = get_post( $post );
		return $post instanceof \WP_Post
			&& 'publish' === $post->post_status
			&& '' === (string) $post->post_password
			&& is_post_publicly_viewable( $post );
	}

	/**
	 * SQL equivalent for post rows.
	 *
	 * @throws \InvalidArgumentException For an unsafe table alias.
	 */
	public static function sql( string $alias ): string {
		if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $alias ) ) {
			throw new \InvalidArgumentException( 'Invalid SQL table alias.' );
		}
		return "{$alias}.post_status = 'publish' AND {$alias}.post_password = ''";
	}

	/** Whether the predicate hides this post because it has a governed kind. */
	public static function hides( \WP_Post|int|null $post ): bool {
		$post = get_post( $post );
		return $post instanceof \WP_Post && self::has_governed_kind( $post ) && ! self::is_eligible( $post );
	}

	/** Remove hidden governed posts from an ID list without reordering it. */
	public static function filter_ids( array $ids ): array {
		return array_values(
			array_filter(
				$ids,
				static fn( $id ): bool => ! self::hides( (int) $id )
			)
		);
	}

	/** Return a media-library image only when its parent is public. */
	public static function public_image_id( int $attachment_id ): int {
		if ( $attachment_id < 1 || ! wp_attachment_is_image( $attachment_id ) ) {
			return 0;
		}
		$parent = (int) wp_get_post_parent_id( $attachment_id );
		return 0 === $parent || self::is_eligible( $parent ) ? $attachment_id : 0;
	}

	/** Apply the governed-kind clause to public list queries. */
	public static function filter_where( string $where, \WP_Query $query ): string {
		$forced = true === $query->get( 'pkiw_public_only' );
		if ( ! $forced && self::skip_query_filter( $query ) ) {
			return $where;
		}
		return $where . self::kind_clause( self::posts_table() );
	}

	/** Apply the same clause to core adjacent-post queries. */
	public static function filter_adjacent_where( string $where ): string {
		return $where . self::kind_clause( 'p' );
	}

	/** Exclude comments attached to hidden governed posts. */
	public static function filter_comment_feed_where( string $where, \WP_Query $query ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return $where . self::kind_clause( self::posts_table() );
	}

	/** Push the REST context and refuse hidden core post items in view context. */
	public static function rest_request_before( $response, array $handler, \WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		self::$rest_contexts[] = (string) $request->get_param( 'context' );
		if ( 'edit' === (string) $request->get_param( 'context' ) ) {
			return $response;
		}

		$route = $request->get_route();
		if ( 1 === preg_match( '#^/wp/v2/posts/(?P<id>[0-9]+)$#', $route, $matches ) && self::hides( (int) $matches['id'] ) ) {
			return new \WP_Error( 'rest_post_invalid_id', __( 'Invalid post ID.', 'default' ), [ 'status' => 404 ] ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Exact core missing-ID response.
		}
		return $response;
	}

	/** Restore the outer REST context after nested requests. */
	public static function rest_request_after( $response, array $handler, \WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		array_pop( self::$rest_contexts );
		return $response;
	}

	/** Refuse hidden singles and singular comment feeds before templates run. */
	public static function refuse_hidden_request(): void {
		global $wp_query;

		$post = self::requested_post();
		if ( ! $post instanceof \WP_Post || ! self::hides( $post ) || self::is_author_preview( $post ) ) {
			return;
		}
		$wp_query->set_404();
		$wp_query->is_feed         = false;
		$wp_query->is_comment_feed = false;
		status_header( 404 );
		nocache_headers();
	}

	/** Stop canonical redirects from revealing a hidden slug. */
	public static function filter_redirect_canonical( $redirect_url, string $requested_url ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return self::hides( self::requested_post() ) ? false : $redirect_url;
	}

	/** Stop old-slug redirects from revealing a hidden post. */
	public static function filter_old_slug_redirect( int $post_id ): int {
		return self::hides( $post_id > 0 ? $post_id : self::requested_post() ) ? 0 : $post_id;
	}

	/** Make Webmention's own early gate true for every hidden governed post. */
	public static function filter_webmention_gate( $value, int $object_id, string $meta_key, bool $single, string $meta_type ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( 'post' === $meta_type && 'webmentions_disabled_pings' === $meta_key && self::hides( $object_id ) ) {
			return [ '1' ];
		}
		return $value;
	}

	/** Add a public person-tag target, and remove all targets when hidden. */
	public static function filter_webmention_links( array $urls, int $post_id ): array {
		$post = get_post( $post_id );
		if ( self::hides( $post ) ) {
			return [];
		}
		if ( $post instanceof \WP_Post && self::is_eligible( $post ) && has_term( 'tag', Taxonomy::TAXONOMY, $post ) && class_exists( __NAMESPACE__ . '\\Tag_Archive' ) ) {
			$raw = Tag_Archive::raw( $post );
			if ( 'person' === ( $raw['target_type'] ?? '' ) && '' !== ( $raw['url'] ?? '' ) ) {
				$urls[] = (string) $raw['url'];
			}
		}
		return array_values( array_unique( array_filter( array_map( 'esc_url_raw', $urls ) ) ) );
	}

	/** Prevent core pingbacks for hidden governed posts. */
	public static function filter_pre_ping( array &$links, array &$pung, int $post_id ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( self::hides( $post_id ) ) {
			$links = [];
		}
	}

	/** Remove person/tag targets from enclosure probes, in every status. */
	public static function filter_enclosure_links( array $links, int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! self::has_governed_kind( $post ) ) {
			return $links;
		}
		if ( ! self::is_eligible( $post ) ) {
			return [];
		}

		$targets = array_map( [ self::class, 'normalize_url' ], self::target_urls( $post ) );
		return array_values(
			array_filter(
				$links,
				static fn( string $url ): bool => ! in_array( self::normalize_url( $url ), $targets, true )
			)
		);
	}

	/** Replace Webmention's reply callback with a post-aware proxy. */
	public static function replace_webmention_reply_callback(): void {
		$callback = [ 'Webmention\\Sender', 'comment_post' ];
		$priority = has_action( 'comment_post', $callback );
		if ( false === $priority || ! remove_action( 'comment_post', $callback, (int) $priority ) ) {
			return;
		}
		add_action( 'comment_post', [ self::class, 'proxy_webmention_reply' ], (int) $priority, 3 );
	}

	/** Call Webmention's original reply sender only for an eligible post. */
	public static function proxy_webmention_reply( int $comment_id, $approved = null, array $comment_data = [] ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$comment = get_comment( $comment_id );
		if ( ! $comment instanceof \WP_Comment || self::hides( (int) $comment->comment_post_ID ) ) {
			return;
		}
		call_user_func( [ 'Webmention\\Sender', 'comment_post' ], $comment_id );
	}

	/** Test seam for interactive WP-CLI detection. */
	public static function override_cli_for_tests( ?bool $value ): void {
		self::$cli_override = $value;
	}

	/** Purge after saves that can affect a public governed post. */
	public static function purge_after_save( int $post_id, \WP_Post $post ): void {
		if ( ! wp_is_post_revision( $post_id ) && self::has_governed_kind( $post ) && self::is_eligible( $post ) ) {
			self::purge( $post );
		}
	}

	/** Purge every governed eligibility transition. */
	public static function purge_after_transition( string $new_status, string $old_status, \WP_Post $post ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$before              = clone $post;
		$before->post_status = $old_status;
		if ( self::has_governed_kind( $post ) && ( self::is_eligible( $before ) || self::is_eligible( $post ) ) ) {
			self::purge( $post );
		}
	}

	/** Purge public edits and password flips with the real before image. */
	public static function purge_after_update( int $post_id, \WP_Post $after, \WP_Post $before ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( self::has_governed_kind( $after ) && ( self::is_eligible( $before ) || self::is_eligible( $after ) ) ) {
			self::purge( $after );
		}
	}

	/** Whether a list query is an authenticated editor or maintenance query. */
	private static function skip_query_filter( \WP_Query $query ): bool {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return true;
		}
		if ( $query->is_main_query() && $query->is_singular() ) {
			return true;
		}
		if ( self::is_cli() ) {
			return true;
		}
		$context = end( self::$rest_contexts );
		if ( 'edit' !== $context ) {
			return false;
		}
		$type = get_post_type_object( 'post' );
		return $type instanceof \WP_Post_Type
			&& current_user_can( $type->cap->edit_others_posts )
			&& current_user_can( $type->cap->edit_private_posts )
			&& current_user_can( $type->cap->edit_published_posts );
	}

	/** The governed-kind SQL wrapper, or an empty string when no term exists. */
	private static function kind_clause( string $alias ): string {
		global $wpdb;

		$ids = self::term_taxonomy_ids();
		if ( [] === $ids ) {
			return '';
		}
		$id_list = implode( ',', array_map( 'intval', $ids ) );
		return " AND NOT ( {$alias}.ID IN ( SELECT pkiw_public_tr.object_id FROM {$wpdb->term_relationships} pkiw_public_tr WHERE pkiw_public_tr.term_taxonomy_id IN ( {$id_list} ) ) AND NOT ( " . self::sql( $alias ) . ' ) )';
	}

	/** Return governed term-taxonomy IDs. @return int[] */
	private static function term_taxonomy_ids(): array {
		static $cache = null;

		$last_changed = wp_cache_get_last_changed( 'terms' );
		if ( is_array( $cache ) && $last_changed === $cache['last_changed'] ) {
			return $cache['ids'];
		}
		$ids = [];
		foreach ( self::KINDS as $slug ) {
			$term = get_term_by( 'slug', $slug, Taxonomy::TAXONOMY );
			if ( $term instanceof \WP_Term ) {
				$ids[] = (int) $term->term_taxonomy_id;
			}
		}
		$cache = [
			'last_changed' => $last_changed,
			'ids'          => $ids,
		];
		return $ids;
	}

	/** Whether a post carries tag or follow. */
	private static function has_governed_kind( \WP_Post $post ): bool {
		return has_term( self::KINDS, Taxonomy::TAXONOMY, $post );
	}

	/** Current posts table name. */
	private static function posts_table(): string {
		global $wpdb;
		return $wpdb->posts;
	}

	/** Interactive WP-CLI skips list filtering; cron under WP-CLI does not. */
	private static function is_cli(): bool {
		return self::$cli_override ?? ( defined( 'WP_CLI' ) && WP_CLI && ! wp_doing_cron() );
	}

	/** Resolve the requested post without falling back to the global post. */
	private static function requested_post(): ?\WP_Post {
		$queried = get_queried_object();
		if ( $queried instanceof \WP_Post ) {
			return $queried;
		}
		$id = max( 0, (int) get_query_var( 'p' ), (int) get_query_var( 'page_id' ) );
		if ( $id > 0 ) {
			$post = get_post( $id );
			return $post instanceof \WP_Post ? $post : null;
		}
		$name = sanitize_title( (string) get_query_var( 'name' ) );
		$post = '' !== $name ? get_page_by_path( $name, OBJECT, 'post' ) : null;
		return $post instanceof \WP_Post ? $post : null;
	}

	/** Authenticated wp-admin previews remain editor contexts. */
	private static function is_author_preview( \WP_Post $post ): bool {
		return is_preview() && current_user_can( 'edit_post', $post->ID );
	}

	/** Return outbound target URLs embedded by governed blocks. @return string[] */
	private static function target_urls( \WP_Post $post ): array {
		$urls = [];
		foreach ( flatten_blocks( parse_blocks( (string) $post->post_content ) ) as $block ) {
			if ( in_array( (string) ( $block['blockName'] ?? '' ), [ 'post-kinds-indieweb/tag-card', 'post-kinds-indieweb/follow-card' ], true ) ) {
				$url = web_url( (string) ( $block['attrs']['url'] ?? '' ) );
				if ( '' !== $url ) {
					$urls[] = $url;
				}
			}
		}
		$cite = web_url( (string) get_post_meta( $post->ID, '_pkiw_cite_url', true ) );
		if ( '' !== $cite ) {
			$urls[] = $cite;
		}

		$processor = new \WP_HTML_Tag_Processor( (string) $post->post_content );
		$depth     = 0;
		while ( $processor->next_tag() ) {
			if ( $processor->is_tag_closer() ) {
				if ( $depth > 0 && in_array( $processor->get_tag(), [ 'SPAN', 'A' ], true ) ) {
					--$depth;
				}
				continue;
			}
			$is_target = $processor->has_class( 'u-tag-of' ) || $processor->has_class( 'u-follow-of' );
			if ( $is_target ) {
				++$depth;
			}
			if ( 'A' === $processor->get_tag() && ( $is_target || ( $depth > 0 && $processor->has_class( 'u-url' ) ) ) ) {
				$url = web_url( (string) $processor->get_attribute( 'href' ) );
				if ( '' !== $url ) {
					$urls[] = $url;
				}
			}
		}
		return array_values( array_unique( $urls ) );
	}

	/** URL equality used by core's enclosure list. */
	private static function normalize_url( string $url ): string {
		$url    = esc_url_raw( $url, [ 'http', 'https' ] );
		$parts  = wp_parse_url( $url );
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( '' === $scheme || '' === $host ) {
			return '';
		}
		$port  = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path  = rtrim( (string) ( $parts['path'] ?? '' ), '/' );
		$query = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		return "{$scheme}://{$host}{$port}{$path}{$query}";
	}

	/** Queue every known public route at Pantheon's edge. */
	private static function purge( \WP_Post $post ): void {
		if ( ! function_exists( 'Pantheon_Cache' ) ) {
			return;
		}
		$kind = get_post_kind_slug( $post );
		if ( ! in_array( $kind, self::KINDS, true ) ) {
			return;
		}
		$paths = [
			'urls'  => [
				(string) get_permalink( $post ),
				home_url( '/' ),
				home_url( '/stream/' ),
				home_url( '/kind/' . $kind . '/' ),
				home_url( '/feed/' ),
				home_url( '/kind/' . $kind . '/feed/' ),
				home_url( '/comments/feed/' ),
			],
			'regex' => [ '^/kind/' . $kind . '/page/', '^/stream/page/' ],
		];
		$paths = apply_filters( 'pkiw_public_eligibility_purge_urls', $paths, $post );
		if ( ! is_array( $paths ) ) {
			return;
		}
		$cache = Pantheon_Cache();
		if ( is_object( $cache ) ) {
			$cache->enqueue_urls( array_values( array_unique( array_map( 'strval', (array) ( $paths['urls'] ?? [] ) ) ) ) );
			$cache->enqueue_regex( array_values( array_unique( array_map( 'strval', (array) ( $paths['regex'] ?? [] ) ) ) ) );
		}
	}
}
