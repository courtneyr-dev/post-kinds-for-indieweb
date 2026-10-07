<?php
/**
 * WP-CLI Commands
 *
 * Provides CLI commands for managing check-ins and venues.
 *
 * @package PKIW
 * @since 1.2.0
 */

declare(strict_types=1);

namespace PKIW;

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
 * Manage Post Kinds for IndieWeb in Block Themes check-ins and venues.
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
	 * Re-mirror card block attributes into _pkiw_* meta for existing posts.
	 *
	 * Runs the same batched, idempotent backfill the scheduled event runs
	 * after an upgrade, all at once. Reads post content; never rewrites it.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Must be 'backfill'.
	 *
	 * [--batch=<size>]
	 * : Posts per batch.
	 * ---
	 * default: 50
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp postkind card-meta backfill
	 *
	 * @subcommand card-meta
	 *
	 * @param array $args       Positional arguments; expects 'backfill'.
	 * @param array $assoc_args Associative arguments (batch).
	 * @return void
	 */
	public function card_meta( array $args, array $assoc_args ): void {
		if ( 'backfill' !== ( $args[0] ?? '' ) ) {
			WP_CLI::error( 'Usage: wp postkind card-meta backfill [--batch=<size>]' );
		}

		$batch  = max( 1, (int) ( $assoc_args['batch'] ?? Card_Meta_Sync::BACKFILL_BATCH ) );
		$cursor = 0;
		$total  = 0;
		do {
			$result = Card_Meta_Sync::backfill_batch( $cursor, $batch );
			$cursor = $result['last_id'];
			$total += $result['processed'];
		} while ( ! $result['done'] );

		update_option( Card_Meta_Sync::BACKFILL_OPTION, Card_Meta_Sync::BACKFILL_VERSION, false );
		delete_option( Card_Meta_Sync::BACKFILL_CURSOR );
		wp_clear_scheduled_hook( Card_Meta_Sync::BACKFILL_HOOK );

		WP_CLI::success( sprintf( 'Re-synced card meta for %d post(s).', $total ) );
	}

	/**
	 * Apply the configured default category to existing kind-bearing posts.
	 *
	 * New kind posts get the default category automatically; this backfills the
	 * posts that existed before the setting was configured. Posts you have
	 * already curated (the per-post marker is set) are skipped, so a deliberate
	 * removal is never undone.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would change without writing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp postkind default-category backfill
	 *     wp postkind default-category backfill --dry-run
	 *
	 * @param array $args       Positional arguments; expects 'backfill'.
	 * @param array $assoc_args Associative arguments (dry-run).
	 * @return void
	 */
	public function default_category( array $args, array $assoc_args ): void {
		$sub = $args[0] ?? '';
		if ( 'backfill' !== $sub ) {
			WP_CLI::error( 'Usage: wp postkind default-category backfill [--dry-run]' );
		}
		if ( Default_Category::configured_category_id() <= 0 ) {
			WP_CLI::error( 'No default category is configured (Post Kinds → General → Default category).' );
		}

		$dry_run = (bool) Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$stats   = ( new Default_Category() )->backfill( $dry_run );

		if ( $dry_run ) {
			WP_CLI::success(
				sprintf(
					'Dry run: %d kind post(s) scanned, %d would get the default category.',
					$stats['scanned'],
					$stats['would_update']
				)
			);
			return;
		}

		WP_CLI::success(
			sprintf(
				'%d kind post(s) scanned, %d updated, %d skipped.',
				$stats['scanned'],
				$stats['updated'],
				$stats['skipped']
			)
		);
	}

	/**
	 * Give existing talk and deck posts the presentation kind.
	 *
	 * Applies the rule a save applies: a deck (a Speaker Deck, SlideShare,
	 * Google Slides, Notist or Canva embed, or a `[slideshare]` shortcode), a
	 * WordPress.tv recording or authored presentation fields. A YouTube,
	 * VideoPress, Vimeo or Dailymotion embed alone never counts, and a
	 * listen-card or listen-kind post is never claimed without one of those.
	 * Only posts with no kind, the default `note`, or a kind matching their
	 * `_pkiw_kind_auto_assigned` marker change; any other kind is protected.
	 * Each candidate's line names its kind and marker. Sets the kind term
	 * only, so modified dates, revisions and syndication stay untouched.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Must be 'backfill'.
	 *
	 * [--dry-run]
	 * : List each candidate and its signals without writing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp postkind presentation backfill --dry-run
	 *     wp postkind presentation backfill
	 *
	 * @param array $args       Positional arguments; expects 'backfill'.
	 * @param array $assoc_args Associative arguments (dry-run).
	 * @return void
	 */
	public function presentation( array $args, array $assoc_args ): void {
		if ( 'backfill' !== ( $args[0] ?? '' ) ) {
			WP_CLI::error( 'Usage: wp postkind presentation backfill [--dry-run]' );
		}

		$taxonomy = Plugin::get_instance()->get_taxonomy();
		if ( ! $taxonomy instanceof Taxonomy ) {
			WP_CLI::error( 'The kind taxonomy is not loaded.' );
		}
		if ( ! $taxonomy->is_valid_kind( Presentation_Classifier::KIND ) ) {
			WP_CLI::error( 'The presentation kind term does not exist.' );
		}

		$dry_run = (bool) Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$report  = Presentation_Classifier::backfill( $taxonomy, $dry_run );

		foreach ( $report['rows'] as $row ) {
			WP_CLI::log( Presentation_Classifier::backfill_line( $row ) );
		}

		if ( $dry_run ) {
			WP_CLI::success(
				sprintf(
					'Dry run: %d post(s) scanned, %d would change, %d protected, %d already presentation.',
					$report['scanned'],
					$report['would_change'],
					$report['protected'],
					$report['unchanged']
				)
			);
			return;
		}

		WP_CLI::success(
			sprintf(
				'%d post(s) scanned, %d changed, %d protected, %d already presentation, %d failed.',
				$report['scanned'],
				$report['changed'],
				$report['protected'],
				$report['unchanged'],
				$report['failed']
			)
		);
	}

	/**
	 * Set featured images from kind artwork for existing posts.
	 *
	 * New saves handle this automatically; this backfills posts created
	 * before the feature existed. Posts with a featured image are left
	 * alone, and a previously attempted artwork URL is never re-tried,
	 * so deliberate removals stay removed.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Must be 'backfill'.
	 *
	 * [--dry-run]
	 * : Report what would change without fetching or writing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp postkind featured-artwork backfill
	 *     wp postkind featured-artwork backfill --dry-run
	 *
	 * @subcommand featured-artwork
	 *
	 * @param array $args       Positional arguments; expects 'backfill'.
	 * @param array $assoc_args Associative arguments (dry-run).
	 * @return void
	 */
	public function featured_artwork( array $args, array $assoc_args ): void {
		$sub = $args[0] ?? '';
		if ( 'backfill' !== $sub ) {
			WP_CLI::error( 'Usage: wp postkind featured-artwork backfill [--dry-run]' );
		}

		$dry_run = (bool) Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$stats   = ( new Featured_Artwork() )->backfill( $dry_run );

		if ( $dry_run ) {
			WP_CLI::success(
				sprintf(
					'Dry run: %d kind post(s) scanned, %d would get a featured image, %d skipped.',
					$stats['scanned'],
					$stats['updated'],
					$stats['skipped']
				)
			);
			return;
		}

		WP_CLI::success(
			sprintf(
				'%d kind post(s) scanned, %d featured image(s) set, %d skipped, %d failed.',
				$stats['scanned'],
				$stats['updated'],
				$stats['skipped'],
				$stats['failed']
			)
		);
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
