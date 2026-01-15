<?php
/**
 * Venue Detail Block - Server-side Render
 *
 * @package PostKindsForIndieWeb
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

use PostKindsForIndieWeb\Venue_Taxonomy;
use function PostKindsForIndieWeb\get_checkins_at_venue;

// Extract attributes with defaults.
$venue_id      = absint( $attributes['venueId'] ?? 0 );
$show_map      = ! empty( $attributes['showMap'] );
$show_address  = $attributes['showAddress'] ?? true;
$show_checkins = $attributes['showCheckins'] ?? true;
$checkin_count = absint( $attributes['checkinCount'] ?? 5 );

// If no venue ID is set, try to get from current query (venue archive page).
if ( 0 === $venue_id && is_tax( Venue_Taxonomy::TAXONOMY ) ) {
	$queried_object = get_queried_object();
	if ( $queried_object instanceof WP_Term ) {
		$venue_id = $queried_object->term_id;
	}
}

// Get venue data.
$venue = $venue_id > 0 ? get_term( $venue_id, Venue_Taxonomy::TAXONOMY ) : null;

if ( ! $venue || is_wp_error( $venue ) ) {
	// Render nothing if no venue found.
	return;
}

// Get venue meta.
$latitude  = get_term_meta( $venue_id, 'latitude', true );
$longitude = get_term_meta( $venue_id, 'longitude', true );
$address   = get_term_meta( $venue_id, 'address', true );
$city      = get_term_meta( $venue_id, 'city', true );
$region    = get_term_meta( $venue_id, 'region', true );
$country   = get_term_meta( $venue_id, 'country', true );
$url       = get_term_meta( $venue_id, 'url', true );
$phone     = get_term_meta( $venue_id, 'phone', true );

// Build address parts.
$address_parts = array_filter( [ $address, $city, $region, $country ] );
$full_address  = implode( ', ', $address_parts );

// Generate unique ID for map.
$map_id = 'venue-map-' . wp_unique_id();

// Map marker data.
$map_data = null;
if ( $show_map && $latitude && $longitude ) {
	$map_data = [
		[
			'id'        => $venue_id,
			'title'     => $venue->name,
			'latitude'  => floatval( $latitude ),
			'longitude' => floatval( $longitude ),
		],
	];
}

// Get recent check-ins.
$checkins = null;
if ( $show_checkins ) {
	$checkins = get_checkins_at_venue( $venue_id, [ 'posts_per_page' => $checkin_count ] );
}

$wrapper_attributes = get_block_wrapper_attributes(
	[
		'class' => 'venue-detail',
	]
);
?>

<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="venue-detail__card h-card">
		<?php if ( $show_map && $map_data ) : ?>
			<div class="venue-detail__map" id="<?php echo esc_attr( $map_id ); ?>" data-markers="<?php echo esc_attr( wp_json_encode( $map_data ) ); ?>">
				<noscript>
					<p class="venue-detail__map-fallback">
						<?php esc_html_e( 'Map requires JavaScript to display.', 'post-kinds-for-indieweb' ); ?>
					</p>
				</noscript>
			</div>
		<?php endif; ?>

		<div class="venue-detail__info">
			<h2 class="venue-detail__name p-name">
				<?php echo esc_html( $venue->name ); ?>
			</h2>

			<?php if ( $show_address && $full_address ) : ?>
				<div class="venue-detail__address p-adr h-adr">
					<span class="venue-detail__address-icon" aria-hidden="true">📍</span>
					<span class="venue-detail__address-text">
						<?php if ( $address ) : ?>
							<span class="p-street-address"><?php echo esc_html( $address ); ?></span>
						<?php endif; ?>
						<?php if ( $city ) : ?>
							<span class="p-locality"><?php echo esc_html( $city ); ?></span>
						<?php endif; ?>
						<?php if ( $region ) : ?>
							<span class="p-region"><?php echo esc_html( $region ); ?></span>
						<?php endif; ?>
						<?php if ( $country ) : ?>
							<span class="p-country-name"><?php echo esc_html( $country ); ?></span>
						<?php endif; ?>
					</span>
				</div>
			<?php endif; ?>

			<?php if ( $url ) : ?>
				<div class="venue-detail__website">
					<span class="venue-detail__website-icon" aria-hidden="true">🔗</span>
					<a href="<?php echo esc_url( $url ); ?>" class="u-url" target="_blank" rel="noopener noreferrer">
						<?php echo esc_html( wp_parse_url( $url, PHP_URL_HOST ) ); ?>
					</a>
				</div>
			<?php endif; ?>

			<?php if ( $phone ) : ?>
				<div class="venue-detail__phone">
					<span class="venue-detail__phone-icon" aria-hidden="true">📞</span>
					<a href="tel:<?php echo esc_attr( preg_replace( '/[^+\d]/', '', $phone ) ); ?>" class="p-tel">
						<?php echo esc_html( $phone ); ?>
					</a>
				</div>
			<?php endif; ?>

			<?php // Hidden geo data for microformats. ?>
			<?php if ( $latitude && $longitude ) : ?>
				<span class="h-geo" hidden>
					<data class="p-latitude" value="<?php echo esc_attr( $latitude ); ?>"></data>
					<data class="p-longitude" value="<?php echo esc_attr( $longitude ); ?>"></data>
				</span>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( $show_checkins && $checkins && $checkins->have_posts() ) : ?>
		<div class="venue-detail__checkins">
			<h3 class="venue-detail__checkins-title">
				<?php esc_html_e( 'Recent Check-ins', 'post-kinds-for-indieweb' ); ?>
			</h3>

			<div class="venue-detail__checkins-list h-feed">
				<?php while ( $checkins->have_posts() ) : ?>
					<?php $checkins->the_post(); ?>
					<article class="venue-detail__checkin-item h-entry">
						<a href="<?php the_permalink(); ?>" class="venue-detail__checkin-link u-url">
							<span class="venue-detail__checkin-title p-name"><?php the_title(); ?></span>
							<time class="venue-detail__checkin-date dt-published" datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>">
								<?php echo esc_html( get_the_date() ); ?>
							</time>
						</a>
					</article>
				<?php endwhile; ?>
				<?php wp_reset_postdata(); ?>
			</div>

			<?php if ( $checkins->found_posts > $checkin_count ) : ?>
				<p class="venue-detail__checkins-more">
					<a href="<?php echo esc_url( get_term_link( $venue ) ); ?>">
						<?php
						printf(
							/* translators: %d: total number of check-ins */
							esc_html__( 'View all %d check-ins →', 'post-kinds-for-indieweb' ),
							absint( $checkins->found_posts )
						);
						?>
					</a>
				</p>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>
