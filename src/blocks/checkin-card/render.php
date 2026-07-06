<?php
/**
 * Checkin Card Block - Server-side Render
 *
 * Renders the checkin card in the two-layer pk-card system: plugin owns
 * structure (badge → label → title → sub → embed/media → note → meta), theme
 * owns paint via --pk-* custom properties. Privacy-aware: private checkins hide
 * the venue name, address, coordinates, and map; public checkins get the full
 * h-card / h-adr / h-geo microformat tree plus an optional OpenStreetMap embed.
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

use function PostKindsForIndieWeb\get_kind_icon_svg;

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

$pkiw_is_private   = 'private' === $pkiw_location_privacy;
$pkiw_is_public    = 'public' === $pkiw_location_privacy;
$pkiw_has_coords   = null !== $pkiw_latitude && null !== $pkiw_longitude;
$pkiw_show_coords  = $pkiw_is_public && $pkiw_has_coords;
$pkiw_show_address = $pkiw_is_public && $pkiw_address;
$pkiw_show_map_emb = $pkiw_show_map && $pkiw_has_coords && ! $pkiw_is_private;

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
		'class' => 'pk-card k-checkin h-entry',
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
<article <?php echo $pkiw_wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="pk-badge"><?php echo get_kind_icon_svg( 'checkin' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
	<div class="pk-body">
		<p class="pk-kindlabel"><?php esc_html_e( 'Check-in', 'post-kinds-for-indieweb' ); ?></p>

		<?php if ( $pkiw_is_private ) : ?>
			<p class="pk-note">
				<span class="dashicons dashicons-lock" aria-hidden="true"></span>
				<?php esc_html_e( 'Location saved privately', 'post-kinds-for-indieweb' ); ?>
			</p>

			<?php if ( $pkiw_photo ) : ?>
				<div class="pk-embed pk-embed--photo"><img class="u-photo" src="<?php echo esc_url( $pkiw_photo ); ?>" alt="<?php echo esc_attr( $pkiw_photo_alt ? $pkiw_photo_alt : __( 'Photo', 'post-kinds-for-indieweb' ) ); ?>" loading="lazy" /></div>
			<?php endif; ?>

			<?php if ( $pkiw_note ) : ?>
				<div class="pk-note p-content">
					<p><?php echo wp_kses_post( $pkiw_note ); ?></p>
				</div>
			<?php endif; ?>

			<div class="pk-meta">
				<?php if ( $pkiw_checkin_iso ) : ?>
					<time class="dt-published" datetime="<?php echo esc_attr( $pkiw_checkin_iso ); ?>"><?php echo esc_html( $pkiw_checkin_display ); ?></time>
				<?php endif; ?>
			</div>
		<?php else : ?>
			<?php if ( $pkiw_venue_name ) : ?>
				<h2 class="pk-title p-name">
					<?php if ( $pkiw_venue_url ) : ?>
						<a class="u-url" href="<?php echo esc_url( $pkiw_venue_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $pkiw_venue_name ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $pkiw_venue_name ); ?>
					<?php endif; ?>
				</h2>
			<?php endif; ?>

			<p class="pk-sub p-location h-card">
				<?php if ( $pkiw_show_address ) : ?>
					<span class="p-street-address"><?php echo esc_html( $pkiw_address ); ?></span>
				<?php endif; ?>

				<?php if ( $pkiw_locality || $pkiw_region || $pkiw_country ) : ?>
					<span class="pk-sub-parts">
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
			</p>

			<?php if ( $pkiw_show_map_emb ) : ?>
				<div class="pk-embed pk-embed--map">
					<iframe
						title="<?php echo esc_attr( sprintf( /* translators: %s: venue name */ __( 'Map of %s', 'post-kinds-for-indieweb' ), $pkiw_venue_name ? $pkiw_venue_name : __( 'location', 'post-kinds-for-indieweb' ) ) ); ?>"
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
							class="pk-map-link"
							target="_blank"
							rel="noopener noreferrer"
						>
							<?php esc_html_e( 'View larger map', 'post-kinds-for-indieweb' ); ?>
						</a>
					<?php else : ?>
						<p class="pk-map-note">
							<?php esc_html_e( 'Showing approximate area', 'post-kinds-for-indieweb' ); ?>
						</p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

				<?php if ( $pkiw_photo ) : ?>
				<div class="pk-embed pk-embed--photo"><img class="u-photo" src="<?php echo esc_url( $pkiw_photo ); ?>" alt="<?php echo esc_attr( $pkiw_photo_alt ? $pkiw_photo_alt : sprintf( /* translators: %s: venue name */ __( 'Photo at %s', 'post-kinds-for-indieweb' ), $pkiw_venue_name ) ); ?>" loading="lazy" /></div>
			<?php endif; ?>

			<?php if ( $pkiw_note ) : ?>
				<div class="pk-note p-content">
					<p><?php echo wp_kses_post( $pkiw_note ); ?></p>
				</div>
			<?php endif; ?>

			<div class="pk-meta">
				<?php if ( $pkiw_checkin_iso ) : ?>
					<time class="dt-published" datetime="<?php echo esc_attr( $pkiw_checkin_iso ); ?>"><?php echo esc_html( $pkiw_checkin_display ); ?></time>
				<?php endif; ?>
			</div>

			<data class="u-checkin" value="<?php echo esc_attr( $pkiw_venue_url ); ?>" hidden></data>
			<?php if ( $pkiw_foursquare_id ) : ?>
				<data class="u-uid" value="<?php echo esc_attr( 'https://foursquare.com/v/' . $pkiw_foursquare_id ); ?>" hidden></data>
			<?php endif; ?>
			<?php if ( $pkiw_osm_id ) : ?>
				<data class="u-uid" value="<?php echo esc_attr( 'https://www.openstreetmap.org/' . $pkiw_osm_id ); ?>" hidden></data>
			<?php endif; ?>
		<?php endif; ?>
	</div>
</article>
<?php
echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
