<?php
/**
 * Scheduled Sync
 *
 * Handles automatic/scheduled imports from external services using WP-Cron.
 *
 * @package ReactionsForIndieWeb
 * @since   1.0.0
 */

declare(strict_types=1);

namespace ReactionsForIndieWeb;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scheduled Sync class.
 *
 * @since 1.0.0
 */
class Scheduled_Sync {

	/**
	 * Cron hook name.
	 *
	 * @var string
	 */
	private const CRON_HOOK = 'reactions_indieweb_scheduled_sync';

	/**
	 * Import manager instance.
	 *
	 * @var Import_Manager
	 */
	private Import_Manager $import_manager;

	/**
	 * Constructor.
	 *
	 * @param Import_Manager $import_manager Import manager instance.
	 */
	public function __construct( Import_Manager $import_manager ) {
		$this->import_manager = $import_manager;
	}

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		// Register the cron action.
		add_action( self::CRON_HOOK, array( $this, 'run_scheduled_sync' ) );

		// Schedule cron on settings save.
		add_action( 'update_option_reactions_indieweb_settings', array( $this, 'maybe_schedule_cron' ), 10, 2 );

		// Schedule on plugin activation.
		add_action( 'reactions_indieweb_activate', array( $this, 'schedule_cron' ) );

		// Unschedule on plugin deactivation.
		add_action( 'reactions_indieweb_deactivate', array( $this, 'unschedule_cron' ) );

		// Check and schedule if needed on admin init.
		add_action( 'admin_init', array( $this, 'ensure_scheduled' ) );
	}

	/**
	 * Ensure the cron is scheduled if auto-sync is enabled.
	 *
	 * @return void
	 */
	public function ensure_scheduled(): void {
		if ( $this->is_auto_sync_enabled() && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			$this->schedule_cron();
		}
	}

	/**
	 * Check if any auto-sync setting is enabled.
	 *
	 * @return bool
	 */
	private function is_auto_sync_enabled(): bool {
		$settings = get_option( 'reactions_indieweb_settings', array() );

		// Check if background sync is enabled.
		if ( empty( $settings['enable_background_sync'] ) ) {
			return false;
		}

		// Check if any individual auto-import is enabled.
		$auto_import_keys = array(
			'listen_auto_import',
			'listen_podcast_auto_import',
			'watch_auto_import',
			'read_auto_import',
			'read_articles_auto_import',
			'checkin_auto_import',
		);

		foreach ( $auto_import_keys as $key ) {
			if ( ! empty( $settings[ $key ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Schedule the cron event.
	 *
	 * @return void
	 */
	public function schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// Run hourly by default.
			wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Unschedule the cron event.
	 *
	 * @return void
	 */
	public function unschedule_cron(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Maybe schedule or unschedule cron based on settings change.
	 *
	 * @param mixed $old_value Old option value.
	 * @param mixed $new_value New option value.
	 * @return void
	 */
	public function maybe_schedule_cron( $old_value, $new_value ): void {
		if ( $this->is_auto_sync_enabled() ) {
			$this->schedule_cron();
		} else {
			$this->unschedule_cron();
		}
	}

	/**
	 * Run the scheduled sync.
	 *
	 * @return void
	 */
	public function run_scheduled_sync(): void {
		$settings = get_option( 'reactions_indieweb_settings', array() );

		// Check if background sync is enabled.
		if ( empty( $settings['enable_background_sync'] ) ) {
			return;
		}

		$this->log( 'Starting scheduled sync' );

		// Run each enabled auto-import.
		if ( ! empty( $settings['listen_auto_import'] ) ) {
			$this->sync_listen_music( $settings );
		}

		if ( ! empty( $settings['listen_podcast_auto_import'] ) ) {
			$this->sync_listen_podcasts();
		}

		if ( ! empty( $settings['watch_auto_import'] ) ) {
			$this->sync_watch( $settings );
		}

		if ( ! empty( $settings['read_auto_import'] ) ) {
			$this->sync_read_books( $settings );
		}

		if ( ! empty( $settings['read_articles_auto_import'] ) ) {
			$this->sync_read_articles();
		}

		if ( ! empty( $settings['checkin_auto_import'] ) ) {
			$this->sync_checkin( $settings );
		}

		// Update last sync time.
		update_option( 'reactions_indieweb_last_sync', time() );

		$this->log( 'Scheduled sync completed' );
	}

	/**
	 * Sync music/scrobble listen posts.
	 *
	 * @param array<string, mixed> $settings Plugin settings.
	 * @return void
	 */
	private function sync_listen_music( array $settings ): void {
		$source = $settings['listen_import_source'] ?? 'lastfm';

		// Map source to import manager source.
		$source_map = array(
			'lastfm'       => 'lastfm',
			'listenbrainz' => 'listenbrainz',
		);

		if ( isset( $source_map[ $source ] ) ) {
			$this->run_import( $source_map[ $source ] );
		}
	}

	/**
	 * Sync podcast listen posts from Readwise.
	 *
	 * @return void
	 */
	private function sync_listen_podcasts(): void {
		$credentials = get_option( 'reactions_indieweb_api_credentials', array() );
		if ( ! empty( $credentials['readwise']['access_token'] ) ) {
			// Enable update_existing to fill in metadata on previously imported posts.
			// Use smaller limit - each episode requires API calls for highlights.
			$this->run_import( 'readwise_podcasts', array(
				'update_existing' => true,
				'limit'           => 20,
			) );
		}
	}

	/**
	 * Sync watch posts.
	 *
	 * @param array<string, mixed> $settings Plugin settings.
	 * @return void
	 */
	private function sync_watch( array $settings ): void {
		$source = $settings['watch_import_source'] ?? 'trakt';

		$source_map = array(
			'trakt' => array( 'trakt_movies', 'trakt_shows' ),
			'simkl' => array( 'simkl' ),
		);

		if ( isset( $source_map[ $source ] ) ) {
			foreach ( $source_map[ $source ] as $import_source ) {
				$this->run_import( $import_source );
			}
		}
	}

	/**
	 * Sync book read posts.
	 *
	 * @param array<string, mixed> $settings Plugin settings.
	 * @return void
	 */
	private function sync_read_books( array $settings ): void {
		$source = $settings['read_import_source'] ?? 'hardcover';

		// Sync Readwise books if configured.
		// Use smaller limit - each book requires API calls for highlights.
		$credentials = get_option( 'reactions_indieweb_api_credentials', array() );
		if ( ! empty( $credentials['readwise']['access_token'] ) ) {
			$this->run_import( 'readwise_books', array( 'limit' => 20 ) );
		}

		if ( 'hardcover' === $source ) {
			$this->run_import( 'hardcover' );
		}
	}

	/**
	 * Sync article read posts from Readwise.
	 *
	 * @return void
	 */
	private function sync_read_articles(): void {
		$credentials = get_option( 'reactions_indieweb_api_credentials', array() );
		if ( ! empty( $credentials['readwise']['access_token'] ) ) {
			$this->run_import( 'readwise_articles', array( 'limit' => 30 ) );
		}
	}

	/**
	 * Sync checkin posts.
	 *
	 * @param array<string, mixed> $settings Plugin settings.
	 * @return void
	 */
	private function sync_checkin( array $settings ): void {
		$credentials = get_option( 'reactions_indieweb_api_credentials', array() );

		// Sync Foursquare if connected.
		if ( ! empty( $credentials['foursquare']['access_token'] ) ) {
			$this->run_import( 'foursquare' );
		}
	}

	/**
	 * Run an import job.
	 *
	 * @param string               $source  Import source identifier.
	 * @param array<string, mixed> $options Additional import options.
	 * @return void
	 */
	private function run_import( string $source, array $options = array() ): void {
		$this->log( "Running import: {$source}" );

		$default_options = array(
			'skip_existing'   => true,
			'update_existing' => false,
			'create_posts'    => true,
			'limit'           => 50, // Limit per sync to avoid overload.
		);

		$result = $this->import_manager->start_import(
			$source,
			array_merge( $default_options, $options )
		);

		if ( $result['success'] ) {
			$this->log( "Import started for {$source}: job {$result['job_id']}" );
		} else {
			$this->log( "Import failed for {$source}: {$result['error']}" );
		}
	}

	/**
	 * Log a sync message.
	 *
	 * @param string $message Message to log.
	 * @return void
	 */
	private function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[Reactions IndieWeb Sync] ' . $message );
		}
	}

	/**
	 * Get the last sync time.
	 *
	 * @return int|null Unix timestamp or null if never synced.
	 */
	public function get_last_sync_time(): ?int {
		$time = get_option( 'reactions_indieweb_last_sync' );
		return $time ? (int) $time : null;
	}

	/**
	 * Get the next scheduled sync time.
	 *
	 * @return int|null Unix timestamp or null if not scheduled.
	 */
	public function get_next_sync_time(): ?int {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		return $timestamp ? (int) $timestamp : null;
	}

	/**
	 * Manually trigger a sync.
	 *
	 * @return void
	 */
	public function trigger_sync(): void {
		$this->run_scheduled_sync();
	}
}
