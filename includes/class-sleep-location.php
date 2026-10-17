<?php
/**
 * Simple Location gate for sleep posts (issue 247).
 *
 * @package PKIW
 * @since   1.9.0
 */

declare(strict_types=1);

namespace PKIW;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps a sleep post's place off every surface.
 *
 * Outpost's Life composer can send a location with a sleep answer, and
 * Simple Location then stores a reverse-geocoded address and makes the post's
 * geo public. For sleep that is usually a home. A sleep post has no place on
 * any surface, so this gate removes what Simple Location prints for it: the
 * map and place line in content, GeoRSS and JSON Feed geo, the head meta
 * tags, REST latitude, longitude, address and timezone, and the post's
 * place in the map and geo endpoints and the location and venue archives.
 *
 * The gate removes only the callbacks Simple Location registered, only for a
 * sleep post's own pass, and puts back exactly what remove_filter() reported
 * removing. It defines no stand-in for Simple Location's classes.
 *
 * @since 1.9.0
 */
final class Sleep_Location {

	/**
	 * Kind slug.
	 *
	 * @var string
	 */
	public const KIND = 'sleep';

	/**
	 * Feed hooks Simple Location puts its GeoRSS item callback on.
	 *
	 * @var string[]
	 */
	private const FEED_HOOKS = [ 'rss_item', 'rss2_item', 'atom_entry', 'rdf_item' ];

	/**
	 * Date and time getters Simple Location's Loc_Timezone reformats at
	 * priority 9, in the timezone it stored for the post.
	 *
	 * @var string[]
	 */
	private const DATE_HOOKS = [ 'get_the_date', 'get_the_time', 'get_the_modified_date', 'get_the_modified_time' ];

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
	 * Wire the gate. Safe to call more than once.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'the_content', [ self::class, 'gate_content' ], 10 );
		add_filter( 'the_content', [ self::class, 'restore_content' ], 13 );
		foreach ( self::FEED_HOOKS as $hook ) {
			add_action( $hook, [ self::class, 'gate_feed_item' ], 9 );
			add_action( $hook, [ self::class, 'restore_feed_item' ], 11 );
		}
		add_filter( 'json_feed_item', [ self::class, 'strip_json_feed_geo' ], 11, 2 );
		add_action( 'template_redirect', [ self::class, 'suppress_head_meta' ], 10 );
		add_filter( 'rest_prepare_post', [ self::class, 'strip_rest_location' ], 30, 3 );
		add_action( 'pre_get_posts', [ self::class, 'exclude_from_place_listings' ] );
		foreach ( self::DATE_HOOKS as $hook ) {
			add_filter( $hook, [ self::class, 'keep_site_timezone' ], 10, 3 );
		}
	}

	/**
	 * Forget every removed callback. A request ends and does this on its own;
	 * tests and long-running CLI work call it between passes.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$content_stack = [];
		self::$feed_stack    = [];
	}

	/**
	 * Remove Simple Location's content callbacks for one sleep content pass.
	 *
	 * @internal Hooked to `the_content` at 10.
	 *
	 * @param mixed $content Content.
	 * @return mixed Content unchanged.
	 */
	public static function gate_content( $content ) {
		self::$content_stack[] = self::is_current_sleep_post()
			? array_merge(
				self::remove_simple_location_callbacks( 'the_content', 11, 'Geo_Data', 'content_map' ),
				self::remove_simple_location_callbacks( 'the_content', 12, 'Geo_Data', 'location_content' )
			)
			: [];

		return $content;
	}

	/**
	 * Put back the callbacks the matching gate_content() removed.
	 *
	 * @internal Hooked to `the_content` at 13.
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
	 * Remove the current feed hook's GeoRSS item callback for a sleep item.
	 *
	 * @internal Hooked to the feed item actions at 9.
	 *
	 * @return void
	 */
	public static function gate_feed_item(): void {
		$hook = current_filter();
		if ( '' === $hook || ! self::is_current_sleep_post() ) {
			self::$feed_stack[] = null;
			return;
		}

		$removed            = self::remove_simple_location_callbacks( $hook, 10, 'Geo_Base', 'georss_item' );
		self::$feed_stack[] = $removed[0] ?? null;
	}

	/**
	 * Put back the GeoRSS item callback the matching gate_feed_item() removed.
	 *
	 * @internal Hooked to the feed item actions at 11.
	 *
	 * @return void
	 */
	public static function restore_feed_item(): void {
		$entry = array_pop( self::$feed_stack );
		if ( ! is_array( $entry ) ) {
			return;
		}

		add_action( current_filter(), $entry['callable'], $entry['priority'], $entry['accepted_args'] );
	}

	/**
	 * Remove geo from JSON Feed items for sleep posts.
	 *
	 * @internal Hooked to `json_feed_item` at 11.
	 *
	 * @param mixed $item Feed item.
	 * @param mixed $post Post, or null.
	 * @return mixed
	 */
	public static function strip_json_feed_geo( $item, $post = null ) {
		$post = $post instanceof \WP_Post ? $post : get_post();
		if ( is_array( $item ) && $post instanceof \WP_Post && self::is_sleep_post( $post ) ) {
			unset( $item['geo'] );
		}

		return $item;
	}

	/**
	 * Remove Simple Location's head meta tags on a sleep single.
	 *
	 * @internal Hooked to `template_redirect`.
	 *
	 * @return void
	 */
	public static function suppress_head_meta(): void {
		$post = get_queried_object();
		if ( ! is_singular() || ! $post instanceof \WP_Post || ! self::is_sleep_post( $post ) ) {
			return;
		}

		self::remove_simple_location_callbacks( 'wp_head', 10, 'Geo_Base', 'meta_tags' );
	}

	/**
	 * Remove Simple Location's fields from a sleep post's public REST response.
	 *
	 * Latitude, longitude, address and timezone go. So do every `geo_` key in
	 * `meta` and IndieBlocks' whole `indieblocks_location` field, which carry
	 * the same place under other names. A UTC offset on the date or modified
	 * time would point to a place too, so both print as the stored local
	 * time. The edit context, which only a user who can edit the post
	 * reaches, is left alone.
	 *
	 * @internal Hooked to `rest_prepare_post` at 30.
	 *
	 * @param mixed $response Response.
	 * @param mixed $post     Post.
	 * @param mixed $request  Request.
	 * @return mixed
	 */
	public static function strip_rest_location( $response, $post, $request ) {
		if ( ! $response instanceof \WP_REST_Response || ! $post instanceof \WP_Post || ! $request instanceof \WP_REST_Request ) {
			return $response;
		}
		if ( 'edit' === $request->get_param( 'context' ) || ! self::is_sleep_post( $post ) ) {
			return $response;
		}

		$data = $response->get_data();
		foreach ( [ 'latitude', 'longitude', 'geo_address', 'timezone', 'indieblocks_location' ] as $key ) {
			unset( $data[ $key ] );
		}
		if ( isset( $data['meta'] ) && is_array( $data['meta'] ) ) {
			foreach ( array_keys( $data['meta'] ) as $key ) {
				if ( str_contains( (string) $key, 'geo_' ) ) {
					unset( $data['meta'][ $key ] );
				}
			}
		}
		$date_columns = [
			'date'     => 'post_date',
			'modified' => 'post_modified',
		];
		foreach ( $date_columns as $key => $column ) {
			if ( isset( $data[ $key ] ) ) {
				$data[ $key ] = mysql_to_rfc3339( $post->$column );
			}
		}
		$response->set_data( $data );

		return $response;
	}

	/**
	 * Print a sleep post's dates and times in the site timezone.
	 *
	 * Simple Location stores a timezone for a post that arrives with a
	 * location and reformats the date and time getters in it, at priority 9.
	 * For a sleep post that would move the noon date (the single's hidden
	 * dt-published, the Stream's date) to another day and show where the
	 * night was spent. This runs after it, at priority 10, and asks core for
	 * the post's own date fields in the site timezone, in the requested
	 * format, so 'U' and 'G' answer as core answers. Other kinds are left
	 * alone. It applies on admin screens too.
	 *
	 * @internal Hooked to `get_the_date`, `get_the_time`, `get_the_modified_date` and `get_the_modified_time` at 10.
	 *
	 * @param mixed $value  Formatted date or time.
	 * @param mixed $format Requested format, or '' for the site's.
	 * @param mixed $post   Post, ID or null for the current post.
	 * @return mixed
	 */
	public static function keep_site_timezone( $value, $format = '', $post = null ) {
		$post = get_post( is_object( $post ) || is_int( $post ) ? $post : null );
		if ( ! $post instanceof \WP_Post || ! self::is_sleep_post( $post ) ) {
			return $value;
		}

		$hook     = current_filter();
		$option   = str_ends_with( $hook, '_time' ) ? 'time_format' : 'date_format';
		$format   = is_string( $format ) && '' !== $format ? $format : (string) get_option( $option );
		$modified = str_contains( $hook, 'modified' );

		// Core's own functions, as get_the_date() and friends call them. They read the
		// post's date fields in the site timezone, which Simple Location hooks neither
		// of, and they answer 'U' and 'G' with an int as core does.
		$answer = $modified ? get_post_modified_time( $format, false, $post, true ) : get_post_time( $format, false, $post, true );

		return false === $answer ? $value : $answer;
	}

	/**
	 * Keep sleep posts out of the places listings.
	 *
	 * A main query on Simple Location's map or geo endpoint, on a location
	 * term or on a venue term drops every sleep post.
	 *
	 * @internal Hooked to `pre_get_posts`.
	 *
	 * @param mixed $query The query.
	 * @return void
	 */
	public static function exclude_from_place_listings( $query ): void {
		if ( ! $query instanceof \WP_Query || ! $query->is_main_query() || is_admin() ) {
			return;
		}

		$place = ! empty( $query->get( 'map' ) )
			|| ! empty( $query->get( 'geo' ) )
			|| $query->is_tax( 'location' )
			|| $query->is_tax( Venue_Taxonomy::TAXONOMY );
		if ( ! $place ) {
			return;
		}

		$exclude   = [
			'taxonomy' => Taxonomy::TAXONOMY,
			'field'    => 'slug',
			'terms'    => [ self::KIND ],
			'operator' => 'NOT IN',
		];
		$tax_query = $query->get( 'tax_query' );
		if ( is_array( $tax_query ) && [] !== $tax_query ) {
			$query->set(
				'tax_query',
				[
					'relation' => 'AND',
					$tax_query,
					$exclude,
				]
			);
			return;
		}

		$query->set( 'tax_query', [ $exclude ] );
	}

	/**
	 * Whether the current global post is a sleep post.
	 *
	 * @return bool
	 */
	private static function is_current_sleep_post(): bool {
		$post = get_post();

		return $post instanceof \WP_Post && self::is_sleep_post( $post );
	}

	/**
	 * Whether a post carries the sleep kind term, first or not: a post that
	 * holds it is gated, so the gate fails closed.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	private static function is_sleep_post( \WP_Post $post ): bool {
		return has_term( self::KIND, Taxonomy::TAXONOMY, $post );
	}

	/**
	 * Remove matching Simple Location callbacks from a hook and priority.
	 *
	 * @param string $hook       Hook name.
	 * @param int    $priority   Priority.
	 * @param string $class_name Class name.
	 * @param string $method     Method name.
	 * @return array<int, array{hook:string, callable:mixed, priority:int, accepted_args:int}> What remove_filter() reported removing.
	 */
	private static function remove_simple_location_callbacks( string $hook, int $priority, string $class_name, string $method ): array {
		$wp_hook = $GLOBALS['wp_filter'][ $hook ] ?? null;
		if ( ! $wp_hook instanceof \WP_Hook || empty( $wp_hook->callbacks[ $priority ] ) ) {
			return [];
		}

		$removed = [];
		foreach ( $wp_hook->callbacks[ $priority ] as $callback ) {
			$callable = $callback['function'] ?? null;
			if ( ! self::is_simple_location_callback( $callable, $class_name, $method ) ) {
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
	 * Whether a callable is a given Simple Location class and method, in
	 * string form (`Geo_Data::content_map`) or array form, with or without a
	 * leading backslash.
	 *
	 * @param mixed  $candidate  Callable candidate.
	 * @param string $class_name Class name.
	 * @param string $method     Method name.
	 * @return bool
	 */
	private static function is_simple_location_callback( $candidate, string $class_name, string $method ): bool {
		$class_name = ltrim( $class_name, '\\' );
		if ( is_string( $candidate ) ) {
			return ltrim( $candidate, '\\' ) === $class_name . '::' . $method;
		}
		if ( ! is_array( $candidate ) || 2 !== count( $candidate ) || $method !== (string) $candidate[1] ) {
			return false;
		}
		$candidate_class = is_object( $candidate[0] ) ? get_class( $candidate[0] ) : (string) $candidate[0];
		$candidate_class = ltrim( $candidate_class, '\\' );

		return $class_name === $candidate_class;
	}
}
