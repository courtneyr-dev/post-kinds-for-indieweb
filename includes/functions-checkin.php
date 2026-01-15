<?php
/**
 * Checkin Helper Functions
 *
 * Unified functions for querying check-ins that work in both modes
 * (standard posts with checkin kind OR dedicated checkin CPT).
 *
 * @package PostKindsForIndieWeb
 * @since 1.2.0
 */

declare(strict_types=1);

namespace PostKindsForIndieWeb;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get check-ins with a unified query interface.
 *
 * Works regardless of whether check-ins are stored as standard posts
 * with the checkin kind or as the dedicated checkin CPT.
 *
 * @param array $args WP_Query arguments.
 * @return \WP_Query Query object.
 */
function get_checkins( array $args = [] ): \WP_Query {
	$defaults = [
		'posts_per_page' => 10,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'post_status'    => 'publish',
	];

	$args = wp_parse_args( $args, $defaults );

	if ( Checkin_Post_Type::is_enabled() ) {
		// Use the dedicated CPT.
		$args['post_type'] = Checkin_Post_Type::POST_TYPE;
	} else {
		// Use standard posts with checkin kind.
		$args['post_type'] = 'post';

		$checkin_term = get_term_by( 'slug', 'checkin', 'indieblocks_kind' );

		if ( $checkin_term ) {
			$existing_tax_query = $args['tax_query'] ?? [];

			$args['tax_query'] = array_merge(
				$existing_tax_query,
				[
					[
						'taxonomy' => 'indieblocks_kind',
						'field'    => 'term_id',
						'terms'    => $checkin_term->term_id,
					],
				]
			);
		}
	}

	return new \WP_Query( $args );
}

/**
 * Get check-ins at a specific venue.
 *
 * @param int   $venue_id Venue term ID.
 * @param array $args     Additional WP_Query arguments.
 * @return \WP_Query Query object.
 */
function get_checkins_at_venue( int $venue_id, array $args = [] ): \WP_Query {
	$existing_tax_query = $args['tax_query'] ?? [];

	$args['tax_query'] = array_merge(
		$existing_tax_query,
		[
			[
				'taxonomy' => Venue_Taxonomy::TAXONOMY,
				'field'    => 'term_id',
				'terms'    => $venue_id,
			],
		]
	);

	return get_checkins( $args );
}

/**
 * Get check-ins by a specific author.
 *
 * @param int   $author_id Author user ID.
 * @param array $args      Additional WP_Query arguments.
 * @return \WP_Query Query object.
 */
function get_checkins_by_author( int $author_id, array $args = [] ): \WP_Query {
	$args['author'] = $author_id;
	return get_checkins( $args );
}

/**
 * Get check-ins within a date range.
 *
 * @param string $after  Start date (strtotime compatible).
 * @param string $before End date (strtotime compatible).
 * @param array  $args   Additional WP_Query arguments.
 * @return \WP_Query Query object.
 */
function get_checkins_in_range( string $after, string $before = '', array $args = [] ): \WP_Query {
	$date_query = [
		[
			'after'     => $after,
			'inclusive' => true,
		],
	];

	if ( $before ) {
		$date_query[0]['before'] = $before;
	}

	$args['date_query'] = $date_query;

	return get_checkins( $args );
}

/**
 * Get the archive URL for check-ins.
 *
 * Returns the appropriate URL whether using CPT mode or standard posts.
 *
 * @return string Archive URL.
 */
function get_checkins_archive_url(): string {
	if ( Checkin_Post_Type::is_enabled() ) {
		$url = get_post_type_archive_link( Checkin_Post_Type::POST_TYPE );
		return $url ? $url : home_url( '/checkins/' );
	}

	$checkin_term = get_term_by( 'slug', 'checkin', 'indieblocks_kind' );

	if ( $checkin_term ) {
		$url = get_term_link( $checkin_term );
		return is_wp_error( $url ) ? home_url( '/' ) : $url;
	}

	return home_url( '/' );
}

/**
 * Check if the current query is for check-ins.
 *
 * @return bool True if on a check-ins archive or single check-in.
 */
function is_checkins_archive(): bool {
	if ( Checkin_Post_Type::is_enabled() ) {
		return is_post_type_archive( Checkin_Post_Type::POST_TYPE );
	}

	return is_tax( 'indieblocks_kind', 'checkin' );
}

/**
 * Check if the current post is a check-in.
 *
 * @param int|\WP_Post|null $post Post ID or object. Defaults to current post.
 * @return bool True if the post is a check-in.
 */
function is_checkin( $post = null ): bool {
	$post = get_post( $post );

	if ( ! $post ) {
		return false;
	}

	// Check if it's the checkin CPT.
	if ( Checkin_Post_Type::POST_TYPE === $post->post_type ) {
		return true;
	}

	// Check if it's a standard post with checkin kind.
	if ( 'post' === $post->post_type ) {
		return has_term( 'checkin', 'indieblocks_kind', $post );
	}

	return false;
}

/**
 * Get the venue for a check-in post.
 *
 * @param int|\WP_Post|null $post Post ID or object. Defaults to current post.
 * @return \WP_Term|null Venue term or null.
 */
function get_checkin_venue( $post = null ): ?\WP_Term {
	$post = get_post( $post );

	if ( ! $post ) {
		return null;
	}

	$venues = get_the_terms( $post, Venue_Taxonomy::TAXONOMY );

	if ( is_wp_error( $venues ) || empty( $venues ) ) {
		return null;
	}

	return $venues[0];
}

/**
 * Get the location data for a check-in post.
 *
 * Returns normalized location data from either the venue term
 * or the post meta (for posts without a venue term).
 *
 * @param int|\WP_Post|null $post Post ID or object. Defaults to current post.
 * @return array Location data with keys: name, address, city, region, country, latitude, longitude.
 */
function get_checkin_location( $post = null ): array {
	$post = get_post( $post );

	if ( ! $post ) {
		return [];
	}

	$location = [
		'name'      => '',
		'address'   => '',
		'city'      => '',
		'region'    => '',
		'country'   => '',
		'latitude'  => '',
		'longitude' => '',
	];

	// Try to get data from venue term first.
	$venue = get_checkin_venue( $post );

	if ( $venue ) {
		$location['name']      = $venue->name;
		$location['address']   = get_term_meta( $venue->term_id, 'address', true );
		$location['city']      = get_term_meta( $venue->term_id, 'city', true );
		$location['region']    = get_term_meta( $venue->term_id, 'region', true );
		$location['country']   = get_term_meta( $venue->term_id, 'country', true );
		$location['latitude']  = get_term_meta( $venue->term_id, 'latitude', true );
		$location['longitude'] = get_term_meta( $venue->term_id, 'longitude', true );
	} else {
		// Fall back to post meta.
		$location['name']      = get_post_meta( $post->ID, '_postkind_checkin_venue', true );
		$location['address']   = get_post_meta( $post->ID, '_postkind_checkin_address', true );
		$location['city']      = get_post_meta( $post->ID, '_postkind_checkin_city', true );
		$location['region']    = get_post_meta( $post->ID, '_postkind_checkin_region', true );
		$location['country']   = get_post_meta( $post->ID, '_postkind_checkin_country', true );
		$location['latitude']  = get_post_meta( $post->ID, '_postkind_checkin_latitude', true );
		$location['longitude'] = get_post_meta( $post->ID, '_postkind_checkin_longitude', true );
	}

	return array_filter( $location, fn( $val ) => '' !== $val );
}

/**
 * Get total check-in count.
 *
 * @return int Total number of published check-ins.
 */
function get_checkin_count(): int {
	if ( Checkin_Post_Type::is_enabled() ) {
		$counts = wp_count_posts( Checkin_Post_Type::POST_TYPE );
		return (int) ( $counts->publish ?? 0 );
	}

	$checkin_term = get_term_by( 'slug', 'checkin', 'indieblocks_kind' );

	if ( ! $checkin_term ) {
		return 0;
	}

	return (int) $checkin_term->count;
}

/**
 * Get venue count.
 *
 * @param bool $hide_empty Whether to exclude venues with no check-ins.
 * @return int Total number of venues.
 */
function get_venue_count( bool $hide_empty = false ): int {
	$count = wp_count_terms(
		[
			'taxonomy'   => Venue_Taxonomy::TAXONOMY,
			'hide_empty' => $hide_empty,
		]
	);

	return is_wp_error( $count ) ? 0 : (int) $count;
}

/**
 * Format a location for display.
 *
 * @param array  $location Location data from get_checkin_location().
 * @param string $format   Format type: 'short', 'medium', 'full', or 'microformat'.
 * @return string Formatted location string or HTML.
 */
function format_location( array $location, string $format = 'medium' ): string { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.MaxExceeded
	if ( empty( $location ) ) {
		return '';
	}

	switch ( $format ) {
		case 'short':
			// Just the name.
			return esc_html( $location['name'] ?? '' );

		case 'full':
			// Full address with all parts.
			$parts = array_filter(
				[
					$location['name'] ?? '',
					$location['address'] ?? '',
					$location['city'] ?? '',
					$location['region'] ?? '',
					$location['country'] ?? '',
				]
			);
			return esc_html( implode( ', ', $parts ) );

		case 'microformat':
			// HTML with microformat2 classes.
			$html = '<span class="p-location h-adr">';

			if ( ! empty( $location['name'] ) ) {
				$html .= '<span class="p-name">' . esc_html( $location['name'] ) . '</span>';
			}

			if ( ! empty( $location['address'] ) ) {
				$html .= '<span class="p-street-address">' . esc_html( $location['address'] ) . '</span>';
			}

			if ( ! empty( $location['city'] ) ) {
				$html .= '<span class="p-locality">' . esc_html( $location['city'] ) . '</span>';
			}

			if ( ! empty( $location['region'] ) ) {
				$html .= '<span class="p-region">' . esc_html( $location['region'] ) . '</span>';
			}

			if ( ! empty( $location['country'] ) ) {
				$html .= '<span class="p-country-name">' . esc_html( $location['country'] ) . '</span>';
			}

			if ( ! empty( $location['latitude'] ) && ! empty( $location['longitude'] ) ) {
				$html .= '<span class="h-geo">';
				$html .= '<data class="p-latitude" value="' . esc_attr( $location['latitude'] ) . '"></data>';
				$html .= '<data class="p-longitude" value="' . esc_attr( $location['longitude'] ) . '"></data>';
				$html .= '</span>';
			}

			$html .= '</span>';
			return $html;

		case 'medium':
		default:
			// Name, City, Country.
			$parts = array_filter(
				[
					$location['name'] ?? '',
					$location['city'] ?? '',
					$location['country'] ?? '',
				]
			);
			return esc_html( implode( ', ', $parts ) );
	}
}
