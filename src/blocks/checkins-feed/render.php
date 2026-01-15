<?php
/**
 * Check-ins Feed Block - Server-side Render
 *
 * @package PostKindsForIndieWeb
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

use function PostKindsForIndieWeb\get_checkins;
use function PostKindsForIndieWeb\get_checkins_at_venue;
use function PostKindsForIndieWeb\get_checkin_location;
use function PostKindsForIndieWeb\format_location;

// Extract attributes with defaults.
$count        = absint( $attributes['count'] ?? 10 );
$show_map     = ! empty( $attributes['showMap'] );
$show_venue   = $attributes['showVenue'] ?? true;
$show_date    = $attributes['showDate'] ?? true;
$show_excerpt = ! empty( $attributes['showExcerpt'] );
$venue_id     = absint( $attributes['venueId'] ?? 0 );
$layout       = $attributes['layout'] ?? 'list';
$columns      = absint( $attributes['columns'] ?? 2 );

// Query check-ins.
$args = [ 'posts_per_page' => $count ];

if ( $venue_id > 0 ) {
	$query = get_checkins_at_venue( $venue_id, $args );
} else {
	$query = get_checkins( $args );
}

// Collect location data for map.
$map_markers = [];

if ( $show_map && $query->have_posts() ) {
	foreach ( $query->posts as $checkin_post ) {
		$location = get_checkin_location( $checkin_post );

		if ( ! empty( $location['latitude'] ) && ! empty( $location['longitude'] ) ) {
			$map_markers[] = [
				'id'        => $checkin_post->ID,
				'title'     => get_the_title( $checkin_post ),
				'url'       => get_permalink( $checkin_post ),
				'latitude'  => floatval( $location['latitude'] ),
				'longitude' => floatval( $location['longitude'] ),
				'venue'     => $location['name'] ?? '',
			];
		}
	}
}

// Build wrapper classes.
$wrapper_classes = [
	'checkins-feed',
	'layout-' . esc_attr( $layout ),
];

if ( 'grid' === $layout ) {
	$wrapper_classes[] = 'columns-' . esc_attr( $columns );
}

$wrapper_attributes = get_block_wrapper_attributes(
	[
		'class' => implode( ' ', $wrapper_classes ),
	]
);

// Generate unique ID for map.
$map_id = 'checkins-map-' . wp_unique_id();
?>

<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php if ( $show_map && ! empty( $map_markers ) ) : ?>
		<div class="checkins-feed__map" id="<?php echo esc_attr( $map_id ); ?>" data-markers="<?php echo esc_attr( wp_json_encode( $map_markers ) ); ?>">
			<noscript>
				<p class="checkins-feed__map-fallback">
					<?php esc_html_e( 'Map requires JavaScript to display.', 'post-kinds-for-indieweb' ); ?>
				</p>
			</noscript>
		</div>
	<?php endif; ?>

	<?php if ( $query->have_posts() ) : ?>
		<div class="checkins-feed__list h-feed">
			<?php while ( $query->have_posts() ) : ?>
				<?php $query->the_post(); ?>
				<?php $location = get_checkin_location(); ?>

				<article class="checkins-feed__item h-entry">
					<?php if ( has_post_thumbnail() ) : ?>
						<div class="checkins-feed__thumbnail">
							<a href="<?php the_permalink(); ?>" class="u-url">
								<?php the_post_thumbnail( 'thumbnail', [ 'class' => 'u-photo' ] ); ?>
							</a>
						</div>
					<?php endif; ?>

					<div class="checkins-feed__content">
						<h3 class="checkins-feed__title p-name">
							<a href="<?php the_permalink(); ?>" class="u-url">
								<?php the_title(); ?>
							</a>
						</h3>

						<?php if ( $show_venue && ! empty( $location['name'] ) ) : ?>
							<div class="checkins-feed__venue">
								<span class="checkins-feed__venue-icon" aria-hidden="true">📍</span>
								<?php echo format_location( $location, 'medium' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</div>
						<?php endif; ?>

						<?php if ( $show_date ) : ?>
							<time class="checkins-feed__date dt-published" datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>">
								<?php echo esc_html( get_the_date() ); ?>
							</time>
						<?php endif; ?>

						<?php if ( $show_excerpt && has_excerpt() ) : ?>
							<div class="checkins-feed__excerpt p-summary">
								<?php the_excerpt(); ?>
							</div>
						<?php endif; ?>
					</div>

					<?php // Hidden microformat data. ?>
					<?php if ( ! empty( $location ) ) : ?>
						<span class="p-location h-adr" hidden>
							<?php if ( ! empty( $location['name'] ) ) : ?>
								<span class="p-name"><?php echo esc_html( $location['name'] ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $location['city'] ) ) : ?>
								<span class="p-locality"><?php echo esc_html( $location['city'] ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $location['country'] ) ) : ?>
								<span class="p-country-name"><?php echo esc_html( $location['country'] ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $location['latitude'] ) && ! empty( $location['longitude'] ) ) : ?>
								<span class="h-geo">
									<data class="p-latitude" value="<?php echo esc_attr( $location['latitude'] ); ?>"></data>
									<data class="p-longitude" value="<?php echo esc_attr( $location['longitude'] ); ?>"></data>
								</span>
							<?php endif; ?>
						</span>
					<?php endif; ?>
				</article>
			<?php endwhile; ?>
			<?php wp_reset_postdata(); ?>
		</div>
	<?php else : ?>
		<p class="checkins-feed__empty">
			<?php esc_html_e( 'No check-ins found.', 'post-kinds-for-indieweb' ); ?>
		</p>
	<?php endif; ?>
</div>
