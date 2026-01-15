<?php
/**
 * WP-CLI Commands
 *
 * Provides CLI commands for managing check-ins and migrations.
 *
 * @package PostKindsForIndieWeb
 * @since 1.2.0
 */

declare(strict_types=1);

namespace PostKindsForIndieWeb;

// Prevent direct access and only load if WP-CLI is active.
if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

use WP_CLI;
use WP_CLI\Utils;

/**
 * Manage Post Kinds for IndieWeb check-ins and migrations.
 *
 * @since 1.2.0
 */
class CLI_Commands {

	/**
	 * Migrate check-ins between post types.
	 *
	 * ## OPTIONS
	 *
	 * [--direction=<direction>]
	 * : Migration direction.
	 * ---
	 * default: posts-to-cpt
	 * options:
	 *   - posts-to-cpt
	 *   - cpt-to-posts
	 * ---
	 *
	 * [--batch=<size>]
	 * : Batch size for processing.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--create-venues]
	 * : Create venue terms from location data during migration.
	 *
	 * [--dry-run]
	 * : Preview changes without actually migrating.
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     # Preview migration from posts to CPT
	 *     wp postkind migrate-checkins --direction=posts-to-cpt --dry-run
	 *
	 *     # Run actual migration with larger batch size
	 *     wp postkind migrate-checkins --direction=posts-to-cpt --batch=100 --yes
	 *
	 *     # Migrate back to standard posts
	 *     wp postkind migrate-checkins --direction=cpt-to-posts --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function migrate_checkins( array $args, array $assoc_args ): void {
		$direction     = Utils\get_flag_value( $assoc_args, 'direction', 'posts-to-cpt' );
		$batch_size    = (int) Utils\get_flag_value( $assoc_args, 'batch', 50 );
		$dry_run       = Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$create_venues = Utils\get_flag_value( $assoc_args, 'create-venues', true );
		$skip_confirm  = Utils\get_flag_value( $assoc_args, 'yes', false );

		// Check if CPT is enabled.
		if ( ! Checkin_Post_Type::is_enabled() ) {
			WP_CLI::error( 'Check-in CPT is not enabled. Enable it in Settings > Reactions > Checkin Posts first.' );
			return;
		}

		// Initialize the migrator.
		$migrator = new Admin\Checkin_Migrator();

		// Get counts.
		$post_count = $migrator->count_checkin_posts();
		$cpt_count  = $migrator->count_checkin_cpt();

		WP_CLI::log( '' );
		WP_CLI::log( 'Current status:' );
		WP_CLI::log( sprintf( '  Standard posts with checkin kind: %d', $post_count ) );
		WP_CLI::log( sprintf( '  Check-in CPT entries: %d', $cpt_count ) );
		WP_CLI::log( '' );

		if ( 'posts-to-cpt' === $direction ) {
			$source_count = $post_count;
			$action_desc  = 'Migrate standard posts to Check-in CPT';
		} else {
			$source_count = $cpt_count;
			$action_desc  = 'Migrate Check-in CPT to standard posts';
		}

		if ( 0 === $source_count ) {
			WP_CLI::warning( 'No posts found to migrate.' );
			return;
		}

		if ( $dry_run ) {
			WP_CLI::log( WP_CLI::colorize( '%YDRY RUN%n - no changes will be made.' ) );
			WP_CLI::log( '' );
		}

		WP_CLI::log( sprintf( 'Action: %s', $action_desc ) );
		WP_CLI::log( sprintf( 'Posts to process: %d', $source_count ) );
		WP_CLI::log( sprintf( 'Batch size: %d', $batch_size ) );
		WP_CLI::log( '' );

		if ( ! $dry_run && ! $skip_confirm ) {
			WP_CLI::confirm( 'Proceed with migration?' );
		}

		$total_migrated = 0;
		$progress       = null;

		if ( ! $dry_run ) {
			$progress = Utils\make_progress_bar( 'Migrating check-ins', $source_count );
		}

		// Process in batches.
		do {
			if ( 'posts-to-cpt' === $direction ) {
				$results = $migrator->migrate_posts_to_cpt( $batch_size, $dry_run, $create_venues );
			} else {
				$results = $migrator->migrate_cpt_to_posts( $batch_size, $dry_run );
			}

			if ( isset( $results['error'] ) ) {
				WP_CLI::error( $results['error'] );
				return;
			}

			$batch_count = $results['count'] ?? 0;

			// Output details.
			foreach ( $results['migrated'] ?? [] as $item ) {
				if ( $dry_run ) {
					WP_CLI::log(
						sprintf(
							'  [%d] %s - %s%s',
							$item['id'],
							$item['title'] ? $item['title'] : '(no title)',
							$item['action'],
							$item['venue'] ? ' (' . $item['venue'] . ')' : ''
						)
					);
				} elseif ( $progress ) {
						$progress->tick();
				}
			}

			$total_migrated += $batch_count;

			// Continue until no more posts or it's a dry run (only show first batch).
		} while ( $batch_count === $batch_size && ! $dry_run );

		if ( $progress ) {
			$progress->finish();
		}

		WP_CLI::log( '' );

		if ( $dry_run ) {
			WP_CLI::success( sprintf( 'Dry run complete. %d check-in(s) would be migrated.', $total_migrated ) );
		} else {
			// Flush rewrite rules.
			flush_rewrite_rules();
			WP_CLI::success( sprintf( 'Migration complete. %d check-in(s) migrated.', $total_migrated ) );
		}
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
		$migrator = new Admin\Checkin_Migrator();

		$post_count  = $migrator->count_checkin_posts();
		$cpt_count   = $migrator->count_checkin_cpt();
		$cpt_enabled = Checkin_Post_Type::is_enabled();

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
		WP_CLI::log( sprintf( 'Check-in CPT enabled: %s', $cpt_enabled ? 'Yes' : 'No' ) );
		WP_CLI::log( sprintf( 'Standard posts with checkin kind: %d', $post_count ) );
		WP_CLI::log( sprintf( 'Check-in CPT entries: %d', $cpt_count ) );
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
