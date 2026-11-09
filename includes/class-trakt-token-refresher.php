<?php
/**
 * Trakt token refresher.
 *
 * Trakt access tokens last 7 days. Refresh tokens are single-use: each
 * refresh returns a new access/refresh pair and invalidates the refresh token
 * it spent. This class refreshes before token_expires, saves the new pair in
 * one write, and holds a lock so two processes never spend the same refresh
 * token.
 *
 * @package PKIW
 * @since 1.9.1
 */

namespace PKIW;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps a Trakt connection alive past the access token's 7-day lifetime.
 */
class Trakt_Token_Refresher {

	/**
	 * WP-Cron hook that runs the refresh check.
	 *
	 * @var string
	 */
	public const CRON_HOOK = 'pkiw_trakt_token_refresh';

	/**
	 * Option row used as a mutex while a refresh token is in flight.
	 *
	 * @var string
	 */
	public const LOCK_OPTION = 'pkiw_trakt_refresh_lock';

	/**
	 * Option holding Trakt's reason when a refresh token was rejected.
	 *
	 * @var string
	 */
	public const REAUTH_OPTION = 'pkiw_trakt_reauth_required';

	/**
	 * Refresh when the access token expires within this many seconds. The
	 * cron runs twice a day, so a 2-day window gives four attempts.
	 *
	 * @var int
	 */
	public const REFRESH_WINDOW = 2 * DAY_IN_SECONDS;

	/**
	 * A lock older than this belongs to a run that died; take it over.
	 *
	 * @var int
	 */
	private const LOCK_TTL = 2 * MINUTE_IN_SECONDS;

	/**
	 * Trakt's token endpoint.
	 *
	 * @var string
	 */
	private const TOKEN_URL = 'https://api.trakt.tv/oauth/token';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( self::CRON_HOOK, [ $this, 'run_scheduled' ] );
		add_action( 'admin_init', [ $this, 'ensure_scheduled' ] );
	}

	/**
	 * Schedule the refresh while Trakt is connected; clear it otherwise.
	 *
	 * @return void
	 */
	public function ensure_scheduled(): void {
		$connected = '' !== ( self::credentials()['refresh_token'] ?? '' );
		$scheduled = wp_next_scheduled( self::CRON_HOOK );

		if ( $connected && ! $scheduled ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', self::CRON_HOOK );
		} elseif ( ! $connected && $scheduled ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/**
	 * Cron callback.
	 *
	 * @return void
	 */
	public function run_scheduled(): void {
		$this->refresh();
	}

	/**
	 * Refresh the Trakt access token if it is due (or always, when forced).
	 *
	 * @param bool $force Refresh even when the token is outside the window.
	 * @return string One of refreshed, not_due, not_connected, locked,
	 *                reauth_required, failed.
	 */
	public function refresh( bool $force = false ): string {
		if ( ! $force && ! self::is_due( self::credentials() ) ) {
			return 'not_due';
		}

		if ( ! self::acquire_lock() ) {
			return 'locked';
		}

		try {
			// Re-read under the lock: another process may have refreshed
			// since the check above, and its refresh token is the live one.
			$trakt = self::credentials( true );
			if ( '' === ( $trakt['refresh_token'] ?? '' ) || '' === ( $trakt['client_id'] ?? '' ) ) {
				return 'not_connected';
			}
			if ( ! $force && ! self::is_due( $trakt ) ) {
				return 'not_due';
			}

			return $this->request_new_pair( $trakt );
		} finally {
			delete_option( self::LOCK_OPTION );
		}
	}

	/**
	 * Trakt's reason for the last rejected refresh, or null when none.
	 *
	 * @return string|null
	 */
	public static function get_reauth_reason(): ?string {
		$reason = get_option( self::REAUTH_OPTION, null );
		return is_string( $reason ) ? $reason : null;
	}

	/**
	 * Forget a rejected refresh; called when the user connects again.
	 *
	 * @return void
	 */
	public static function clear_reauth_reason(): void {
		delete_option( self::REAUTH_OPTION );
	}

	/**
	 * Spend the refresh token and save what Trakt returns.
	 *
	 * @param array<string, mixed> $trakt Current Trakt credentials.
	 * @return string Refresh status.
	 */
	private function request_new_pair( array $trakt ): string {
		// Public-client refresh grant (RFC 6749 section 6). Trakt's PKCE apps
		// have no client secret, and one saved from an older app is not sent.
		$response = wp_remote_post(
			self::TOKEN_URL,
			[
				'timeout' => 30,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode(
					[
						'refresh_token' => $trakt['refresh_token'],
						'client_id'     => $trakt['client_id'],
						'redirect_uri'  => admin_url( 'admin-post.php' ),
						'grant_type'    => 'refresh_token',
					]
				),
			]
		);

		if ( is_wp_error( $response ) ) {
			// The token was never received by Trakt; the next run retries it.
			return 'failed';
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		$body   = is_array( $body ) ? $body : [];

		if ( 200 === $status && ! empty( $body['access_token'] ) && is_string( $body['access_token'] ) ) {
			$expires_in = absint( $body['expires_in'] ?? 0 );
			$this->save(
				[
					'access_token'  => $body['access_token'],
					// Trakt always returns a new refresh token; keep the old
					// one only if a response somehow omits it.
					'refresh_token' => is_string( $body['refresh_token'] ?? null ) && '' !== $body['refresh_token'] ? $body['refresh_token'] : $trakt['refresh_token'],
					'token_expires' => time() + ( $expires_in > 0 ? $expires_in : WEEK_IN_SECONDS ),
				]
			);
			self::clear_reauth_reason();
			return 'refreshed';
		}

		if ( 'invalid_grant' === ( $body['error'] ?? '' ) ) {
			// The refresh token is dead (spent, revoked, or a legacy token
			// Trakt no longer exchanges). Only a new Connect fixes it.
			$this->save(
				[
					'access_token'  => '',
					'refresh_token' => '',
					'token_expires' => null,
				]
			);
			$reason = sanitize_text_field( wp_strip_all_tags( (string) ( $body['error_description'] ?? 'invalid_grant' ) ) );
			update_option( self::REAUTH_OPTION, '' !== $reason ? $reason : 'invalid_grant', false );
			wp_clear_scheduled_hook( self::CRON_HOOK );
			return 'reauth_required';
		}

		return 'failed';
	}

	/**
	 * Write token fields into pkiw_api_credentials['trakt'] in one update.
	 *
	 * @param array<string, string|int|null> $fields Fields to set; null removes one.
	 * @return void
	 */
	private function save( array $fields ): void {
		$all   = self::all_credentials( true );
		$trakt = is_array( $all['trakt'] ?? null ) ? $all['trakt'] : [];

		foreach ( $fields as $key => $value ) {
			if ( null === $value ) {
				unset( $trakt[ $key ] );
			} else {
				$trakt[ $key ] = $value;
			}
		}

		$all['trakt'] = $trakt;
		update_option( 'pkiw_api_credentials', $all );
	}

	/**
	 * Whether the access token expires inside the refresh window. An
	 * unknown expiry counts as due: older settings saves dropped it.
	 *
	 * @param array<string, mixed> $trakt Trakt credentials.
	 * @return bool
	 */
	private static function is_due( array $trakt ): bool {
		if ( '' === ( $trakt['refresh_token'] ?? '' ) ) {
			return false;
		}
		$expires = absint( $trakt['token_expires'] ?? 0 );
		return 0 === $expires || $expires <= time() + self::REFRESH_WINDOW;
	}

	/**
	 * Trakt credentials.
	 *
	 * @param bool $fresh Bypass the options cache.
	 * @return array<string, mixed>
	 */
	private static function credentials( bool $fresh = false ): array {
		$trakt = self::all_credentials( $fresh )['trakt'] ?? [];
		return is_array( $trakt ) ? $trakt : [];
	}

	/**
	 * All API credentials.
	 *
	 * @param bool $fresh Bypass the options cache.
	 * @return array<string, mixed>
	 */
	private static function all_credentials( bool $fresh ): array {
		if ( $fresh && ! wp_using_ext_object_cache() ) {
			// Without a persistent cache this request's copy dates from its
			// start; a parallel request may have written a newer pair since.
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'pkiw_api_credentials', 'options' );
		}
		$all = get_option( 'pkiw_api_credentials', [] );
		return is_array( $all ) ? $all : [];
	}

	/**
	 * Take the refresh lock. INSERT IGNORE succeeds for exactly one caller,
	 * the same mutex WP_Upgrader::create_lock() uses.
	 *
	 * @return bool True when this process holds the lock.
	 */
	private static function acquire_lock(): bool {
		global $wpdb;

		$now = time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic mutex; the options API has no insert-if-absent.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )",
				self::LOCK_OPTION,
				(string) $now
			)
		);
		if ( 1 === $inserted ) {
			wp_cache_delete( self::LOCK_OPTION, 'options' );
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read the mutex row itself, not a cached copy.
		$held_since = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION )
		);
		if ( null === $held_since || (int) $held_since > $now - self::LOCK_TTL ) {
			return false;
		}

		// Stale lock: take it only if no one else replaced it first.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- compare-and-swap on the mutex row.
		$taken = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				(string) $now,
				self::LOCK_OPTION,
				(string) $held_since
			)
		);
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		return 1 === $taken;
	}
}
