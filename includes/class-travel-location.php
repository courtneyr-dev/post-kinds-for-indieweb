<?php
/**
 * Simple Location gate for trip and itinerary posts.
 *
 * @package PKIW
 * @since   1.9.0
 */

declare(strict_types=1);

namespace PKIW;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keeps the post's posting location off travel surfaces. */
final class Travel_Location {

	public const KINDS = [ Travel::TRIP, Travel::ITINERARY ];

	/** Simple Location feed hooks. @var string[] */
	private const FEED_HOOKS = [ 'rss_item', 'rss2_item', 'atom_entry', 'rdf_item' ];

	/** Date filters that Simple Location can offset. @var string[] */
	private const DATE_HOOKS = [ 'get_the_date', 'get_the_time', 'get_the_modified_date', 'get_the_modified_time' ];

	/**
	 * Removed content callbacks by render depth.
	 *
	 * @var array<int, array<int, array{hook:string,callable:mixed,priority:int,accepted_args:int}>>
	 */
	private static array $content_stack = [];

	/**
	 * Removed feed callbacks by item depth.
	 *
	 * @var array<int, array{hook:string,callable:mixed,priority:int,accepted_args:int}|null>
	 */
	private static array $feed_stack = [];

	/** Wire every Simple Location gate. */
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

	/** Clear per-pass callback stacks. */
	public static function reset(): void {
		self::$content_stack = [];
		self::$feed_stack    = [];
	}

	/**
	 * Remove Simple Location's content callbacks for one travel pass.
	 *
	 * @param mixed $content Filtered content.
	 */
	public static function gate_content( $content ) {
		self::$content_stack[] = self::is_current_travel_post()
			? array_merge(
				self::remove_callbacks( 'the_content', 11, 'Geo_Data', 'content_map' ),
				self::remove_callbacks( 'the_content', 12, 'Geo_Data', 'location_content' )
			)
			: [];
		return $content;
	}

	/**
	 * Restore exactly the content callbacks the matching pass removed.
	 *
	 * @param mixed $content Filtered content.
	 */
	public static function restore_content( $content ) {
		$removed = array_pop( self::$content_stack );
		foreach ( is_array( $removed ) ? $removed : [] as $entry ) {
			add_filter( $entry['hook'], $entry['callable'], $entry['priority'], $entry['accepted_args'] );
		}
		return $content;
	}

	/** Remove GeoRSS for the current travel feed item. */
	public static function gate_feed_item(): void {
		$hook = current_filter();
		if ( '' === $hook || ! self::is_current_travel_post() ) {
			self::$feed_stack[] = null;
			return;
		}
		$removed            = self::remove_callbacks( $hook, 10, 'Geo_Base', 'georss_item' );
		self::$feed_stack[] = $removed[0] ?? null;
	}

	/** Restore the GeoRSS callback removed for the matching feed item. */
	public static function restore_feed_item(): void {
		$entry = array_pop( self::$feed_stack );
		if ( is_array( $entry ) ) {
			add_action( current_filter(), $entry['callable'], $entry['priority'], $entry['accepted_args'] );
		}
	}

	/**
	 * Strip JSON Feed geo from travel posts.
	 *
	 * @param mixed $item JSON Feed item.
	 * @param mixed $post Post object.
	 */
	public static function strip_json_feed_geo( $item, $post = null ) {
		$post = $post instanceof \WP_Post ? $post : get_post();
		if ( is_array( $item ) && $post instanceof \WP_Post && self::is_travel_post( $post ) ) {
			unset( $item['geo'] );
		}
		return $item;
	}

	/** Suppress Simple Location head metadata on travel singles. */
	public static function suppress_head_meta(): void {
		$post = get_queried_object();
		if ( is_singular() && $post instanceof \WP_Post && self::is_travel_post( $post ) ) {
			self::remove_callbacks( 'wp_head', 10, 'Geo_Base', 'meta_tags' );
		}
	}

	/**
	 * Strip Simple Location's fields from public REST responses.
	 *
	 * @param mixed $response REST response.
	 * @param mixed $post     Post object.
	 * @param mixed $request  REST request.
	 */
	public static function strip_rest_location( $response, $post, $request ) {
		if ( ! $response instanceof \WP_REST_Response || ! $post instanceof \WP_Post || ! $request instanceof \WP_REST_Request ) {
			return $response;
		}
		if ( 'edit' === $request->get_param( 'context' ) || ! self::is_travel_post( $post ) ) {
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
		foreach ( [
			'date'     => 'post_date',
			'modified' => 'post_modified',
		] as $key => $column ) {
			if ( isset( $data[ $key ] ) ) {
				$data[ $key ] = mysql_to_rfc3339( $post->$column );
			}
		}
		$response->set_data( $data );
		return $response;
	}

	/**
	 * Keep travel dates in the site timezone, not the posting place.
	 *
	 * @param mixed  $value  Filtered date value.
	 * @param string $format Requested format.
	 * @param mixed  $post   Post object or ID.
	 */
	public static function keep_site_timezone( $value, $format = '', $post = null ) {
		$post = get_post( is_object( $post ) || is_int( $post ) ? $post : null );
		if ( ! $post instanceof \WP_Post || ! self::is_travel_post( $post ) ) {
			return $value;
		}
		$hook     = current_filter();
		$option   = str_ends_with( $hook, '_time' ) ? 'time_format' : 'date_format';
		$format   = is_string( $format ) && '' !== $format ? $format : (string) get_option( $option );
		$modified = str_contains( $hook, 'modified' );
		$answer   = $modified ? get_post_modified_time( $format, false, $post, true ) : get_post_time( $format, false, $post, true );
		return false === $answer ? $value : $answer;
	}

	/**
	 * Exclude both travel kinds from Simple Location place listings.
	 *
	 * @param mixed $query Main query.
	 */
	public static function exclude_from_place_listings( $query ): void {
		if ( ! $query instanceof \WP_Query || ! $query->is_main_query() || is_admin() ) {
			return;
		}
		$place = ! empty( $query->get( 'map' ) ) || ! empty( $query->get( 'geo' ) ) || $query->is_tax( 'location' ) || $query->is_tax( Venue_Taxonomy::TAXONOMY );
		if ( ! $place ) {
			return;
		}
		$exclude  = [
			'taxonomy' => Taxonomy::TAXONOMY,
			'field'    => 'slug',
			'terms'    => self::KINDS,
			'operator' => 'NOT IN',
		];
		$existing = $query->get( 'tax_query' );
		$query->set(
			'tax_query',
			is_array( $existing ) && [] !== $existing ? [
				'relation' => 'AND',
				$existing,
				$exclude,
			] : [ $exclude ]
		);
	}

	/** Whether the current post is a travel kind. */
	private static function is_current_travel_post(): bool {
		$post = get_post();
		return $post instanceof \WP_Post && self::is_travel_post( $post );
	}

	/**
	 * Whether a post has either travel kind.
	 *
	 * @param \WP_Post $post Post object.
	 */
	private static function is_travel_post( \WP_Post $post ): bool {
		return has_term( self::KINDS, Taxonomy::TAXONOMY, $post );
	}

	/**
	 * Remove callbacks matching one Simple Location method.
	 *
	 * @param string $hook       Hook name.
	 * @param int    $priority   Hook priority.
	 * @param string $class_name Callback class.
	 * @param string $method     Callback method.
	 * @return array<int, array{hook:string,callable:mixed,priority:int,accepted_args:int}>
	 */
	private static function remove_callbacks( string $hook, int $priority, string $class_name, string $method ): array {
		$wp_hook = $GLOBALS['wp_filter'][ $hook ] ?? null;
		if ( ! $wp_hook instanceof \WP_Hook || empty( $wp_hook->callbacks[ $priority ] ) ) {
			return [];
		}
		$removed = [];
		foreach ( $wp_hook->callbacks[ $priority ] as $callback ) {
			$callable = $callback['function'] ?? null;
			if ( ! self::matches_callback( $callable, $class_name, $method ) || ! remove_filter( $hook, $callable, $priority ) ) {
				continue;
			}
			$removed[] = [
				'hook'          => $hook,
				'callable'      => $callable,
				'priority'      => $priority,
				'accepted_args' => (int) ( $callback['accepted_args'] ?? 1 ),
			];
		}
		return $removed;
	}

	/**
	 * Whether a callback matches a class method.
	 *
	 * @param mixed  $candidate  Registered callback.
	 * @param string $class_name Expected class.
	 * @param string $method     Expected method.
	 */
	private static function matches_callback( mixed $candidate, string $class_name, string $method ): bool {
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
