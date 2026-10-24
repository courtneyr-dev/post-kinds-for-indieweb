<?php
/**
 * Micropub q=source shows a token what a visitor sees (issue 351).
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
 * Filters the Micropub plugin's q=source answer for a user who can't edit
 * the post.
 *
 * The Micropub plugin checks only that a token is valid before it answers
 * q=source (2.5.3, trait-micropub.php:59), then hands back the post's raw
 * post_content and every `mf2_*` meta row the creating request stored
 * (functions.php:319-360). For a token whose user can't edit the post this
 * filter:
 *
 * - refuses a post a visitor can't read: not published, or password-protected;
 * - takes hidden card values out of the content, as search does: private
 *   acquisition cost, hidden exercise and sleep details, hidden card
 *   locations (Meta_Fields::strip_private_cost(), strip_private_health(),
 *   strip_private_location());
 * - drops the stored location properties unless the post's location is
 *   fully public, and the stored sleep and exercise detail properties
 *   unless the post shows them;
 * - gives a title generated from a hidden location as
 *   Title_Privacy::safe_title().
 *
 * Users who can edit the post keep the full source.
 */
final class Micropub_Source_Privacy {

	/**
	 * Stored mf2 properties that carry a location.
	 *
	 * @var string[]
	 */
	private const LOCATION_PROPERTIES = [ 'location', 'checkin', 'latitude', 'longitude', 'altitude', 'geo', 'street-address', 'locality', 'region', 'country-name', 'postal-code' ];

	/**
	 * Stored mf2 properties that carry opt-in health details, by kind. A
	 * sleep post's duration is public (Meta_Fields::HEALTH_FIELDS), so it stays.
	 *
	 * @var array<string, string[]>
	 */
	private const HEALTH_PROPERTIES = [
		'exercise' => [ 'start', 'end', 'duration', 'distance', 'heart-rate', 'splits' ],
		'sleep'    => [ 'start', 'end', 'heart-rate' ],
	];

	/**
	 * Wire the filter. Safe to call more than once.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'micropub_query', [ self::class, 'filter_query' ], 20, 2 );
	}

	/**
	 * Filter a q=source answer.
	 *
	 * @internal Hooked to `micropub_query` at 20.
	 *
	 * @param mixed $resp  The query response.
	 * @param mixed $input The Micropub request input.
	 * @return mixed
	 */
	public static function filter_query( $resp, $input ) {
		if ( ! is_array( $resp ) || ! is_array( $input ) || 'source' !== ( $input['q'] ?? null ) ) {
			return $resp;
		}

		if ( array_key_exists( 'url', $input ) ) {
			$post_id = url_to_postid( (string) ( is_array( $input['url'] ) ? reset( $input['url'] ) : $input['url'] ) );

			return self::filter_item( $resp, $post_id, true );
		}

		if ( ! isset( $resp['items'] ) || ! is_array( $resp['items'] ) ) {
			return $resp;
		}

		$ids   = self::listed_ids( $input );
		$items = [];
		foreach ( array_values( $resp['items'] ) as $index => $item ) {
			// An item that can't be matched to its post is dropped, never passed through.
			$filtered = count( $ids ) === count( $resp['items'] ) && is_array( $item ) ? self::filter_item( $item, $ids[ $index ], false ) : null;
			if ( is_array( $filtered ) ) {
				$items[] = $filtered;
			}
		}
		$resp['items'] = $items;

		return $resp;
	}

	/**
	 * The post IDs a list q=source answer holds, in order: the same
	 * get_posts() call the Micropub plugin makes (handle_source_query()).
	 * Its `post-status` argument isn't a WP_Query var, so it lists
	 * published posts only, and so does this.
	 *
	 * @param array<string, mixed> $input The Micropub request input.
	 * @return int[]
	 */
	private static function listed_ids( array $input ): array {
		$args = [
			'posts_per_page' => function_exists( 'mp_get' ) ? \mp_get( $input, 'limit', 10 ) : ( $input['limit'] ?? 10 ),
			'fields'         => 'ids',
		];
		if ( array_key_exists( 'offset', $input ) ) {
			$args['offset'] = function_exists( 'mp_get' ) ? \mp_get( $input, 'offset' ) : $input['offset'];
		}

		return array_map( 'intval', (array) get_posts( $args ) );
	}

	/**
	 * One mf2 item as the requester may see it.
	 *
	 * @param array<string, mixed> $item    The item.
	 * @param int                  $post_id Its post.
	 * @param bool                 $single  Whether it's a q=source&url= answer, which gets an error instead of null.
	 * @return array<string, mixed>|\WP_Error|null Null drops a listed item.
	 */
	private static function filter_item( array $item, int $post_id, bool $single ) {
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post ) {
			return $single ? new \WP_Error( 'forbidden', 'not found', [ 'status' => 403 ] ) : null;
		}
		if ( current_user_can( 'edit_post', $post_id ) ) {
			return $item;
		}
		if ( 'publish' !== $post->post_status || '' !== (string) $post->post_password ) {
			return $single ? new \WP_Error( 'forbidden', 'not found', [ 'status' => 403 ] ) : null;
		}

		$properties = is_array( $item['properties'] ?? null ) ? $item['properties'] : [];

		if ( isset( $properties['content'] ) && is_array( $properties['content'] ) ) {
			foreach ( $properties['content'] as $key => $content ) {
				if ( is_string( $content ) ) {
					$properties['content'][ $key ] = Meta_Fields::strip_private_location( Meta_Fields::strip_private_health( Meta_Fields::strip_private_cost( $content, $post_id, true ), $post_id ), $post_id );
				}
			}
		}

		if ( in_array( false, Meta_Fields::get_public_location_fields( $post_id ), true ) ) {
			$properties = array_diff_key( $properties, array_flip( self::LOCATION_PROPERTIES ) );
		}

		$terms = get_the_terms( $post_id, Taxonomy::TAXONOMY );
		$kind  = is_array( $terms ) && isset( $terms[0] ) && $terms[0] instanceof \WP_Term ? $terms[0]->slug : '';
		if ( isset( self::HEALTH_PROPERTIES[ $kind ] ) && ! Meta_Fields::health_details_visible( $post_id ) ) {
			$properties = array_diff_key( $properties, array_flip( self::HEALTH_PROPERTIES[ $kind ] ) );
		}

		$safe = Title_Privacy::safe_title( $post_id );
		if ( isset( $properties['name'] ) && $safe !== (string) $post->post_title ) {
			$properties['name'] = [ wp_strip_all_tags( $safe ) ];
		}

		$item['properties'] = $properties;

		return $item;
	}
}
