<?php
/**
 * Checkin Card Block - Server-side Render
 *
 * Privacy-aware rendering for IndieWeb checkin posts. Mirrors the previous
 * save.js output so existing posts keep rendering identically; new posts
 * created via the Micropub bridge get a populated card automatically.
 *
 * @package PostKindsForIndieWeb
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content (empty for dynamic blocks).
 * @var WP_Block $block      Block instance.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- render.php variables are scoped by WordPress block rendering.

$pkiw_venue_name       = $attributes['venueName'] ?? '';
$pkiw_venue_type       = $attributes['venueType'] ?? 'place';
$pkiw_address          = $attributes['address'] ?? '';
$pkiw_locality         = $attributes['locality'] ?? '';
$pkiw_region           = $attributes['region'] ?? '';
$pkiw_country          = $attributes['country'] ?? '';
$pkiw_postal_code      = $attributes['postalCode'] ?? '';
$pkiw_latitude         = isset( $attributes['latitude'] ) ? (float) $attributes['latitude'] : null;
$pkiw_longitude        = isset( $attributes['longitude'] ) ? (float) $attributes['longitude'] : null;
$pkiw_location_privacy = $attributes['locationPrivacy'] ?? 'approximate';
$pkiw_osm_id           = $attributes['osmId'] ?? '';
$pkiw_venue_url        = $attributes['venueUrl'] ?? '';
$pkiw_foursquare_id    = $attributes['foursquareId'] ?? '';
$pkiw_checkin_at       = $attributes['checkinAt'] ?? '';
$pkiw_note             = $attributes['note'] ?? '';
$pkiw_photo            = $attributes['photo'] ?? '';
$pkiw_photo_alt        = $attributes['photoAlt'] ?? '';
$pkiw_show_map         = isset( $attributes['showMap'] ) ? (bool) $attributes['showMap'] : true;
$pkiw_layout           = $attributes['layout'] ?? 'horizontal';

$pkiw_is_private   = 'private' === $pkiw_location_privacy;
$pkiw_is_public    = 'public' === $pkiw_location_privacy;
$pkiw_has_coords   = null !== $pkiw_latitude && null !== $pkiw_longitude;
$pkiw_show_coords  = $pkiw_is_public && $pkiw_has_coords;
$pkiw_show_address = $pkiw_is_public && $pkiw_address;
$pkiw_show_map_emb = $pkiw_show_map && $pkiw_has_coords && ! $pkiw_is_private;

$pkiw_venue_icons  = [
	'place'      => '📍',
	'restaurant' => '🍽️',
	'cafe'       => '☕',
	'bar'        => '🍺',
	'hotel'      => '🏨',
	'airport'    => '✈️',
	'park'       => '🌳',
	'museum'     => '🏛️',
	'theater'    => '🎭',
	'store'      => '🛍️',
	'office'     => '🏢',
	'home'       => '🏠',
	'other'      => '📌',
];
$pkiw_venue_labels = [
	'place'      => __( 'Place', 'post-kinds-for-indieweb' ),
	'restaurant' => __( 'Restaurant', 'post-kinds-for-indieweb' ),
	'cafe'       => __( 'Cafe', 'post-kinds-for-indieweb' ),
	'bar'        => __( 'Bar', 'post-kinds-for-indieweb' ),
	'hotel'      => __( 'Hotel', 'post-kinds-for-indieweb' ),
	'airport'    => __( 'Airport', 'post-kinds-for-indieweb' ),
	'park'       => __( 'Park', 'post-kinds-for-indieweb' ),
	'museum'     => __( 'Museum', 'post-kinds-for-indieweb' ),
	'theater'    => __( 'Theater', 'post-kinds-for-indieweb' ),
	'store'      => __( 'Store', 'post-kinds-for-indieweb' ),
	'office'     => __( 'Office', 'post-kinds-for-indieweb' ),
	'home'       => __( 'Home', 'post-kinds-for-indieweb' ),
	'other'      => __( 'Other', 'post-kinds-for-indieweb' ),
];
$pkiw_icon         = $pkiw_venue_icons[ $pkiw_venue_type ] ?? $pkiw_venue_icons['place'];
$pkiw_label        = $pkiw_venue_labels[ $pkiw_venue_type ] ?? $pkiw_venue_labels['place'];

// Map URL — wider bbox for approximate privacy.
$pkiw_map_url = '';
if ( $pkiw_has_coords ) {
	$pkiw_bbox    = $pkiw_is_public ? 0.01 : 0.1;
	$pkiw_map_url = sprintf(
		'https://www.openstreetmap.org/export/embed.html?bbox=%F,%F,%F,%F&layer=mapnik&marker=%F,%F',
		$pkiw_longitude - $pkiw_bbox,
		$pkiw_latitude - $pkiw_bbox,
		$pkiw_longitude + $pkiw_bbox,
		$pkiw_latitude + $pkiw_bbox,
		$pkiw_latitude,
		$pkiw_longitude
	);
}

// Geo URI for microformats.
$pkiw_geo_uri = $pkiw_has_coords ? sprintf( 'geo:%F,%F', $pkiw_latitude, $pkiw_longitude ) : '';

$pkiw_wrapper_attrs = get_block_wrapper_attributes(
	[
		'class' => 'checkin-card layout-' . esc_attr( $pkiw_layout ),
	]
);

// Format checkin time.
$pkiw_checkin_iso     = '';
$pkiw_checkin_display = '';
if ( $pkiw_checkin_at ) {
	$pkiw_ts = strtotime( $pkiw_checkin_at );
	if ( $pkiw_ts ) {
		$pkiw_checkin_iso     = gmdate( 'c', $pkiw_ts );
		$pkiw_checkin_display = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $pkiw_ts );
	}
}

ob_start();
?>
<div <?php echo $pkiw_wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php if ( $pkiw_is_private ) : ?>
		<div class="post-kinds-card post-kinds-card--private h-entry">
			<?php if ( $pkiw_photo ) : ?>
				<div class="post-kinds-card__media">
					<img
						src="<?php echo esc_url( $pkiw_photo ); ?>"
						alt="<?php echo esc_attr( $pkiw_photo_alt ? $pkiw_photo_alt : __( 'Photo', 'post-kinds-for-indieweb' ) ); ?>"
						class="post-kinds-card__image u-photo"
						loading="lazy"
					/>
				</div>
			<?php endif; ?>

			<div class="post-kinds-card__content">
				<span class="post-kinds-card__badge">
					<span class="post-kinds-card__badge-icon" aria-hidden="true"><?php echo esc_html( $pkiw_icon ); ?></span>
					<?php echo esc_html( $pkiw_label ); ?>
				</span>

				<p class="post-kinds-card__private-notice">
					<span class="dashicons dashicons-lock" aria-hidden="true"></span>
					<?php esc_html_e( 'Location saved privately', 'post-kinds-for-indieweb' ); ?>
				</p>

				<?php if ( $pkiw_checkin_iso ) : ?>
					<time class="post-kinds-card__timestamp dt-published" datetime="<?php echo esc_attr( $pkiw_checkin_iso ); ?>">
						<?php echo esc_html( $pkiw_checkin_display ); ?>
					</time>
				<?php endif; ?>

				<?php if ( $pkiw_note ) : ?>
					<div class="post-kinds-card__notes p-content">
						<p><?php echo wp_kses_post( $pkiw_note ); ?></p>
					</div>
				<?php endif; ?>
			</div>
		</div>
	<?php else : ?>
		<div class="post-kinds-card h-entry">
			<?php if ( $pkiw_photo ) : ?>
				<div class="post-kinds-card__media">
					<img
						src="<?php echo esc_url( $pkiw_photo ); ?>"
						alt="<?php echo esc_attr( $pkiw_photo_alt ? $pkiw_photo_alt : sprintf( /* translators: %s: venue name */ __( 'Photo at %s', 'post-kinds-for-indieweb' ), $pkiw_venue_name ) ); ?>"
						class="post-kinds-card__image u-photo"
						loading="lazy"
					/>
				</div>
			<?php endif; ?>

			<div class="post-kinds-card__content">
				<span class="post-kinds-card__badge">
					<span class="post-kinds-card__badge-icon" aria-hidden="true"><?php echo esc_html( $pkiw_icon ); ?></span>
					<?php echo esc_html( $pkiw_label ); ?>
				</span>

				<?php if ( $pkiw_venue_name ) : ?>
					<h3 class="post-kinds-card__title">
						<?php if ( $pkiw_venue_url ) : ?>
							<a href="<?php echo esc_url( $pkiw_venue_url ); ?>" class="p-name u-url" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $pkiw_venue_name ); ?></a>
						<?php else : ?>
							<span class="p-name"><?php echo esc_html( $pkiw_venue_name ); ?></span>
						<?php endif; ?>
					</h3>
				<?php endif; ?>

				<div class="post-kinds-card__location p-location h-card">
					<?php if ( $pkiw_show_address ) : ?>
						<span class="p-street-address"><?php echo esc_html( $pkiw_address ); ?></span>
					<?php endif; ?>

					<?php if ( $pkiw_locality || $pkiw_region || $pkiw_country ) : ?>
						<span class="post-kinds-card__location-parts">
							<?php if ( $pkiw_locality ) : ?>
								<span class="p-locality"><?php echo esc_html( $pkiw_locality ); ?></span>
							<?php endif; ?>
							<?php
							if ( $pkiw_locality && $pkiw_region ) {
								echo ', ';
							}
							?>
							<?php if ( $pkiw_region ) : ?>
								<span class="p-region"><?php echo esc_html( $pkiw_region ); ?></span>
							<?php endif; ?>
							<?php
							if ( ( $pkiw_locality || $pkiw_region ) && $pkiw_country ) {
								echo ', ';
							}
							?>
							<?php if ( $pkiw_country ) : ?>
								<span class="p-country-name"><?php echo esc_html( $pkiw_country ); ?></span>
							<?php endif; ?>
						</span>
					<?php endif; ?>

					<?php if ( $pkiw_is_public && $pkiw_postal_code ) : ?>
						<span class="p-postal-code"><?php echo esc_html( $pkiw_postal_code ); ?></span>
					<?php endif; ?>

					<?php if ( $pkiw_show_coords ) : ?>
						<data class="p-geo h-geo" value="<?php echo esc_attr( $pkiw_geo_uri ); ?>">
							<data class="p-latitude" value="<?php echo esc_attr( (string) $pkiw_latitude ); ?>" hidden></data>
							<data class="p-longitude" value="<?php echo esc_attr( (string) $pkiw_longitude ); ?>" hidden></data>
						</data>
					<?php endif; ?>
				</div>

				<?php if ( $pkiw_checkin_iso ) : ?>
					<time class="post-kinds-card__timestamp dt-published" datetime="<?php echo esc_attr( $pkiw_checkin_iso ); ?>">
						<?php echo esc_html( $pkiw_checkin_display ); ?>
					</time>
				<?php endif; ?>

				<?php if ( $pkiw_note ) : ?>
					<div class="post-kinds-card__notes p-content">
						<p><?php echo wp_kses_post( $pkiw_note ); ?></p>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( $pkiw_show_map_emb ) : ?>
				<div class="post-kinds-card__map">
					<iframe
						title="<?php echo esc_attr( sprintf( /* translators: %s: venue name */ __( 'Map of %s', 'post-kinds-for-indieweb' ), $pkiw_venue_name ?: __( 'location', 'post-kinds-for-indieweb' ) ) ); ?>"
						width="100%"
						height="200"
						frameborder="0"
						scrolling="no"
						marginheight="0"
						marginwidth="0"
						src="<?php echo esc_url( $pkiw_map_url ); ?>"
						loading="lazy"
					></iframe>
					<?php if ( $pkiw_is_public ) : ?>
						<a
							href="<?php echo esc_url( sprintf( 'https://www.openstreetmap.org/?mlat=%F&mlon=%F#map=16/%F/%F', $pkiw_latitude, $pkiw_longitude, $pkiw_latitude, $pkiw_longitude ) ); ?>"
							class="post-kinds-card__map-link"
							target="_blank"
							rel="noopener noreferrer"
						>
							<?php esc_html_e( 'View larger map', 'post-kinds-for-indieweb' ); ?>
						</a>
					<?php else : ?>
						<p class="post-kinds-card__map-note">
							<?php esc_html_e( 'Showing approximate area', 'post-kinds-for-indieweb' ); ?>
						</p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<data class="u-checkin" value="<?php echo esc_attr( $pkiw_venue_url ); ?>" hidden></data>
			<?php if ( $pkiw_foursquare_id ) : ?>
				<data class="u-uid" value="<?php echo esc_attr( 'https://foursquare.com/v/' . $pkiw_foursquare_id ); ?>" hidden></data>
			<?php endif; ?>
			<?php if ( $pkiw_osm_id ) : ?>
				<data class="u-uid" value="<?php echo esc_attr( 'https://www.openstreetmap.org/' . $pkiw_osm_id ); ?>" hidden></data>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>
<?php
echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
