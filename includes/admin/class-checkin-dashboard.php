<?php
/**
 * Check-in Dashboard
 *
 * Admin page for viewing all check-ins in a Foursquare-like interface.
 *
 * @package PKIW
 * @since   1.1.0
 */

namespace PKIW\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check-in Dashboard class.
 */
class Checkin_Dashboard {

	/**
	 * Admin instance.
	 *
	 * @var Admin
	 */
	private Admin $admin;

	/**
	 * Constructor.
	 *
	 * @param Admin $admin Admin instance.
	 */
	public function __construct( Admin $admin ) {
		$this->admin = $admin;
	}

	/**
	 * Initialize dashboard.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Enqueue dashboard assets.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'pkiw_page_post-kinds-indieweb-checkins' !== $hook ) {
			return;
		}

		// Enqueue Leaflet for maps (bundled locally).
		wp_enqueue_style(
			'leaflet',
			PKIW_URL . 'assets/vendor/leaflet/leaflet.css',
			[],
			'1.9.4'
		);

		wp_enqueue_script(
			'leaflet',
			PKIW_URL . 'assets/vendor/leaflet/leaflet.js',
			[],
			'1.9.4',
			true
		);

		// Enqueue Leaflet MarkerCluster (bundled locally).
		wp_enqueue_style(
			'leaflet-markercluster',
			PKIW_URL . 'assets/vendor/leaflet-markercluster/MarkerCluster.css',
			[ 'leaflet' ],
			'1.4.1'
		);

		wp_enqueue_style(
			'leaflet-markercluster-default',
			PKIW_URL . 'assets/vendor/leaflet-markercluster/MarkerCluster.Default.css',
			[ 'leaflet-markercluster' ],
			'1.4.1'
		);

		wp_enqueue_script(
			'leaflet-markercluster',
			PKIW_URL . 'assets/vendor/leaflet-markercluster/leaflet.markercluster.js',
			[ 'leaflet' ],
			'1.4.1',
			true
		);

		// Enqueue dashboard styles.
		wp_enqueue_style(
			'pkiw-checkin-dashboard',
			PKIW_URL . 'assets/css/checkin-dashboard.css',
			[ 'leaflet', 'leaflet-markercluster' ],
			PKIW_VERSION
		);

		// Enqueue dashboard script.
		wp_enqueue_script(
			'pkiw-checkin-dashboard',
			PKIW_URL . 'assets/js/checkin-dashboard.js',
			[ 'jquery', 'leaflet', 'leaflet-markercluster', 'wp-api-fetch' ],
			PKIW_VERSION,
			true
		);

		wp_localize_script(
			'pkiw-checkin-dashboard',
			'pkiwCheckinDashboard',
			[
				'restUrl' => rest_url( 'post-kinds-indieweb/v1/' ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'siteUrl' => home_url(),
				'i18n'    => [
					'loading'       => __( 'Loading check-ins...', 'post-kinds-for-indieweb-in-block-themes' ),
					'noCheckins'    => __( 'No check-ins found.', 'post-kinds-for-indieweb-in-block-themes' ),
					'viewOnMap'     => __( 'View on map', 'post-kinds-for-indieweb-in-block-themes' ),
					'editPost'      => __( 'Edit', 'post-kinds-for-indieweb-in-block-themes' ),
					'viewPost'      => __( 'View', 'post-kinds-for-indieweb-in-block-themes' ),
					'totalCheckins' => __( 'Total Check-ins', 'post-kinds-for-indieweb-in-block-themes' ),
					'uniqueVenues'  => __( 'Unique Venues', 'post-kinds-for-indieweb-in-block-themes' ),
					'countries'     => __( 'Countries', 'post-kinds-for-indieweb-in-block-themes' ),
					'cities'        => __( 'Cities', 'post-kinds-for-indieweb-in-block-themes' ),
				],
			]
		);
	}

	/**
	 * Render the dashboard page.
	 *
	 * @return void
	 */
	public function render(): void {
		?>
		<div class="wrap checkin-dashboard-wrap">
			<div class="checkin-dashboard-header">
				<h1><?php esc_html_e( 'Check-in Dashboard', 'post-kinds-for-indieweb-in-block-themes' ); ?></h1>
				<div class="checkin-view-toggles">
					<button type="button" class="button active" data-view="grid">
						<span class="dashicons dashicons-grid-view"></span>
						<?php esc_html_e( 'Grid', 'post-kinds-for-indieweb-in-block-themes' ); ?>
					</button>
					<button type="button" class="button" data-view="map">
						<span class="dashicons dashicons-location"></span>
						<?php esc_html_e( 'Map', 'post-kinds-for-indieweb-in-block-themes' ); ?>
					</button>
					<button type="button" class="button" data-view="timeline">
						<span class="dashicons dashicons-list-view"></span>
						<?php esc_html_e( 'Timeline', 'post-kinds-for-indieweb-in-block-themes' ); ?>
					</button>
				</div>
			</div>

			<div class="checkin-filters">
				<select id="checkin-year-filter">
					<option value=""><?php esc_html_e( 'All Years', 'post-kinds-for-indieweb-in-block-themes' ); ?></option>
					<?php
					$current_year = (int) gmdate( 'Y' );
					for ( $pkiw_year = $current_year; $pkiw_year >= $current_year - 10; $pkiw_year-- ) {
						printf( '<option value="%d">%d</option>', absint( $pkiw_year ), absint( $pkiw_year ) );
					}
					?>
				</select>

				<select id="checkin-type-filter">
					<option value=""><?php esc_html_e( 'All Venue Types', 'post-kinds-for-indieweb-in-block-themes' ); ?></option>
					<option value="restaurant"><?php esc_html_e( 'Restaurants', 'post-kinds-for-indieweb-in-block-themes' ); ?></option>
					<option value="cafe"><?php esc_html_e( 'Cafes', 'post-kinds-for-indieweb-in-block-themes' ); ?></option>
					<option value="bar"><?php esc_html_e( 'Bars', 'post-kinds-for-indieweb-in-block-themes' ); ?></option>
					<option value="hotel"><?php esc_html_e( 'Hotels', 'post-kinds-for-indieweb-in-block-themes' ); ?></option>
					<option value="airport"><?php esc_html_e( 'Airports', 'post-kinds-for-indieweb-in-block-themes' ); ?></option>
					<option value="park"><?php esc_html_e( 'Parks', 'post-kinds-for-indieweb-in-block-themes' ); ?></option>
					<option value="museum"><?php esc_html_e( 'Museums', 'post-kinds-for-indieweb-in-block-themes' ); ?></option>
					<option value="store"><?php esc_html_e( 'Stores', 'post-kinds-for-indieweb-in-block-themes' ); ?></option>
				</select>

				<input type="search" id="checkin-search" placeholder="<?php esc_attr_e( 'Search venues...', 'post-kinds-for-indieweb-in-block-themes' ); ?>">
			</div>

			<div class="checkin-dashboard-content">
				<div class="checkin-views">
					<!-- Grid view -->
					<div class="checkin-grid-view active">
						<div class="checkin-loading">
							<span class="spinner is-active"></span>
							<?php esc_html_e( 'Loading check-ins...', 'post-kinds-for-indieweb-in-block-themes' ); ?>
						</div>
					</div>

					<!-- Map view -->
					<div class="checkin-map-view">
						<div id="checkin-map"></div>
					</div>

					<!-- Timeline view -->
					<div class="checkin-timeline-view"></div>
				</div>

				<div class="checkin-stats-sidebar">
					<!-- Overview Stats -->
					<div class="stats-card">
						<h3><span class="dashicons dashicons-chart-bar"></span> <?php esc_html_e( 'Overview', 'post-kinds-for-indieweb-in-block-themes' ); ?></h3>
						<div class="stats-numbers">
							<div class="stat-item">
								<span class="stat-value" id="stat-total-checkins">-</span>
								<span class="stat-label"><?php esc_html_e( 'Check-ins', 'post-kinds-for-indieweb-in-block-themes' ); ?></span>
							</div>
							<div class="stat-item">
								<span class="stat-value" id="stat-unique-venues">-</span>
								<span class="stat-label"><?php esc_html_e( 'Venues', 'post-kinds-for-indieweb-in-block-themes' ); ?></span>
							</div>
							<div class="stat-item">
								<span class="stat-value" id="stat-countries">-</span>
								<span class="stat-label"><?php esc_html_e( 'Countries', 'post-kinds-for-indieweb-in-block-themes' ); ?></span>
							</div>
							<div class="stat-item">
								<span class="stat-value" id="stat-cities">-</span>
								<span class="stat-label"><?php esc_html_e( 'Cities', 'post-kinds-for-indieweb-in-block-themes' ); ?></span>
							</div>
						</div>
					</div>

					<!-- Top Venues -->
					<div class="stats-card">
						<h3><span class="dashicons dashicons-star-filled"></span> <?php esc_html_e( 'Most Visited', 'post-kinds-for-indieweb-in-block-themes' ); ?></h3>
						<ul class="top-venues-list" id="top-venues-list">
							<li><?php esc_html_e( 'Loading...', 'post-kinds-for-indieweb-in-block-themes' ); ?></li>
						</ul>
					</div>

					<!-- Countries Visited -->
					<div class="stats-card">
						<h3><span class="dashicons dashicons-admin-site"></span> <?php esc_html_e( 'Countries', 'post-kinds-for-indieweb-in-block-themes' ); ?></h3>
						<div class="places-list" id="countries-list">
							<span class="place-tag"><?php esc_html_e( 'Loading...', 'post-kinds-for-indieweb-in-block-themes' ); ?></span>
						</div>
					</div>

					<!-- Cities Visited -->
					<div class="stats-card">
						<h3><span class="dashicons dashicons-location"></span> <?php esc_html_e( 'Cities', 'post-kinds-for-indieweb-in-block-themes' ); ?></h3>
						<div class="places-list" id="cities-list">
							<span class="place-tag"><?php esc_html_e( 'Loading...', 'post-kinds-for-indieweb-in-block-themes' ); ?></span>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
