<?php
/**
 * Drink Card Block - Server-side Render
 *
 * Renders the drink card in the two-layer pk-card system: plugin owns
 * structure (badge → label → title → sub → embed → note → meta), theme owns
 * paint via --pk-* custom properties.
 *
 * @package PKIW
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content (empty for dynamic blocks).
 * @var WP_Block $block      Block instance.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- render.php variables are scoped by WordPress block rendering.

use function PKIW\get_kind_icon_svg;

$pkiw_drink_labels = [
	'coffee'   => __( 'Coffee', 'post-kinds-for-indieweb' ),
	'tea'      => __( 'Tea', 'post-kinds-for-indieweb' ),
	'beer'     => __( 'Beer', 'post-kinds-for-indieweb' ),
	'wine'     => __( 'Wine', 'post-kinds-for-indieweb' ),
	'cocktail' => __( 'Cocktail', 'post-kinds-for-indieweb' ),
	'juice'    => __( 'Juice', 'post-kinds-for-indieweb' ),
	'soda'     => __( 'Soda', 'post-kinds-for-indieweb' ),
	'smoothie' => __( 'Smoothie', 'post-kinds-for-indieweb' ),
	'water'    => __( 'Water', 'post-kinds-for-indieweb' ),
	'other'    => __( 'Drink', 'post-kinds-for-indieweb' ),
];

$pkiw_name              = $attributes['name'] ?? '';
$pkiw_drink_type        = $attributes['drinkType'] ?? 'other';
$pkiw_brand             = $attributes['brand'] ?? '';
$pkiw_photo             = $attributes['photo'] ?? '';
$pkiw_photo_alt         = $attributes['photoAlt'] ?? '';
$pkiw_rating            = isset( $attributes['rating'] ) ? (int) $attributes['rating'] : 0;
$pkiw_drank_at          = $attributes['drankAt'] ?? '';
$pkiw_notes             = $attributes['notes'] ?? '';
$pkiw_venue_url         = $attributes['venueUrl'] ?? '';
$pkiw_location_name     = $attributes['locationName'] ?? '';
$pkiw_location_address  = $attributes['locationAddress'] ?? '';
$pkiw_location_locality = $attributes['locationLocality'] ?? '';
$pkiw_location_region   = $attributes['locationRegion'] ?? '';
$pkiw_location_country  = $attributes['locationCountry'] ?? '';
$pkiw_geo_lat           = isset( $attributes['geoLatitude'] ) ? (float) $attributes['geoLatitude'] : 0.0;
$pkiw_geo_lon           = isset( $attributes['geoLongitude'] ) ? (float) $attributes['geoLongitude'] : 0.0;

$pkiw_badge_label = $pkiw_drink_labels[ $pkiw_drink_type ] ?? $pkiw_drink_type;

$pkiw_wrapper_attrs = get_block_wrapper_attributes(
	[
		'class' => 'pk-card k-drink h-food',
	]
);

$pkiw_drank_iso     = '';
$pkiw_drank_display = '';
if ( $pkiw_drank_at ) {
	$pkiw_ts = strtotime( $pkiw_drank_at );
	if ( $pkiw_ts ) {
		$pkiw_drank_iso     = gmdate( 'c', $pkiw_ts );
		$pkiw_drank_display = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $pkiw_ts );
	}
}

ob_start();
?>
<article <?php echo $pkiw_wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="pk-badge"><?php echo get_kind_icon_svg( 'drink' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
	<div class="pk-body">
		<p class="pk-kindlabel"><?php esc_html_e( 'Drank', 'post-kinds-for-indieweb' ); ?></p>

		<?php if ( $pkiw_name ) : ?>
			<h2 class="pk-title p-name"><?php echo esc_html( $pkiw_name ); ?></h2>
		<?php endif; ?>

		<?php if ( $pkiw_brand || $pkiw_badge_label ) : ?>
			<p class="pk-sub">
				<span><?php echo esc_html( $pkiw_badge_label ); ?></span>
				<?php
				if ( $pkiw_badge_label && $pkiw_brand ) :
					?>
					&mdash; <?php endif; ?>
				<?php if ( $pkiw_brand ) : ?>
					<span class="p-author h-card"><span class="p-name"><?php echo esc_html( $pkiw_brand ); ?></span></span>
				<?php endif; ?>
			</p>
		<?php endif; ?>

		<?php if ( $pkiw_location_name ) : ?>
			<p class="pk-sub p-location h-card">
				<?php if ( $pkiw_venue_url ) : ?>
					<a class="pk-chip p-name u-url" href="<?php echo esc_url( $pkiw_venue_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $pkiw_location_name ); ?></a>
				<?php else : ?>
					<span class="pk-chip p-name"><?php echo esc_html( $pkiw_location_name ); ?></span>
				<?php endif; ?>
				<?php if ( $pkiw_location_address ) : ?>
					<span class="p-street-address"><?php echo esc_html( $pkiw_location_address ); ?></span>
				<?php endif; ?>
				<?php if ( $pkiw_location_locality ) : ?>
					<span class="p-locality"><?php echo esc_html( $pkiw_location_locality ); ?></span>
				<?php endif; ?>
				<?php if ( $pkiw_location_region ) : ?>
					<span class="p-region"><?php echo esc_html( $pkiw_location_region ); ?></span>
				<?php endif; ?>
				<?php if ( $pkiw_location_country ) : ?>
					<span class="p-country-name"><?php echo esc_html( $pkiw_location_country ); ?></span>
				<?php endif; ?>
				<?php if ( 0.0 !== $pkiw_geo_lat || 0.0 !== $pkiw_geo_lon ) : ?>
					<data class="p-geo h-geo" value="<?php echo esc_attr( $pkiw_geo_lat . ',' . $pkiw_geo_lon ); ?>" hidden>
						<span class="p-latitude"><?php echo esc_html( (string) $pkiw_geo_lat ); ?></span>
						<span class="p-longitude"><?php echo esc_html( (string) $pkiw_geo_lon ); ?></span>
					</data>
				<?php endif; ?>
			</p>
		<?php endif; ?>

		<?php if ( $pkiw_rating > 0 ) : ?>
			<div class="pk-stars p-rating" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: rating out of five. */ __( 'Rated %d of 5', 'post-kinds-for-indieweb' ), $pkiw_rating ) ); ?>">
				<?php for ( $pkiw_i = 1; $pkiw_i <= 5; $pkiw_i++ ) : ?>
					<svg class="<?php echo $pkiw_i <= $pkiw_rating ? '' : 'off'; ?>" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l3 6.5 7 .6-5.3 4.6 1.6 6.8L12 17l-6.9 3.5 1.6-6.8L1.4 9.1l7-.6z"/></svg>
				<?php endfor; ?>
			</div>
		<?php endif; ?>

		<?php if ( $pkiw_photo ) : ?>
			<div class="pk-embed pk-embed--photo"><img class="u-photo" src="<?php echo esc_url( $pkiw_photo ); ?>" alt="<?php echo esc_attr( $pkiw_photo_alt ? $pkiw_photo_alt : $pkiw_name ); ?>" loading="lazy" /></div>
		<?php endif; ?>

		<?php if ( $pkiw_notes ) : ?>
			<p class="pk-note p-content"><?php echo wp_kses_post( $pkiw_notes ); ?></p>
		<?php endif; ?>

		<div class="pk-meta">
			<?php if ( $pkiw_drank_iso ) : ?>
				<time class="dt-published" datetime="<?php echo esc_attr( $pkiw_drank_iso ); ?>"><?php echo esc_html( $pkiw_drank_display ); ?></time>
			<?php endif; ?>
		</div>
	</div>

	<data class="u-drank" value="<?php echo esc_attr( $pkiw_name ); ?>" hidden></data>
</article>
<?php
echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
