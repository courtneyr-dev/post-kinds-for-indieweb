<?php
/**
 * Check-in Dashboard Block - Server-side Render
 *
 * @package PostKindsForIndieWeb
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block default content.
 * @var WP_Block $block      Block instance.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- render.php variables are scoped by WordPress block rendering.

$pkiw_layout       = $attributes['layout'] ?? 'grid';
$pkiw_show_map     = $attributes['showMap'] ?? true;
$pkiw_show_stats   = $attributes['showStats'] ?? true;
$pkiw_limit        = $attributes['limit'] ?? 12;
$pkiw_show_filters = $attributes['showFilters'] ?? false;

// Enqueue Leaflet for map view.
if ( $pkiw_show_map ) {
	wp_enqueue_style( 'leaflet', POST_KINDS_INDIEWEB_URL . 'assets/vendor/leaflet/leaflet.css', [], '1.9.4' );
	wp_enqueue_script( 'leaflet', POST_KINDS_INDIEWEB_URL . 'assets/vendor/leaflet/leaflet.js', [], '1.9.4', true );
	wp_enqueue_style( 'leaflet-markercluster', POST_KINDS_INDIEWEB_URL . 'assets/vendor/leaflet-markercluster/MarkerCluster.css', [ 'leaflet' ], '1.4.1' );
	wp_enqueue_style( 'leaflet-markercluster-default', POST_KINDS_INDIEWEB_URL . 'assets/vendor/leaflet-markercluster/MarkerCluster.Default.css', [ 'leaflet-markercluster' ], '1.4.1' );
	wp_enqueue_script( 'leaflet-markercluster', POST_KINDS_INDIEWEB_URL . 'assets/vendor/leaflet-markercluster/leaflet.markercluster.js', [ 'leaflet' ], '1.4.1', true );
}

// Get check-ins.
$pkiw_args = [
	'post_type'      => 'post',
	'posts_per_page' => $pkiw_limit,
	'post_status'    => 'publish',
	'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		[
			'taxonomy' => 'indieblocks_kind',
			'field'    => 'slug',
			'terms'    => 'checkin',
		],
	],
	'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		[
			'key'     => '_reactions_checkin_venue_name',
			'compare' => 'EXISTS',
		],
	],
	'orderby'        => 'date',
	'order'          => 'DESC',
];

$pkiw_checkins_query = new WP_Query( $pkiw_args );
$pkiw_checkins       = [];

if ( $pkiw_checkins_query->have_posts() ) {
	while ( $pkiw_checkins_query->have_posts() ) {
		$pkiw_checkins_query->the_post();
		$pkiw_post_id = get_the_ID();

		$pkiw_checkin = [
			'id'         => $pkiw_post_id,
			'venue_name' => get_post_meta( $pkiw_post_id, '_reactions_checkin_venue_name', true ),
			'address'    => get_post_meta( $pkiw_post_id, '_reactions_checkin_address', true ),
			'venue_type' => get_post_meta( $pkiw_post_id, '_reactions_checkin_venue_type', true ),
			'latitude'   => get_post_meta( $pkiw_post_id, '_reactions_checkin_latitude', true ),
			'longitude'  => get_post_meta( $pkiw_post_id, '_reactions_checkin_longitude', true ),
			'photo'      => get_post_meta( $pkiw_post_id, '_reactions_checkin_photo', true ),
			'note'       => get_the_excerpt(),
			'date'       => get_the_date( 'c' ),
			'permalink'  => get_permalink(),
		];

		// Check privacy settings.
		$pkiw_privacy = get_post_meta( $pkiw_post_id, '_reactions_checkin_geo_privacy', true );
		if ( 'private' === $pkiw_privacy ) {
			$pkiw_checkin['latitude']  = null;
			$pkiw_checkin['longitude'] = null;
		}

		$pkiw_checkins[] = $pkiw_checkin;
	}
	wp_reset_postdata();
}

// Calculate stats.
$pkiw_stats = [
	'total'         => $pkiw_checkins_query->found_posts,
	'unique_venues' => 0,
	'countries'     => [],
	'cities'        => [],
];

$pkiw_unique_venues = [];
foreach ( $pkiw_checkins as $pkiw_checkin ) {
	if ( ! empty( $pkiw_checkin['venue_name'] ) ) {
		$pkiw_unique_venues[ $pkiw_checkin['venue_name'] ] = true;
	}
}
$pkiw_stats['unique_venues'] = count( $pkiw_unique_venues );

// Get wrapper attributes.
$pkiw_wrapper_attributes = get_block_wrapper_attributes(
	[
		'class'         => 'checkin-dashboard-frontend layout-' . esc_attr( $pkiw_layout ),
		'data-layout'   => esc_attr( $pkiw_layout ),
		'data-show-map' => $pkiw_show_map ? 'true' : 'false',
		'data-limit'    => esc_attr( $pkiw_limit ),
	]
);
?>

<div <?php echo $pkiw_wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php if ( $pkiw_show_stats ) : ?>
	<div class="checkin-dashboard-stats">
		<div class="stat-item">
			<span class="stat-value"><?php echo esc_html( $pkiw_stats['total'] ); ?></span>
			<span class="stat-label"><?php esc_html_e( 'Check-ins', 'post-kinds-for-indieweb' ); ?></span>
		</div>
		<div class="stat-item">
			<span class="stat-value"><?php echo esc_html( $pkiw_stats['unique_venues'] ); ?></span>
			<span class="stat-label"><?php esc_html_e( 'Venues', 'post-kinds-for-indieweb' ); ?></span>
		</div>
	</div>
	<?php endif; ?>

	<?php if ( $pkiw_show_filters ) : ?>
	<div class="checkin-dashboard-filters">
		<button type="button" class="view-btn active" data-view="grid">
			<?php esc_html_e( 'Grid', 'post-kinds-for-indieweb' ); ?>
		</button>
		<?php if ( $pkiw_show_map ) : ?>
		<button type="button" class="view-btn" data-view="map">
			<?php esc_html_e( 'Map', 'post-kinds-for-indieweb' ); ?>
		</button>
		<?php endif; ?>
		<button type="button" class="view-btn" data-view="timeline">
			<?php esc_html_e( 'Timeline', 'post-kinds-for-indieweb' ); ?>
		</button>
	</div>
	<?php endif; ?>

	<div class="checkin-dashboard-views">
		<!-- Grid View -->
		<div class="checkin-view-grid <?php echo 'grid' === $pkiw_layout ? 'active' : ''; ?>">
			<?php if ( empty( $pkiw_checkins ) ) : ?>
			<div class="checkin-empty">
				<p><?php esc_html_e( 'No check-ins yet.', 'post-kinds-for-indieweb' ); ?></p>
			</div>
			<?php else : ?>
			<div class="checkin-grid">
				<?php foreach ( $pkiw_checkins as $pkiw_checkin ) : ?>
				<article class="checkin-card h-entry">
					<?php if ( ! empty( $pkiw_checkin['photo'] ) ) : ?>
					<div class="checkin-card-photo">
						<img src="<?php echo esc_url( $pkiw_checkin['photo'] ); ?>" alt="<?php echo esc_attr( $pkiw_checkin['venue_name'] ); ?>" class="u-photo" loading="lazy">
					</div>
					<?php endif; ?>
					<div class="checkin-card-content">
						<h3 class="checkin-card-venue p-name">
							<a href="<?php echo esc_url( $pkiw_checkin['permalink'] ); ?>" class="u-url">
								<?php echo esc_html( $pkiw_checkin['venue_name'] ); ?>
							</a>
						</h3>
						<?php if ( ! empty( $pkiw_checkin['address'] ) ) : ?>
						<p class="checkin-card-address p-location"><?php echo esc_html( $pkiw_checkin['address'] ); ?></p>
						<?php endif; ?>
						<time class="checkin-card-date dt-published" datetime="<?php echo esc_attr( $pkiw_checkin['date'] ); ?>">
							<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $pkiw_checkin['date'] ) ) ); ?>
						</time>
					</div>
				</article>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
		</div>

		<?php if ( $pkiw_show_map ) : ?>
		<!-- Map View -->
		<div class="checkin-view-map <?php echo 'map' === $pkiw_layout ? 'active' : ''; ?>">
			<div id="checkin-frontend-map" class="checkin-map" data-checkins="
			<?php
			echo esc_attr(
				wp_json_encode(
					array_filter(
						$pkiw_checkins,
						function ( $c ) {
							return ! empty( $c['latitude'] );
						}
					)
				)
			);
			?>
																				"></div>
		</div>
		<?php endif; ?>

		<!-- Timeline View -->
		<div class="checkin-view-timeline <?php echo 'timeline' === $pkiw_layout ? 'active' : ''; ?>">
			<?php
			// Group by month.
			$pkiw_grouped = [];
			foreach ( $pkiw_checkins as $pkiw_checkin ) {
				$pkiw_month_key = date_i18n( 'F Y', strtotime( $pkiw_checkin['date'] ) );
				if ( ! isset( $pkiw_grouped[ $pkiw_month_key ] ) ) {
					$pkiw_grouped[ $pkiw_month_key ] = [];
				}
				$pkiw_grouped[ $pkiw_month_key ][] = $pkiw_checkin;
			}
			?>
			<?php foreach ( $pkiw_grouped as $pkiw_month => $pkiw_month_checkins ) : ?>
			<div class="timeline-group">
				<h3 class="timeline-month"><?php echo esc_html( $pkiw_month ); ?></h3>
				<div class="timeline-items">
					<?php foreach ( $pkiw_month_checkins as $pkiw_checkin ) : ?>
					<div class="timeline-item h-entry">
						<div class="timeline-marker"></div>
						<div class="timeline-content">
							<a href="<?php echo esc_url( $pkiw_checkin['permalink'] ); ?>" class="timeline-venue u-url p-name">
								<?php echo esc_html( $pkiw_checkin['venue_name'] ); ?>
							</a>
							<?php if ( ! empty( $pkiw_checkin['address'] ) ) : ?>
							<span class="timeline-address p-location"><?php echo esc_html( $pkiw_checkin['address'] ); ?></span>
							<?php endif; ?>
							<time class="timeline-date dt-published" datetime="<?php echo esc_attr( $pkiw_checkin['date'] ); ?>">
								<?php echo esc_html( date_i18n( 'M j, g:i a', strtotime( $pkiw_checkin['date'] ) ) ); ?>
							</time>
						</div>
					</div>
					<?php endforeach; ?>
				</div>
			</div>
			<?php endforeach; ?>
		</div>
	</div>
</div>
