<?php
/**
 * Eat Card Block - Server-side Render
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

$pkiw_name              = $attributes['name'] ?? '';
$pkiw_cuisine           = $attributes['cuisine'] ?? '';
$pkiw_photo             = $attributes['photo'] ?? '';
$pkiw_photo_alt         = $attributes['photoAlt'] ?? '';
$pkiw_rating            = isset( $attributes['rating'] ) ? (int) $attributes['rating'] : 0;
$pkiw_ate_at            = $attributes['ateAt'] ?? '';
$pkiw_notes             = $attributes['notes'] ?? '';
$pkiw_restaurant_url    = $attributes['restaurantUrl'] ?? '';
$pkiw_location_name     = $attributes['locationName'] ?? '';
$pkiw_location_address  = $attributes['locationAddress'] ?? '';
$pkiw_location_locality = $attributes['locationLocality'] ?? '';
$pkiw_location_region   = $attributes['locationRegion'] ?? '';
$pkiw_location_country  = $attributes['locationCountry'] ?? '';
$pkiw_geo_lat           = isset( $attributes['geoLatitude'] ) ? (float) $attributes['geoLatitude'] : 0.0;
$pkiw_geo_lon           = isset( $attributes['geoLongitude'] ) ? (float) $attributes['geoLongitude'] : 0.0;
$pkiw_layout            = $attributes['layout'] ?? 'horizontal';

$pkiw_wrapper_attrs = get_block_wrapper_attributes(
	[
		'class' => 'eat-card layout-' . esc_attr( $pkiw_layout ),
	]
);

// Format ateAt timestamp.
$pkiw_ate_iso     = '';
$pkiw_ate_display = '';
if ( $pkiw_ate_at ) {
	$pkiw_ts = strtotime( $pkiw_ate_at );
	if ( $pkiw_ts ) {
		$pkiw_ate_iso     = gmdate( 'c', $pkiw_ts );
		$pkiw_ate_display = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $pkiw_ts );
	}
}

ob_start();
?>
<div <?php echo $pkiw_wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="post-kinds-card h-food">
		<?php if ( $pkiw_photo ) : ?>
			<div class="post-kinds-card__media">
				<img
					src="<?php echo esc_url( $pkiw_photo ); ?>"
					alt="<?php echo esc_attr( $pkiw_photo_alt ? $pkiw_photo_alt : $pkiw_name ); ?>"
					class="post-kinds-card__image u-photo"
					loading="lazy"
				/>
			</div>
		<?php endif; ?>

		<div class="post-kinds-card__content">
			<?php if ( $pkiw_cuisine ) : ?>
				<span class="post-kinds-card__badge"><?php echo esc_html( $pkiw_cuisine ); ?></span>
			<?php endif; ?>

			<?php if ( $pkiw_name ) : ?>
				<h3 class="post-kinds-card__title p-name"><?php echo esc_html( $pkiw_name ); ?></h3>
			<?php endif; ?>

			<?php if ( $pkiw_location_name ) : ?>
				<div class="post-kinds-card__location p-location h-card">
					<p class="post-kinds-card__venue">
						<?php if ( $pkiw_restaurant_url ) : ?>
							<a href="<?php echo esc_url( $pkiw_restaurant_url ); ?>" class="p-name u-url" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $pkiw_location_name ); ?></a>
						<?php else : ?>
							<span class="p-name"><?php echo esc_html( $pkiw_location_name ); ?></span>
						<?php endif; ?>
					</p>
					<?php if ( $pkiw_location_address ) : ?>
						<p class="post-kinds-card__address p-street-address"><?php echo esc_html( $pkiw_location_address ); ?></p>
					<?php endif; ?>
					<?php if ( $pkiw_location_locality || $pkiw_location_region || $pkiw_location_country ) : ?>
						<p class="post-kinds-card__city">
							<?php if ( $pkiw_location_locality ) : ?>
								<span class="p-locality"><?php echo esc_html( $pkiw_location_locality ); ?></span>
							<?php endif; ?>
							<?php
							if ( $pkiw_location_locality && $pkiw_location_region ) {
								echo ', ';
							}
							?>
							<?php if ( $pkiw_location_region ) : ?>
								<span class="p-region"><?php echo esc_html( $pkiw_location_region ); ?></span>
							<?php endif; ?>
							<?php
							if ( ( $pkiw_location_locality || $pkiw_location_region ) && $pkiw_location_country ) {
								echo ', ';
							}
							?>
							<?php if ( $pkiw_location_country ) : ?>
								<span class="p-country-name"><?php echo esc_html( $pkiw_location_country ); ?></span>
							<?php endif; ?>
						</p>
					<?php endif; ?>
					<?php if ( 0.0 !== $pkiw_geo_lat || 0.0 !== $pkiw_geo_lon ) : ?>
						<data class="p-geo h-geo" value="<?php echo esc_attr( $pkiw_geo_lat . ',' . $pkiw_geo_lon ); ?>" hidden>
							<span class="p-latitude"><?php echo esc_html( (string) $pkiw_geo_lat ); ?></span>
							<span class="p-longitude"><?php echo esc_html( (string) $pkiw_geo_lon ); ?></span>
						</data>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( $pkiw_rating > 0 ) : ?>
				<div class="post-kinds-card__rating p-rating" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: rating value */ __( 'Rating: %d out of 5', 'post-kinds-for-indieweb' ), $pkiw_rating ) ); ?>">
					<?php
					for ( $pkiw_i = 0; $pkiw_i < 5; $pkiw_i++ ) :
						$pkiw_filled = $pkiw_i < $pkiw_rating ? ' filled' : '';
						?>
						<span class="star<?php echo esc_attr( $pkiw_filled ); ?>" aria-hidden="true">★</span>
					<?php endfor; ?>
					<span class="post-kinds-card__rating-value"><?php echo esc_html( $pkiw_rating . '/5' ); ?></span>
				</div>
			<?php endif; ?>

			<?php if ( $pkiw_notes ) : ?>
				<p class="post-kinds-card__notes p-content"><?php echo wp_kses_post( $pkiw_notes ); ?></p>
			<?php endif; ?>

			<?php if ( $pkiw_ate_iso ) : ?>
				<time class="post-kinds-card__timestamp dt-published" datetime="<?php echo esc_attr( $pkiw_ate_iso ); ?>">
					<?php echo esc_html( $pkiw_ate_display ); ?>
				</time>
			<?php endif; ?>
		</div>

		<data class="u-ate" value="<?php echo esc_attr( $pkiw_name ); ?>" hidden></data>
	</div>
</div>
<?php
echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
