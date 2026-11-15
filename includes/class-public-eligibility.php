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
	 * @var array<int, array{id: int, context: string}>
	 */
	private static array $rest_contexts = [];

	/**
	 * True while a redirect target resolves, so the list filter can't hide the post being checked.
	 *
	 * @var bool
	 */
	private static bool $resolving_target = false;

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
		add_action( 'pre_get_comments', [ self::class, 'partition_comment_cache' ], 20 );
		add_filter( 'comments_clauses', [ self::class, 'filter_comment_clauses' ], 20, 2 );
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
		add_action( 'set_object_terms', [ self::class, 'purge_after_terms' ], 30, 6 );
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
		if ( self::$resolving_target || ( ! $forced && self::skip_query_filter( $query ) ) ) {
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
		if ( $query->is_singular() || self::query_excludes_governed_posts( $query ) ) {
			return $where;
		}
		return $where . self::kind_clause( self::posts_table(), self::editable_hidden_ids() );
	}

	/** Keep public and editor comment results in separate core cache entries. */
	public static function partition_comment_cache( \WP_Comment_Query $query ): void {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		$editable_ids = self::editable_hidden_ids();
		sort( $editable_ids, SORT_NUMERIC );
		$scope = implode(
			':',
			[
				wp_cache_get_last_changed( 'posts' ),
				wp_cache_get_last_changed( 'terms' ),
				implode( ',', $editable_ids ),
			]
		);

		$query->query_vars['cache_domain'] = (string) ( $query->query_vars['cache_domain'] ?? 'core' ) . ':pkiw-public-eligibility:' . md5( $scope );
	}

	/** Exclude comments attached to hidden governed posts outside editor contexts. */
	public static function filter_comment_clauses( array $clauses, \WP_Comment_Query $query ): array {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $clauses;
		}
		$post_id = (int) ( $query->query_vars['post_id'] ?? 0 );
		if ( $post_id > 0 && current_user_can( 'edit_post', $post_id ) ) {
			return $clauses;
		}

		$alias = 'pkiw_comment_post';
		if ( ! str_contains( (string) $clauses['join'], " {$alias} " ) ) {
			$clauses['join'] .= ' INNER JOIN ' . self::posts_table() . " {$alias} ON {$alias}.ID = {$GLOBALS['wpdb']->comments}.comment_post_ID";
		}
		$clauses['where'] .= self::kind_clause( $alias, self::editable_hidden_ids() );
		return $clauses;
	}

	/** Push the REST context and make hidden post and comment routes look missing. */
	public static function rest_request_before( $response, $handler, \WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		self::$rest_contexts[] = [
			'id'      => spl_object_id( $request ),
			'context' => (string) $request->get_param( 'context' ),
		];

		$post_id = self::rest_item_post_id( $request );
		if ( $post_id > 0 && self::hides( $post_id ) && ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'rest_post_invalid_id', __( 'Invalid post ID.', 'default' ), [ 'status' => 404 ] ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Exact core missing-ID response.
		}

		if ( 'GET' === $request->get_method() && '/wp/v2/comments' === $request->get_route() ) {
			$comment_post_id = (int) $request->get_param( 'post' );
			if ( $comment_post_id > 0 && self::hides( $comment_post_id ) && ! current_user_can( 'edit_post', $comment_post_id ) ) {
				return new \WP_REST_Response(
					[],
					200,
					[
						'X-WP-Total'      => 0,
						'X-WP-TotalPages' => 0,
					]
				);
			}
		}
		return $response;
	}

	/** Restore the outer REST context after nested requests. */
	public static function rest_request_after( $response, $handler, \WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$request_id          = spl_object_id( $request );
		self::$rest_contexts = array_values(
			array_filter(
				self::$rest_contexts,
				static fn( array $entry ): bool => $request_id !== $entry['id']
			)
		);
		return $response;
	}

	/** Refuse hidden singles and singular comment feeds before templates run. */
	public static function refuse_hidden_request(): void {
		global $wp_query;

		$post = self::requested_post();
		if ( ! $post instanceof \WP_Post || ! self::hides( $post ) || current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}
		$wp_query->set_404();
		$wp_query->is_feed         = false;
		$wp_query->is_comment_feed = false;
		$wp_query->is_embed        = false;
		status_header( 404 );
		nocache_headers();
	}

	/** Stop canonical redirects from revealing a hidden slug. */
	public static function filter_redirect_canonical( $redirect_url, string $requested_url ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$requested = self::requested_post();
		if ( self::hides( $requested ) && ! self::can_edit( $requested ) ) {
			return false;
		}
		$target_id = 0;
		if ( is_string( $redirect_url ) && '' !== $redirect_url ) {
			self::$resolving_target = true;
			try {
				$target_id = url_to_postid( $redirect_url );
			} finally {
				self::$resolving_target = false;
			}
		}
		return $target_id > 0 && self::hides( $target_id ) && ! current_user_can( 'edit_post', $target_id ) ? false : $redirect_url;
	}

	/** Stop old-slug redirects from revealing a hidden post. */
	public static function filter_old_slug_redirect( int $post_id ): int {
		$post = $post_id > 0 ? get_post( $post_id ) : self::requested_post();
		return self::hides( $post ) && ! self::can_edit( $post ) ? 0 : $post_id;
	}

	/** Make Webmention's own early gate true for every hidden governed post. */
	public static function filter_webmention_gate( $value, $object_id, $meta_key, $single, $meta_type ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( 'post' === $meta_type && 'webmentions_disabled_pings' === $meta_key && self::hides( $object_id ) ) {
			return [ '1' ];
		}
		return $value;
	}

	/** Add a public person-tag target, and remove all targets when hidden. */
	public static function filter_webmention_links( array $urls, int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! self::has_governed_kind( $post ) ) {
			return $urls;
		}
		if ( self::hides( $post ) ) {
			return [];
		}
		if ( self::is_eligible( $post ) && has_term( 'tag', Taxonomy::TAXONOMY, $post ) && class_exists( __NAMESPACE__ . '\\Tag_Archive' ) ) {
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

	/** Purge a governed archive when its term is removed from a post. */
	public static function purge_after_terms( int $object_id, $terms, array $term_taxonomy_ids, string $taxonomy, bool $append, array $old_term_taxonomy_ids ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( Taxonomy::TAXONOMY !== $taxonomy ) {
			return;
		}
		$post = get_post( $object_id );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		foreach ( self::KINDS as $kind ) {
			$term = get_term_by( 'slug', $kind, Taxonomy::TAXONOMY );
			if ( $term instanceof \WP_Term && in_array( (int) $term->term_taxonomy_id, $old_term_taxonomy_ids, true ) && ! in_array( (int) $term->term_taxonomy_id, $term_taxonomy_ids, true ) ) {
				self::purge( $post, [ $kind ] );
			}
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
		if ( self::query_excludes_governed_posts( $query ) ) {
			return true;
		}
		$context = self::current_rest_context();
		if ( 'edit' !== $context ) {
			return false;
		}
		$type = get_post_type_object( 'post' );
		return $type instanceof \WP_Post_Type
			&& current_user_can( $type->cap->edit_others_posts )
			&& current_user_can( $type->cap->edit_private_posts )
			&& current_user_can( $type->cap->edit_published_posts );
	}

	/** Whether query constraints make a tag or follow post impossible. */
	private static function query_excludes_governed_posts( \WP_Query $query ): bool {
		$post_types = $query->get( 'post_type' );
		if ( ! empty( $post_types ) && 'any' !== $post_types ) {
			$taxonomy = get_taxonomy( Taxonomy::TAXONOMY );
			$governed = $taxonomy instanceof \WP_Taxonomy ? (array) $taxonomy->object_type : [ 'post' ];
			if ( [] === array_intersect( $governed, (array) $post_types ) ) {
				return true;
			}
		}

		return false;
	}

	/** The governed-kind SQL wrapper, or an empty string when no term exists. */
	private static function kind_clause( string $alias, array $editable_ids = [] ): string {
		global $wpdb;

		$ids = self::term_taxonomy_ids();
		if ( [] === $ids ) {
			return '';
		}
		$id_list  = implode( ',', array_map( 'intval', $ids ) );
		$editable = [] === $editable_ids ? '' : " AND {$alias}.ID NOT IN ( " . implode( ',', array_map( 'intval', $editable_ids ) ) . ' )';
		return " AND NOT ( {$alias}.ID IN ( SELECT pkiw_public_tr.object_id FROM {$wpdb->term_relationships} pkiw_public_tr WHERE pkiw_public_tr.term_taxonomy_id IN ( {$id_list} ) ) AND NOT ( " . self::sql( $alias ) . " ){$editable} )";
	}

	/** Hidden governed post IDs the current caller may edit. @return int[] */
	private static function editable_hidden_ids(): array {
		global $wpdb;

		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return [];
		}
		static $cache     = [];
		$last_changed     = wp_cache_get_last_changed( 'posts' ) . ':' . wp_cache_get_last_changed( 'terms' );
		$capability_cache = $user_id . ':' . $last_changed;
		if ( isset( $cache[ $capability_cache ] ) ) {
			return $cache[ $capability_cache ];
		}
		$term_ids = self::term_taxonomy_ids();
		if ( [] === $term_ids ) {
			return [];
		}
		$term_list = implode( ',', array_map( 'intval', $term_ids ) );
		$post_ids  = $wpdb->get_col( "SELECT DISTINCT pkiw_editable.ID FROM {$wpdb->posts} pkiw_editable INNER JOIN {$wpdb->term_relationships} pkiw_editable_tr ON pkiw_editable_tr.object_id = pkiw_editable.ID WHERE pkiw_editable_tr.term_taxonomy_id IN ( {$term_list} ) AND NOT ( " . self::sql( 'pkiw_editable' ) . ' )' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Internal integer IDs and the frozen predicate build this request-local capability set.

		$cache[ $capability_cache ] = array_values(
			array_filter(
				array_map( 'intval', $post_ids ),
				static fn( int $post_id ): bool => current_user_can( 'edit_post', $post_id )
			)
		);
		return $cache[ $capability_cache ];
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

	/** Whether the current caller may edit a resolved post. */
	private static function can_edit( $post ): bool {
		$post = get_post( $post );
		return $post instanceof \WP_Post && current_user_can( 'edit_post', $post->ID );
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
	private static function purge( \WP_Post $post, ?array $kinds = null ): void {
		if ( ! function_exists( 'Pantheon_Cache' ) ) {
			return;
		}
		$kinds ??= array_values( array_filter( self::KINDS, static fn( string $kind ): bool => has_term( $kind, Taxonomy::TAXONOMY, $post ) ) );
		if ( [] === $kinds ) {
			return;
		}
		$paths = [
			'urls'  => [
				(string) get_permalink( $post ),
				home_url( '/' ),
				home_url( '/stream/' ),
				home_url( '/feed/' ),
				home_url( '/comments/feed/' ),
			],
			'regex' => [ '^/stream/page/' ],
		];
		foreach ( $kinds as $kind ) {
			$paths['urls'][]  = home_url( '/kind/' . $kind . '/' );
			$paths['urls'][]  = home_url( '/kind/' . $kind . '/feed/' );
			$paths['regex'][] = '^/kind/' . $kind . '/page/';
		}
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

	/** Resolve a governed core REST item route without assuming the post type. */
	private static function rest_item_post_id( \WP_REST_Request $request ): int {
		$route = $request->get_route();
		if ( 1 !== preg_match( '#/([0-9]+)$#', $route, $matches ) ) {
			return 0;
		}
		$post = get_post( (int) $matches[1] );
		if ( ! $post instanceof \WP_Post ) {
			return 0;
		}
		$taxonomy = get_taxonomy( Taxonomy::TAXONOMY );
		if ( ! $taxonomy instanceof \WP_Taxonomy || ! in_array( $post->post_type, (array) $taxonomy->object_type, true ) ) {
			return 0;
		}
		$type = get_post_type_object( $post->post_type );
		if ( ! $type instanceof \WP_Post_Type || ! $type->show_in_rest ) {
			return 0;
		}
		$namespace = '' !== (string) $type->rest_namespace ? (string) $type->rest_namespace : 'wp/v2';
		$base      = '' !== (string) $type->rest_base ? (string) $type->rest_base : $type->name;
		return '/' . trim( $namespace, '/' ) . '/' . trim( $base, '/' ) . '/' . $post->ID === $route ? $post->ID : 0;
	}

	/** Return the active REST context. */
	private static function current_rest_context(): string {
		$entry = end( self::$rest_contexts );
		return is_array( $entry ) ? (string) $entry['context'] : '';
	}
}
