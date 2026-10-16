<?php
/**
 * Simple Location gate for exercise posts.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

namespace PKIW;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps Simple Location's output off exercise posts (issue 245).
 *
 * Outpost's Exercise variant sends a geo pin with no location visibility,
 * and Simple Location's own after_micropub hook then makes that post's
 * visibility public, stores the reverse-geocoded address and assigns a
 * `location` term. Simple Location then prints a map and that address,
 * GeoRSS, JSON Feed geo, geo meta tags and REST coordinates, and lists the
 * post on its map and geo endpoints and location archives, all under its own
 * visibility, which is looser than Post Kinds' approximate default.
 *
 * An exercise post allows a place on its single only, through
 * Kind_Facts at pkiw_get_visible_location_fields()'s tier, so every Simple
 * Location output is held back for an exercise post, whatever its own
 * visibility says (the signed shape, W3-G3):
 *
 * - the_content: a gate at priority 10 removes `Geo_Data::content_map` (11)
 *   and `Geo_Data::location_content` (12) and keeps the callables
 *   remove_filter() really removed on a per-pass stack; a restore at 13 pops
 *   the stack and re-adds each at its own priority, so a nested pass (another
 *   post rendered inside this one) can't clear the outer pass's list.
 * - feeds: `Geo_Base::georss_item` comes off rss_item, rss2_item, atom_entry
 *   and rdf_item at 9 and returns at 11, only when it was removed; the JSON
 *   Feed item loses its `geo`.
 * - head: `Geo_Base::meta_tags` comes off wp_head on an exercise single.
 * - REST: latitude, longitude and geo_address leave a post response outside
 *   the edit context. `timezone` stays: it is coarser than the approximate
 *   tier's locality.
 * - listings: the main query of Simple Location's map and geo endpoints, of
 *   its `location` archive and of a `pkiw_venue` archive leaves exercise
 *   posts out.
 *
 * No code in this class assigns a `location` or `pkiw_venue` term, and none
 * registers a callable it didn't remove. With Simple Location absent every
 * remove_filter() returns false and nothing is added.
 *
 * @since 1.9.0
 */
final class Exercise_Location {

	/**
	 * Removed content callbacks, one frame per the_content pass.
	 *
	 * @var array<int, array<int, array{hook:string, callable:mixed, priority:int, accepted_args:int}>>
	 */
	private static array $content_stack = [];

	/**
	 * Removed feed callbacks, one frame per feed item pass.
	 *
	 * @var array<int, array{hook:string, callable:mixed, priority:int, accepted_args:int}|null>
	 */
	private static array $feed_stack = [];

	/**
	 * Wire the gate. Every callback is static, so a second call adds nothing.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'the_content', [ self::class, 'gate_content' ], 10 );
		add_filter( 'the_content', [ self::class, 'restore_content' ], 13 );
		foreach ( [ 'rss_item', 'rss2_item', 'atom_entry', 'rdf_item' ] as $hook ) {
			add_action( $hook, [ self::class, 'gate_feed_item' ], 9 );
			add_action( $hook, [ self::class, 'restore_feed_item' ], 11 );
		}
		add_filter( 'json_feed_item', [ self::class, 'strip_json_feed_geo' ], 11, 2 );
		add_action( 'template_redirect', [ self::class, 'suppress_head_meta' ], 10 );
		add_filter( 'rest_prepare_post', [ self::class, 'strip_rest_location' ], 30, 3 );
		add_action( 'pre_get_posts', [ self::class, 'exclude_from_place_listings' ] );
	}

	/**
	 * Clear the render-pass stacks.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$content_stack = [];
		self::$feed_stack    = [];
	}

	/**
	 * Remove Simple Location's content callbacks for one exercise content pass.
	 *
	 * @param mixed $content Content.
	 * @return mixed Content unchanged.
	 */
	public static function gate_content( $content ) {
		self::$content_stack[] = self::is_exercise( get_post() )
			? array_merge(
				self::remove( 'the_content', 11, 'Geo_Data', 'content_map' ),
				self::remove( 'the_content', 12, 'Geo_Data', 'location_content' )
			)
			: [];

		return $content;
	}

	/**
	 * Re-add what gate_content() removed in this pass, at its own priority.
	 *
	 * @param mixed $content Content.
	 * @return mixed Content unchanged.
	 */
	public static function restore_content( $content ) {
		$removed = array_pop( self::$content_stack );
		foreach ( is_array( $removed ) ? $removed : [] as $entry ) {
			add_filter( $entry['hook'], $entry['callable'], $entry['priority'], $entry['accepted_args'] );
		}

		return $content;
	}

	/**
	 * Remove the current feed hook's GeoRSS callback for an exercise item.
	 *
	 * @return void
	 */
	public static function gate_feed_item(): void {
		$hook = current_filter();
		if ( '' === $hook || ! self::is_exercise( get_post() ) ) {
			self::$feed_stack[] = null;
			return;
		}

		$removed            = self::remove( $hook, 10, 'Geo_Base', 'georss_item' );
		self::$feed_stack[] = $removed[0] ?? null;
	}

	/**
	 * Re-add the GeoRSS callback when gate_feed_item() removed it for this item.
	 *
	 * @return void
	 */
	public static function restore_feed_item(): void {
		$entry = array_pop( self::$feed_stack );
		if ( ! is_array( $entry ) ) {
			return;
		}

		add_action( $entry['hook'], $entry['callable'], $entry['priority'], $entry['accepted_args'] );
	}

	/**
	 * Drop `geo` from the JSON Feed item of an exercise post.
	 *
	 * @param mixed $item JSON Feed item.
	 * @param mixed $post Feed post, or null.
	 * @return mixed Item.
	 */
	public static function strip_json_feed_geo( $item, $post = null ) {
		$post = $post instanceof \WP_Post ? $post : get_post();
		if ( is_array( $item ) && self::is_exercise( $post ) ) {
			unset( $item['geo'] );
		}

		return $item;
	}

	/**
	 * Remove Simple Location's head meta tags on an exercise single.
	 *
	 * One head prints per request, so there's no restore.
	 *
	 * @return void
	 */
	public static function suppress_head_meta(): void {
		$post = get_queried_object();
		if ( ! is_singular() || ! self::is_exercise( $post ) ) {
			return;
		}

		self::remove( 'wp_head', 10, 'Geo_Base', 'meta_tags' );
	}

	/**
	 * Take latitude, longitude and the address out of an exercise post's REST response.
	 *
	 * The edit context is the author's own and keeps them; every other
	 * context, for any requester, loses them.
	 *
	 * @param mixed $response Response.
	 * @param mixed $post     Post.
	 * @param mixed $request  Request.
	 * @return mixed Response.
	 */
	public static function strip_rest_location( $response, $post, $request ) {
		if ( ! $response instanceof \WP_REST_Response || ! $post instanceof \WP_Post || ! $request instanceof \WP_REST_Request ) {
			return $response;
		}
		if ( 'edit' === $request->get_param( 'context' ) || ! self::is_exercise( $post ) ) {
			return $response;
		}

		$data = $response->get_data();
		if ( is_array( $data ) ) {
			foreach ( [ 'latitude', 'longitude', 'geo_address' ] as $key ) {
				unset( $data[ $key ] );
			}
			$response->set_data( $data );
		}

		return $response;
	}

	/**
	 * Leave exercise posts out of the place listings.
	 *
	 * Simple Location's map and geo endpoints list posts by its own
	 * visibility, and its `location` archive and the `pkiw_venue` archive
	 * list every post that holds the term, so without this a place archive
	 * would list a post whose place is private.
	 *
	 * @param mixed $query Query.
	 * @return void
	 */
	public static function exclude_from_place_listings( $query ): void {
		if ( ! $query instanceof \WP_Query || ! $query->is_main_query() ) {
			return;
		}

		$endpoint = '' !== (string) $query->get( 'map' ) || '' !== (string) $query->get( 'geo' );
		if ( ! $endpoint && ! $query->is_tax( 'location' ) && ! $query->is_tax( Venue_Taxonomy::TAXONOMY ) ) {
			return;
		}

		$tax_query   = (array) $query->get( 'tax_query' );
		$tax_query[] = [
			'taxonomy' => Taxonomy::TAXONOMY,
			'field'    => 'slug',
			'terms'    => [ Exercise_Archive::KIND ],
			'operator' => 'NOT IN',
		];
		$query->set( 'tax_query', $tax_query ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Keeps one kind off a place listing.
	}

	/**
	 * Whether a post is an exercise post: it holds the exercise kind term.
	 *
	 * @param mixed $post Post.
	 * @return bool
	 */
	private static function is_exercise( $post ): bool {
		return $post instanceof \WP_Post && has_term( Exercise_Archive::KIND, Taxonomy::TAXONOMY, $post );
	}

	/**
	 * Remove the callbacks of a Simple Location class and method from a hook and priority.
	 *
	 * Returns only what remove_filter() really removed, so a restore re-adds
	 * nothing it didn't take away.
	 *
	 * @param string $hook       Hook name.
	 * @param int    $priority   Priority.
	 * @param string $class_name Class name.
	 * @param string $method     Method name.
	 * @return array<int, array{hook:string, callable:mixed, priority:int, accepted_args:int}>
	 */
	private static function remove( string $hook, int $priority, string $class_name, string $method ): array {
		$wp_hook = $GLOBALS['wp_filter'][ $hook ] ?? null;
		if ( ! $wp_hook instanceof \WP_Hook || empty( $wp_hook->callbacks[ $priority ] ) ) {
			return [];
		}

		$removed = [];
		foreach ( $wp_hook->callbacks[ $priority ] as $callback ) {
			$callable = $callback['function'] ?? null;
			if ( ! self::is_callback_of( $callable, $class_name, $method ) ) {
				continue;
			}
			if ( remove_filter( $hook, $callable, $priority ) ) {
				$removed[] = [
					'hook'          => $hook,
					'callable'      => $callable,
					'priority'      => $priority,
					'accepted_args' => (int) ( $callback['accepted_args'] ?? 1 ),
				];
			}
		}

		return $removed;
	}

	/**
	 * Whether a registered callable is the named class's method, in any of its spellings.
	 *
	 * @param mixed  $candidate  Callable as registered.
	 * @param string $class_name Class name.
	 * @param string $method     Method name.
	 * @return bool
	 */
	private static function is_callback_of( $candidate, string $class_name, string $method ): bool {
		$class_name = ltrim( $class_name, '\\' );
		if ( is_string( $candidate ) ) {
			return ltrim( $candidate, '\\' ) === $class_name . '::' . $method;
		}
		if ( ! is_array( $candidate ) || 2 !== count( $candidate ) || $method !== (string) $candidate[1] ) {
			return false;
		}
		$candidate_class = is_object( $candidate[0] ) ? get_class( $candidate[0] ) : (string) $candidate[0];

		return ltrim( $candidate_class, '\\' ) === $class_name;
	}
}
