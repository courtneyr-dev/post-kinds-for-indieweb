<?php
/**
 * Wrapped post metadata, routing, and lookup.
 *
 * @package PKIW
 */

declare(strict_types=1);
namespace PKIW;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Own the Wrapped post lifecycle and canonical locator. */
final class Wrapped_Post {
	// Public callback signatures document their input types.
	// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag

	/** Wrapped kind meta key. */
	public const KIND_META = '_pkiw_wrapped_kind';
	/** Wrapped year meta key. */
	public const YEAR_META = '_pkiw_wrapped_year';
	/**
	 * Kind-removal recursion guard.
	 *
	 * @var bool
	 */
	private static bool $enforcing = false;

	/** Wire post metadata, save sync, surface routing, and alias handling. */
	public function register(): void {
		add_action( 'init', [ self::class, 'register_meta' ] );
		add_action( 'init', [ self::class, 'register_route' ] );
		add_action( 'save_post', [ self::class, 'sync_on_save' ], 10, 3 );
		add_action( 'rest_after_insert_post', [ self::class, 'enforce_after_rest_insert' ], 99, 3 );
		add_action( 'wp_after_insert_post', [ self::class, 'enforce_after_wp_insert' ], 99, 4 );
		add_action( 'set_object_terms', [ self::class, 'enforce_after_terms' ], 99, 6 );
		add_filter( 'pkiw_post_surface', [ self::class, 'surface' ], 20, 2 );
		add_filter( 'query_vars', [ self::class, 'query_vars' ] );
		add_action( 'parse_request', [ self::class, 'serve_alias' ] );
	}

	/** Register private post meta. */
	public static function register_meta(): void {
		foreach ( [
			self::KIND_META => 'string',
			self::YEAR_META => 'integer',
		] as $key => $type ) {
			register_post_meta(
				'post',
				$key,
				[
					'type'          => $type,
					'single'        => true,
					'show_in_rest'  => false,
					'auth_callback' => '__return_false',
				]
			);
		}
	}

	/** Synchronize meta from the first top-level or nested Wrapped block. */
	public static function sync_on_save( int $id, \WP_Post $post, bool $update ): void {
		if ( 'post' !== $post->post_type || wp_is_post_revision( $id ) || wp_is_post_autosave( $id ) ) {
			return;
		}
		$had   = '' !== (string) get_post_meta( $id, self::KIND_META, true );
		$block = self::find_block( parse_blocks( $post->post_content ) );
		$kind  = sanitize_key( (string) ( $block['attrs']['kind'] ?? '' ) );
		$year  = $block && isset( $block['attrs']['year'] ) && is_int( $block['attrs']['year'] ) ? $block['attrs']['year'] : 0;
		if ( $block && $year >= 2000 && $year <= (int) wp_date( 'Y' ) && term_exists( $kind, 'kind' ) ) {
			update_post_meta( $id, self::KIND_META, $kind );
			update_post_meta( $id, self::YEAR_META, $year );
			self::enforce_no_kind( $id );
			return;
		}
		delete_post_meta( $id, self::KIND_META );
		delete_post_meta( $id, self::YEAR_META );
		if ( $had && ! wp_get_object_terms( $id, 'kind', [ 'fields' => 'ids' ] ) ) {
			wp_set_object_terms( $id, (int) get_option( 'default_term_kind' ), 'kind' );
		}
	}

	/** Enforce the no-kind invariant after REST insert work. */
	public static function enforce_after_rest_insert( \WP_Post $post, \WP_REST_Request $request, bool $creating ): void {
		if ( '' !== (string) get_post_meta( $post->ID, self::KIND_META, true ) ) {
			self::enforce_no_kind( $post->ID );
		}
	}

	/** Enforce the no-kind invariant after core insert work. */
	public static function enforce_after_wp_insert( int $post_id, \WP_Post $post, bool $update, ?\WP_Post $post_before ): void {
		if ( '' !== (string) get_post_meta( $post_id, self::KIND_META, true ) ) {
			self::enforce_no_kind( $post_id );
		}
	}

	/** Re-clear kind terms assigned after sync. */
	public static function enforce_after_terms( int $id, $terms, array $tt_ids, string $taxonomy, bool $append, array $old_tt_ids ): void {
		if ( 'kind' === $taxonomy && ! self::$enforcing && '' !== (string) get_post_meta( $id, self::KIND_META, true ) ) {
			self::enforce_no_kind( $id );
		}
	}

	/** Remove every kind relationship without recursion. */
	public static function enforce_no_kind( int $id ): void {
		self::$enforcing = true;
		wp_set_object_terms( $id, [], 'kind' );
		self::$enforcing = false;
	}

	/** Force ordinary Wrapped posts to the Stream unless promoted. */
	public static function surface( string $surface, \WP_Post $post ): string {
		return '' !== (string) get_post_meta( $post->ID, self::KIND_META, true ) && ! get_post_meta( $post->ID, 'pkiw_promote', true ) ? Post_Surface::STREAM : $surface;
	}

	/** Locate the newest listable Wrapped post for a kind and optional year. */
	public static function latest( string $kind, ?int $year = null ): ?\WP_Post {
		$gen = (string) get_option( 'pkiw_wrapped_gen', '' );
		$key = 'pkiw_wrapped_latest_' . substr( md5( $kind ), 0, 12 ) . '_' . ( $year ?? 'all' ) . '_' . $gen;
		if ( '' !== $gen ) {
			$cached = get_transient( $key );
			// The options table hands an int transient back as a numeric string.
			if ( is_int( $cached ) || ( is_string( $cached ) && ctype_digit( $cached ) ) ) {
				$cached = (int) $cached;
				if ( 0 === $cached || Public_Listing::is_listable( $cached ) ) {
					return 0 === $cached ? null : get_post( $cached );
				}
			}
		}
		$meta = [
			[
				'key'   => self::KIND_META,
				'value' => sanitize_key( $kind ),
			],
		];
		if ( null !== $year ) {
			$meta[] = [
				'key'   => self::YEAR_META,
				'value' => $year,
				'type'  => 'NUMERIC',
			];
		}
		$query = new \WP_Query(
			Public_Listing::query_args(
				[
					'posts_per_page' => 1,
					'no_found_rows'  => true,
					'meta_query'     => $meta,
					'meta_key'       => self::YEAR_META,
					'orderby'        => [
						'meta_value_num' => 'DESC',
						'date'           => 'DESC',
						'ID'             => 'DESC',
					],
				]
			)
		);
		$post  = $query->posts[0] ?? null;
		if ( ! $post instanceof \WP_Post || ! Public_Listing::is_listable( $post ) ) {
			$post = null;
		}
		if ( '' !== $gen ) {
			set_transient( $key, $post ? $post->ID : 0, DAY_IN_SECONDS );
		}

		return $post;
	}

	/** Register the stable alias rewrite. */
	public static function register_route(): void {
		$taxonomy = get_taxonomy( 'kind' );
		$base     = $taxonomy && is_array( $taxonomy->rewrite ) ? (string) $taxonomy->rewrite['slug'] : 'kind';
		add_rewrite_rule( '^' . preg_quote( $base, '#' ) . '/([^/]+)/([0-9]{4})/wrapped/?$', 'index.php?pkiw_wrapped_kind=$matches[1]&pkiw_wrapped_year=$matches[2]', 'top' );
	}

	/** Add alias query variables. */
	public static function query_vars( $vars ) {
		if ( ! is_array( $vars ) ) {
			return $vars;
		}
		$vars[] = 'pkiw_wrapped_kind';
		$vars[] = 'pkiw_wrapped_year';

		return $vars;
	}

	/** Resolve an alias to a 301 or a 404. */
	public static function serve_alias( $wp ): void {
		// Untyped: a plugin or test can fire parse_request with something other than WP.
		if ( ! $wp instanceof \WP || ! isset( $wp->query_vars['pkiw_wrapped_kind'], $wp->query_vars['pkiw_wrapped_year'] ) ) {
			return;
		}
		$kind = sanitize_key( (string) $wp->query_vars['pkiw_wrapped_kind'] );
		$year = (int) $wp->query_vars['pkiw_wrapped_year'];
		$post = term_exists( $kind, 'kind' ) ? self::latest( $kind, $year ) : null;
		if ( ! $post ) {
			$wp->query_vars = [ 'error' => '404' ];
			nocache_headers();
			return;
		}
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex' );
		}
		wp_safe_redirect( get_permalink( $post ), 301 );
		exit;
	}

	/** Recursively find the first actual Wrapped block. */
	public static function find_block( array $blocks ): ?array {
		foreach ( $blocks as $block ) {
			if ( 'post-kinds-indieweb/wrapped' === ( $block['blockName'] ?? '' ) ) {
				return $block;
			}
			$found = self::find_block( $block['innerBlocks'] ?? [] );
			if ( $found ) {
				return $found;
			}
		}

		return null;
	}
}
