<?php
/**
 * WP-CLI Commands
 *
 * Provides CLI commands for managing check-ins and venues.
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

// Only load if WP-CLI is active.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

use WP_CLI;
use WP_CLI\Utils;

/**
 * Manage Post Kinds for IndieWeb check-ins and venues.
 *
 * @since 1.2.0
 */
class CLI_Commands {

	/**
	 * Recompute the cached _pkiw_surface value for every post.
	 *
	 * Run once after a bulk import that bypasses the normal save hooks.
	 *
	 * ## EXAMPLES
	 *
	 *     wp postkind surfaces backfill
	 *
	 * @param array $args       Positional arguments; expects 'backfill'.
	 * @param array $assoc_args Associative arguments (unused).
	 * @return void
	 */
	public function surfaces( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$sub = $args[0] ?? '';
		if ( 'backfill' !== $sub ) {
			WP_CLI::error( 'Usage: wp postkind surfaces backfill' );
		}
		$count = Post_Surface::backfill();
		WP_CLI::success( sprintf( 'Recomputed _pkiw_surface for %d posts.', $count ) );
	}

	/**
	 * Display check-in statistics.
	 *
	 * ## EXAMPLES
	 *
	 *     wp postkind checkin-stats
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments (unused).
	 * @return void
	 */
	public function checkin_stats( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$checkin_count = get_checkin_count();

		$venue_terms = wp_count_terms(
			[
				'taxonomy'   => Venue_Taxonomy::TAXONOMY,
				'hide_empty' => false,
			]
		);

		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%BCheck-in Statistics%n' ) );
		WP_CLI::log( str_repeat( '─', 40 ) );
		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Posts with checkin kind: %d', $checkin_count ) );
		WP_CLI::log( sprintf( 'Venue terms: %d', is_wp_error( $venue_terms ) ? 0 : $venue_terms ) );
		WP_CLI::log( '' );
	}

	/**
	 * List all venues.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp postkind venues
	 *     wp postkind venues --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function venues( array $args, array $assoc_args ): void {
		$format = Utils\get_flag_value( $assoc_args, 'format', 'table' );

		$terms = get_terms(
			[
				'taxonomy'   => Venue_Taxonomy::TAXONOMY,
				'hide_empty' => false,
			]
		);

		if ( is_wp_error( $terms ) ) {
			WP_CLI::error( $terms->get_error_message() );
			return;
		}

		if ( empty( $terms ) ) {
			WP_CLI::warning( 'No venues found.' );
			return;
		}

		$data = [];

		foreach ( $terms as $term ) {
			$city      = get_term_meta( $term->term_id, 'city', true );
			$country   = get_term_meta( $term->term_id, 'country', true );
			$latitude  = get_term_meta( $term->term_id, 'latitude', true );
			$longitude = get_term_meta( $term->term_id, 'longitude', true );

			$data[] = [
				'id'        => $term->term_id,
				'name'      => $term->name,
				'slug'      => $term->slug,
				'city'      => $city ? $city : '-',
				'country'   => $country ? $country : '-',
				'latitude'  => $latitude ? $latitude : '-',
				'longitude' => $longitude ? $longitude : '-',
				'count'     => $term->count,
			];
		}

		Utils\format_items( $format, $data, [ 'id', 'name', 'city', 'country', 'latitude', 'longitude', 'count' ] );
	}

	/**
	 * Create a venue.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The venue name.
	 *
	 * [--address=<address>]
	 * : Street address.
	 *
	 * [--city=<city>]
	 * : City.
	 *
	 * [--region=<region>]
	 * : State or region.
	 *
	 * [--country=<country>]
	 * : Country.
	 *
	 * [--latitude=<latitude>]
	 * : GPS latitude.
	 *
	 * [--longitude=<longitude>]
	 * : GPS longitude.
	 *
	 * [--porcelain]
	 * : Output just the venue ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp postkind create-venue "Coffee Shop" --city="Portland" --country="USA"
	 *     wp postkind create-venue "Central Park" --latitude=40.7829 --longitude=-73.9654
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function create_venue( array $args, array $assoc_args ): void {
		$name      = $args[0];
		$porcelain = Utils\get_flag_value( $assoc_args, 'porcelain', false );

		$location_data = [
			'name'      => $name,
			'address'   => Utils\get_flag_value( $assoc_args, 'address', '' ),
			'city'      => Utils\get_flag_value( $assoc_args, 'city', '' ),
			'region'    => Utils\get_flag_value( $assoc_args, 'region', '' ),
			'country'   => Utils\get_flag_value( $assoc_args, 'country', '' ),
			'latitude'  => Utils\get_flag_value( $assoc_args, 'latitude', '' ),
			'longitude' => Utils\get_flag_value( $assoc_args, 'longitude', '' ),
		];

		$result = Venue_Taxonomy::create_or_get( $location_data );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		if ( $porcelain ) {
			WP_CLI::log( $result );
		} else {
			WP_CLI::success( sprintf( 'Created venue "%s" with ID %d.', $name, $result ) );
		}
	}
}

// Register the command.
WP_CLI::add_command( 'postkind', __NAMESPACE__ . '\\CLI_Commands' );
