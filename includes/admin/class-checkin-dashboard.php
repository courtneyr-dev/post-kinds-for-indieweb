<?php
/**
 * Check-in Dashboard
 *
 * Admin page for viewing all check-ins in a Foursquare-like interface.
 *
 * @package Reactions_For_IndieWeb
 * @since   1.1.0
 */

namespace ReactionsForIndieWeb\Admin;

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
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue dashboard assets.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'reactions_page_reactions-indieweb-checkins' !== $hook ) {
			return;
		}

		// Enqueue Leaflet for maps.
		wp_enqueue_style(
			'leaflet',
			'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
			array(),
			'1.9.4'
		);

		wp_enqueue_script(
			'leaflet',
			'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
			array(),
			'1.9.4',
			true
		);

		// Enqueue Leaflet MarkerCluster.
		wp_enqueue_style(
			'leaflet-markercluster',
			'https://unpkg.com/leaflet.markercluster@1.4.1/dist/MarkerCluster.css',
			array( 'leaflet' ),
			'1.4.1'
		);

		wp_enqueue_style(
			'leaflet-markercluster-default',
			'https://unpkg.com/leaflet.markercluster@1.4.1/dist/MarkerCluster.Default.css',
			array( 'leaflet-markercluster' ),
			'1.4.1'
		);

		wp_enqueue_script(
			'leaflet-markercluster',
			'https://unpkg.com/leaflet.markercluster@1.4.1/dist/leaflet.markercluster.js',
			array( 'leaflet' ),
			'1.4.1',
			true
		);

		// Enqueue dashboard styles.
		wp_enqueue_style(
			'reactions-checkin-dashboard',
			REACTIONS_INDIEWEB_URL . 'assets/css/checkin-dashboard.css',
			array( 'leaflet', 'leaflet-markercluster' ),
			REACTIONS_INDIEWEB_VERSION
		);

		// Enqueue dashboard script.
		wp_enqueue_script(
			'reactions-checkin-dashboard',
			REACTIONS_INDIEWEB_URL . 'assets/js/checkin-dashboard.js',
			array( 'jquery', 'leaflet', 'leaflet-markercluster', 'wp-api-fetch' ),
			REACTIONS_INDIEWEB_VERSION,
			true
		);

		wp_localize_script(
			'reactions-checkin-dashboard',
			'reactionsCheckinDashboard',
			array(
				'restUrl'   => rest_url( 'reactions-indieweb/v1/' ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'siteUrl'   => home_url(),
				'i18n'      => array(
					'loading'      => __( 'Loading check-ins...', 'reactions-for-indieweb' ),
					'noCheckins'   => __( 'No check-ins found.', 'reactions-for-indieweb' ),
					'viewOnMap'    => __( 'View on map', 'reactions-for-indieweb' ),
					'editPost'     => __( 'Edit', 'reactions-for-indieweb' ),
					'viewPost'     => __( 'View', 'reactions-for-indieweb' ),
					'totalCheckins'=> __( 'Total Check-ins', 'reactions-for-indieweb' ),
					'uniqueVenues' => __( 'Unique Venues', 'reactions-for-indieweb' ),
					'countries'    => __( 'Countries', 'reactions-for-indieweb' ),
					'cities'       => __( 'Cities', 'reactions-for-indieweb' ),
				),
			)
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
				<h1><?php esc_html_e( 'Check-in Dashboard', 'reactions-for-indieweb' ); ?></h1>
				<div class="checkin-view-toggles">
					<button type="button" class="button active" data-view="grid">
						<span class="dashicons dashicons-grid-view"></span>
						<?php esc_html_e( 'Grid', 'reactions-for-indieweb' ); ?>
					</button>
					<button type="button" class="button" data-view="map">
						<span class="dashicons dashicons-location"></span>
						<?php esc_html_e( 'Map', 'reactions-for-indieweb' ); ?>
					</button>
					<button type="button" class="button" data-view="timeline">
						<span class="dashicons dashicons-list-view"></span>
						<?php esc_html_e( 'Timeline', 'reactions-for-indieweb' ); ?>
					</button>
				</div>
			</div>

			<div class="checkin-filters">
				<select id="checkin-year-filter">
					<option value=""><?php esc_html_e( 'All Years', 'reactions-for-indieweb' ); ?></option>
					<?php
					$current_year = (int) gmdate( 'Y' );
					for ( $year = $current_year; $year >= $current_year - 10; $year-- ) {
						printf( '<option value="%d">%d</option>', $year, $year );
					}
					?>
				</select>

				<select id="checkin-type-filter">
					<option value=""><?php esc_html_e( 'All Venue Types', 'reactions-for-indieweb' ); ?></option>
					<option value="restaurant"><?php esc_html_e( 'Restaurants', 'reactions-for-indieweb' ); ?></option>
					<option value="cafe"><?php esc_html_e( 'Cafes', 'reactions-for-indieweb' ); ?></option>
					<option value="bar"><?php esc_html_e( 'Bars', 'reactions-for-indieweb' ); ?></option>
					<option value="hotel"><?php esc_html_e( 'Hotels', 'reactions-for-indieweb' ); ?></option>
					<option value="airport"><?php esc_html_e( 'Airports', 'reactions-for-indieweb' ); ?></option>
					<option value="park"><?php esc_html_e( 'Parks', 'reactions-for-indieweb' ); ?></option>
					<option value="museum"><?php esc_html_e( 'Museums', 'reactions-for-indieweb' ); ?></option>
					<option value="store"><?php esc_html_e( 'Stores', 'reactions-for-indieweb' ); ?></option>
				</select>

				<input type="search" id="checkin-search" placeholder="<?php esc_attr_e( 'Search venues...', 'reactions-for-indieweb' ); ?>">
			</div>

			<div class="checkin-dashboard-content">
				<div class="checkin-views">
					<!-- Grid view -->
					<div class="checkin-grid-view active">
						<div class="checkin-loading">
							<span class="spinner is-active"></span>
							<?php esc_html_e( 'Loading check-ins...', 'reactions-for-indieweb' ); ?>
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
						<h3><span class="dashicons dashicons-chart-bar"></span> <?php esc_html_e( 'Overview', 'reactions-for-indieweb' ); ?></h3>
						<div class="stats-numbers">
							<div class="stat-item">
								<span class="stat-value" id="stat-total-checkins">-</span>
								<span class="stat-label"><?php esc_html_e( 'Check-ins', 'reactions-for-indieweb' ); ?></span>
							</div>
							<div class="stat-item">
								<span class="stat-value" id="stat-unique-venues">-</span>
								<span class="stat-label"><?php esc_html_e( 'Venues', 'reactions-for-indieweb' ); ?></span>
							</div>
							<div class="stat-item">
								<span class="stat-value" id="stat-countries">-</span>
								<span class="stat-label"><?php esc_html_e( 'Countries', 'reactions-for-indieweb' ); ?></span>
							</div>
							<div class="stat-item">
								<span class="stat-value" id="stat-cities">-</span>
								<span class="stat-label"><?php esc_html_e( 'Cities', 'reactions-for-indieweb' ); ?></span>
							</div>
						</div>
					</div>

					<!-- Top Venues -->
					<div class="stats-card">
						<h3><span class="dashicons dashicons-star-filled"></span> <?php esc_html_e( 'Most Visited', 'reactions-for-indieweb' ); ?></h3>
						<ul class="top-venues-list" id="top-venues-list">
							<li><?php esc_html_e( 'Loading...', 'reactions-for-indieweb' ); ?></li>
						</ul>
					</div>

					<!-- Countries Visited -->
					<div class="stats-card">
						<h3><span class="dashicons dashicons-admin-site"></span> <?php esc_html_e( 'Countries', 'reactions-for-indieweb' ); ?></h3>
						<div class="places-list" id="countries-list">
							<span class="place-tag"><?php esc_html_e( 'Loading...', 'reactions-for-indieweb' ); ?></span>
						</div>
					</div>

					<!-- Cities Visited -->
					<div class="stats-card">
						<h3><span class="dashicons dashicons-location"></span> <?php esc_html_e( 'Cities', 'reactions-for-indieweb' ); ?></h3>
						<div class="places-list" id="cities-list">
							<span class="place-tag"><?php esc_html_e( 'Loading...', 'reactions-for-indieweb' ); ?></span>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
