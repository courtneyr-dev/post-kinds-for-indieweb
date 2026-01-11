<?php
/**
 * Check-in Dashboard Block - Server-side Render
 *
 * @package Reactions_For_IndieWeb
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block default content.
 * @var WP_Block $block      Block instance.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$layout       = $attributes['layout'] ?? 'grid';
$show_map     = $attributes['showMap'] ?? true;
$show_stats   = $attributes['showStats'] ?? true;
$limit        = $attributes['limit'] ?? 12;
$show_filters = $attributes['showFilters'] ?? false;

// Enqueue Leaflet for map view
if ( $show_map ) {
	wp_enqueue_style( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', array(), '1.9.4' );
	wp_enqueue_script( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', array(), '1.9.4', true );
	wp_enqueue_style( 'leaflet-markercluster', 'https://unpkg.com/leaflet.markercluster@1.4.1/dist/MarkerCluster.css', array( 'leaflet' ), '1.4.1' );
	wp_enqueue_style( 'leaflet-markercluster-default', 'https://unpkg.com/leaflet.markercluster@1.4.1/dist/MarkerCluster.Default.css', array( 'leaflet-markercluster' ), '1.4.1' );
	wp_enqueue_script( 'leaflet-markercluster', 'https://unpkg.com/leaflet.markercluster@1.4.1/dist/leaflet.markercluster.js', array( 'leaflet' ), '1.4.1', true );
}

// Get check-ins
$args = array(
	'post_type'      => 'post',
	'posts_per_page' => $limit,
	'post_status'    => 'publish',
	'tax_query'      => array(
		array(
			'taxonomy' => 'indieblocks_kind',
			'field'    => 'slug',
			'terms'    => 'checkin',
		),
	),
	'meta_query'     => array(
		array(
			'key'     => '_reactions_checkin_venue_name',
			'compare' => 'EXISTS',
		),
	),
	'orderby'        => 'date',
	'order'          => 'DESC',
);

$checkins_query = new WP_Query( $args );
$checkins       = array();

if ( $checkins_query->have_posts() ) {
	while ( $checkins_query->have_posts() ) {
		$checkins_query->the_post();
		$post_id = get_the_ID();

		$checkin = array(
			'id'          => $post_id,
			'venue_name'  => get_post_meta( $post_id, '_reactions_checkin_venue_name', true ),
			'address'     => get_post_meta( $post_id, '_reactions_checkin_address', true ),
			'venue_type'  => get_post_meta( $post_id, '_reactions_checkin_venue_type', true ),
			'latitude'    => get_post_meta( $post_id, '_reactions_checkin_latitude', true ),
			'longitude'   => get_post_meta( $post_id, '_reactions_checkin_longitude', true ),
			'photo'       => get_post_meta( $post_id, '_reactions_checkin_photo', true ),
			'note'        => get_the_excerpt(),
			'date'        => get_the_date( 'c' ),
			'permalink'   => get_permalink(),
		);

		// Check privacy settings
		$privacy = get_post_meta( $post_id, '_reactions_checkin_geo_privacy', true );
		if ( 'private' === $privacy ) {
			$checkin['latitude']  = null;
			$checkin['longitude'] = null;
		}

		$checkins[] = $checkin;
	}
	wp_reset_postdata();
}

// Calculate stats
$stats = array(
	'total'         => $checkins_query->found_posts,
	'unique_venues' => 0,
	'countries'     => array(),
	'cities'        => array(),
);

$unique_venues = array();
foreach ( $checkins as $checkin ) {
	if ( ! empty( $checkin['venue_name'] ) ) {
		$unique_venues[ $checkin['venue_name'] ] = true;
	}
}
$stats['unique_venues'] = count( $unique_venues );

// Get wrapper attributes
$wrapper_attributes = get_block_wrapper_attributes( array(
	'class' => 'checkin-dashboard-frontend layout-' . esc_attr( $layout ),
	'data-layout' => esc_attr( $layout ),
	'data-show-map' => $show_map ? 'true' : 'false',
	'data-limit' => esc_attr( $limit ),
) );
?>

<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php if ( $show_stats ) : ?>
	<div class="checkin-dashboard-stats">
		<div class="stat-item">
			<span class="stat-value"><?php echo esc_html( $stats['total'] ); ?></span>
			<span class="stat-label"><?php esc_html_e( 'Check-ins', 'reactions-for-indieweb' ); ?></span>
		</div>
		<div class="stat-item">
			<span class="stat-value"><?php echo esc_html( $stats['unique_venues'] ); ?></span>
			<span class="stat-label"><?php esc_html_e( 'Venues', 'reactions-for-indieweb' ); ?></span>
		</div>
	</div>
	<?php endif; ?>

	<?php if ( $show_filters ) : ?>
	<div class="checkin-dashboard-filters">
		<button type="button" class="view-btn active" data-view="grid">
			<?php esc_html_e( 'Grid', 'reactions-for-indieweb' ); ?>
		</button>
		<?php if ( $show_map ) : ?>
		<button type="button" class="view-btn" data-view="map">
			<?php esc_html_e( 'Map', 'reactions-for-indieweb' ); ?>
		</button>
		<?php endif; ?>
		<button type="button" class="view-btn" data-view="timeline">
			<?php esc_html_e( 'Timeline', 'reactions-for-indieweb' ); ?>
		</button>
	</div>
	<?php endif; ?>

	<div class="checkin-dashboard-views">
		<!-- Grid View -->
		<div class="checkin-view-grid <?php echo 'grid' === $layout ? 'active' : ''; ?>">
			<?php if ( empty( $checkins ) ) : ?>
			<div class="checkin-empty">
				<p><?php esc_html_e( 'No check-ins yet.', 'reactions-for-indieweb' ); ?></p>
			</div>
			<?php else : ?>
			<div class="checkin-grid">
				<?php foreach ( $checkins as $checkin ) : ?>
				<article class="checkin-card h-entry">
					<?php if ( ! empty( $checkin['photo'] ) ) : ?>
					<div class="checkin-card-photo">
						<img src="<?php echo esc_url( $checkin['photo'] ); ?>" alt="<?php echo esc_attr( $checkin['venue_name'] ); ?>" class="u-photo" loading="lazy">
					</div>
					<?php endif; ?>
					<div class="checkin-card-content">
						<h3 class="checkin-card-venue p-name">
							<a href="<?php echo esc_url( $checkin['permalink'] ); ?>" class="u-url">
								<?php echo esc_html( $checkin['venue_name'] ); ?>
							</a>
						</h3>
						<?php if ( ! empty( $checkin['address'] ) ) : ?>
						<p class="checkin-card-address p-location"><?php echo esc_html( $checkin['address'] ); ?></p>
						<?php endif; ?>
						<time class="checkin-card-date dt-published" datetime="<?php echo esc_attr( $checkin['date'] ); ?>">
							<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $checkin['date'] ) ) ); ?>
						</time>
					</div>
				</article>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
		</div>

		<?php if ( $show_map ) : ?>
		<!-- Map View -->
		<div class="checkin-view-map <?php echo 'map' === $layout ? 'active' : ''; ?>">
			<div id="checkin-frontend-map" class="checkin-map" data-checkins="<?php echo esc_attr( wp_json_encode( array_filter( $checkins, function( $c ) { return ! empty( $c['latitude'] ); } ) ) ); ?>"></div>
		</div>
		<?php endif; ?>

		<!-- Timeline View -->
		<div class="checkin-view-timeline <?php echo 'timeline' === $layout ? 'active' : ''; ?>">
			<?php
			// Group by month
			$grouped = array();
			foreach ( $checkins as $checkin ) {
				$month_key = date_i18n( 'F Y', strtotime( $checkin['date'] ) );
				if ( ! isset( $grouped[ $month_key ] ) ) {
					$grouped[ $month_key ] = array();
				}
				$grouped[ $month_key ][] = $checkin;
			}
			?>
			<?php foreach ( $grouped as $month => $month_checkins ) : ?>
			<div class="timeline-group">
				<h3 class="timeline-month"><?php echo esc_html( $month ); ?></h3>
				<div class="timeline-items">
					<?php foreach ( $month_checkins as $checkin ) : ?>
					<div class="timeline-item h-entry">
						<div class="timeline-marker"></div>
						<div class="timeline-content">
							<a href="<?php echo esc_url( $checkin['permalink'] ); ?>" class="timeline-venue u-url p-name">
								<?php echo esc_html( $checkin['venue_name'] ); ?>
							</a>
							<?php if ( ! empty( $checkin['address'] ) ) : ?>
							<span class="timeline-address p-location"><?php echo esc_html( $checkin['address'] ); ?></span>
							<?php endif; ?>
							<time class="timeline-date dt-published" datetime="<?php echo esc_attr( $checkin['date'] ); ?>">
								<?php echo esc_html( date_i18n( 'M j, g:i a', strtotime( $checkin['date'] ) ) ); ?>
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
