<?php
/**
 * Venue Detail Block - Server-side Render
 *
 * @package PostKindsForIndieWeb
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use PostKindsForIndieWeb\Venue_Taxonomy;
use function PostKindsForIndieWeb\get_checkins_at_venue;

// Extract attributes with defaults.
$pkiw_venue_id      = absint( $attributes['venueId'] ?? 0 );
$pkiw_show_map      = ! empty( $attributes['showMap'] );
$pkiw_show_address  = $attributes['showAddress'] ?? true;
$pkiw_show_checkins = $attributes['showCheckins'] ?? true;
$pkiw_checkin_count = absint( $attributes['checkinCount'] ?? 5 );

// If no venue ID is set, try to get from current query (venue archive page).
if ( 0 === $pkiw_venue_id && is_tax( Venue_Taxonomy::TAXONOMY ) ) {
	$pkiw_queried_object = get_queried_object();
	if ( $pkiw_queried_object instanceof WP_Term ) {
		$pkiw_venue_id = $pkiw_queried_object->term_id;
	}
}

// Get venue data.
$pkiw_venue = $pkiw_venue_id > 0 ? get_term( $pkiw_venue_id, Venue_Taxonomy::TAXONOMY ) : null;

if ( ! $pkiw_venue || is_wp_error( $pkiw_venue ) ) {
	// Render nothing if no venue found.
	return;
}

// Get venue meta.
$pkiw_latitude  = get_term_meta( $pkiw_venue_id, 'latitude', true );
$pkiw_longitude = get_term_meta( $pkiw_venue_id, 'longitude', true );
$pkiw_address   = get_term_meta( $pkiw_venue_id, 'address', true );
$pkiw_city      = get_term_meta( $pkiw_venue_id, 'city', true );
$pkiw_region    = get_term_meta( $pkiw_venue_id, 'region', true );
$pkiw_country   = get_term_meta( $pkiw_venue_id, 'country', true );
$pkiw_url       = get_term_meta( $pkiw_venue_id, 'url', true );
$pkiw_phone     = get_term_meta( $pkiw_venue_id, 'phone', true );

// Build address parts.
$pkiw_address_parts = array_filter( [ $pkiw_address, $pkiw_city, $pkiw_region, $pkiw_country ] );
$pkiw_full_address  = implode( ', ', $pkiw_address_parts );

// Generate unique ID for map.
$pkiw_map_id = 'venue-map-' . wp_unique_id();

// Map marker data.
$pkiw_map_data = null;
if ( $pkiw_show_map && $pkiw_latitude && $pkiw_longitude ) {
	$pkiw_map_data = [
		[
			'id'        => $pkiw_venue_id,
			'title'     => $pkiw_venue->name,
			'latitude'  => floatval( $pkiw_latitude ),
			'longitude' => floatval( $pkiw_longitude ),
		],
	];
}

// Get recent check-ins.
$pkiw_checkins = null;
if ( $pkiw_show_checkins ) {
	$pkiw_checkins = get_checkins_at_venue( $pkiw_venue_id, [ 'posts_per_page' => $pkiw_checkin_count ] );
}

$pkiw_wrapper_attributes = get_block_wrapper_attributes(
	[
		'class' => 'venue-detail',
	]
);
?>

<div <?php echo $pkiw_wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="venue-detail__card h-card">
		<?php if ( $pkiw_show_map && $pkiw_map_data ) : ?>
			<div class="venue-detail__map" id="<?php echo esc_attr( $pkiw_map_id ); ?>" data-markers="<?php echo esc_attr( wp_json_encode( $pkiw_map_data ) ); ?>">
				<noscript>
					<p class="venue-detail__map-fallback">
						<?php esc_html_e( 'Map requires JavaScript to display.', 'post-kinds-for-indieweb' ); ?>
					</p>
				</noscript>
			</div>
		<?php endif; ?>

		<div class="venue-detail__info">
			<h2 class="venue-detail__name p-name">
				<?php echo esc_html( $pkiw_venue->name ); ?>
			</h2>

			<?php if ( $pkiw_show_address && $pkiw_full_address ) : ?>
				<div class="venue-detail__address p-adr h-adr">
					<span class="venue-detail__address-icon" aria-hidden="true">📍</span>
					<span class="venue-detail__address-text">
						<?php if ( $pkiw_address ) : ?>
							<span class="p-street-address"><?php echo esc_html( $pkiw_address ); ?></span>
						<?php endif; ?>
						<?php if ( $pkiw_city ) : ?>
							<span class="p-locality"><?php echo esc_html( $pkiw_city ); ?></span>
						<?php endif; ?>
						<?php if ( $pkiw_region ) : ?>
							<span class="p-region"><?php echo esc_html( $pkiw_region ); ?></span>
						<?php endif; ?>
						<?php if ( $pkiw_country ) : ?>
							<span class="p-country-name"><?php echo esc_html( $pkiw_country ); ?></span>
						<?php endif; ?>
					</span>
				</div>
			<?php endif; ?>

			<?php if ( $pkiw_url ) : ?>
				<div class="venue-detail__website">
					<span class="venue-detail__website-icon" aria-hidden="true">🔗</span>
					<a href="<?php echo esc_url( $pkiw_url ); ?>" class="u-url" target="_blank" rel="noopener noreferrer">
						<?php echo esc_html( wp_parse_url( $pkiw_url, PHP_URL_HOST ) ); ?>
					</a>
				</div>
			<?php endif; ?>

			<?php if ( $pkiw_phone ) : ?>
				<div class="venue-detail__phone">
					<span class="venue-detail__phone-icon" aria-hidden="true">📞</span>
					<a href="tel:<?php echo esc_attr( preg_replace( '/[^+\d]/', '', $pkiw_phone ) ); ?>" class="p-tel">
						<?php echo esc_html( $pkiw_phone ); ?>
					</a>
				</div>
			<?php endif; ?>

			<?php // Hidden geo data for microformats. ?>
			<?php if ( $pkiw_latitude && $pkiw_longitude ) : ?>
				<span class="h-geo" hidden>
					<data class="p-latitude" value="<?php echo esc_attr( $pkiw_latitude ); ?>"></data>
					<data class="p-longitude" value="<?php echo esc_attr( $pkiw_longitude ); ?>"></data>
				</span>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( $pkiw_show_checkins && $pkiw_checkins && $pkiw_checkins->have_posts() ) : ?>
		<div class="venue-detail__checkins">
			<h3 class="venue-detail__checkins-title">
				<?php esc_html_e( 'Recent Check-ins', 'post-kinds-for-indieweb' ); ?>
			</h3>

			<div class="venue-detail__checkins-list h-feed">
				<?php while ( $pkiw_checkins->have_posts() ) : ?>
					<?php $pkiw_checkins->the_post(); ?>
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

			<?php if ( $pkiw_checkins->found_posts > $pkiw_checkin_count ) : ?>
				<p class="venue-detail__checkins-more">
					<a href="<?php echo esc_url( get_term_link( $pkiw_venue ) ); ?>">
						<?php
						printf(
							/* translators: %d: total number of check-ins */
							esc_html__( 'View all %d check-ins →', 'post-kinds-for-indieweb' ),
							absint( $pkiw_checkins->found_posts )
						);
						?>
					</a>
				</p>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>
