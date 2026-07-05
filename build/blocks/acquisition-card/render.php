<?php
/**
 * Acquisition Card Block - Server-side Render
 *
 * Renders the acquisition card in the two-layer pk-card system: plugin owns
 * structure (badge → label → title → sub → media → note → meta), theme owns
 * paint via --pk-* custom properties.
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

$pkiw_title       = $attributes['title'] ?? '';
$pkiw_type        = $attributes['acquisitionType'] ?? '';
$pkiw_cost        = $attributes['cost'] ?? '';
$pkiw_where       = $attributes['where'] ?? '';
$pkiw_where_url   = $attributes['whereUrl'] ?? '';
$pkiw_photo       = $attributes['photo'] ?? '';
$pkiw_photo_alt   = $attributes['photoAlt'] ?? '';
$pkiw_notes       = $attributes['notes'] ?? '';
$pkiw_acquired_at = $attributes['acquiredAt'] ?? '';

$pkiw_type_labels = [
	'purchase' => __( 'Purchase', 'post-kinds-for-indieweb' ),
	'gift'     => __( 'Gift', 'post-kinds-for-indieweb' ),
	'found'    => __( 'Found', 'post-kinds-for-indieweb' ),
	'won'      => __( 'Won', 'post-kinds-for-indieweb' ),
	'trade'    => __( 'Trade', 'post-kinds-for-indieweb' ),
	'free'     => __( 'Free', 'post-kinds-for-indieweb' ),
	'other'    => __( 'Other', 'post-kinds-for-indieweb' ),
];
$pkiw_type_label  = $pkiw_type_labels[ $pkiw_type ] ?? ( $pkiw_type ? $pkiw_type : __( 'Acquired', 'post-kinds-for-indieweb' ) );

$pkiw_wrapper_attrs = get_block_wrapper_attributes(
	[
		'class' => 'pk-card k-acquisition h-cite',
	]
);

ob_start();
?>
<article <?php echo $pkiw_wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="pk-badge"><?php echo get_kind_icon_svg( 'acquisition' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
	<div class="pk-body">
		<p class="pk-kindlabel"><?php echo esc_html( $pkiw_type_label ); ?></p>

		<?php if ( $pkiw_title ) : ?>
			<h3 class="pk-title p-name">
				<?php if ( $pkiw_where_url ) : ?>
					<a class="u-url" href="<?php echo esc_url( $pkiw_where_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $pkiw_title ); ?></a>
				<?php else : ?>
					<?php echo esc_html( $pkiw_title ); ?>
				<?php endif; ?>
			</h3>
		<?php endif; ?>

		<?php if ( $pkiw_cost || $pkiw_where ) : ?>
			<p class="pk-sub">
				<?php if ( $pkiw_cost ) : ?>
					<span><?php echo esc_html( $pkiw_cost ); ?></span>
				<?php endif; ?>
				<?php
				if ( $pkiw_cost && $pkiw_where ) :
					?>
					<span class="pk-dot"></span><?php endif; ?>
				<?php if ( $pkiw_where ) : ?>
					<span class="p-location"><?php printf( /* translators: %s: place the item was acquired. */ esc_html__( 'from %s', 'post-kinds-for-indieweb' ), esc_html( $pkiw_where ) ); ?></span>
				<?php endif; ?>
			</p>
		<?php endif; ?>

		<?php if ( $pkiw_photo ) : ?>
			<div class="pk-media">
				<img class="pk-thumb u-photo" src="<?php echo esc_url( $pkiw_photo ); ?>" alt="<?php echo esc_attr( $pkiw_photo_alt ? $pkiw_photo_alt : $pkiw_title ); ?>" loading="lazy" />
			</div>
		<?php endif; ?>

		<?php if ( $pkiw_notes ) : ?>
			<p class="pk-note p-content"><?php echo esc_html( $pkiw_notes ); ?></p>
		<?php endif; ?>

		<?php if ( $pkiw_acquired_at ) : ?>
			<div class="pk-meta">
				<time class="dt-published" datetime="<?php echo esc_attr( gmdate( 'c', (int) strtotime( $pkiw_acquired_at ) ) ); ?>"><?php echo esc_html( wp_date( get_option( 'date_format' ), (int) strtotime( $pkiw_acquired_at ) ) ); ?></time>
			</div>
		<?php endif; ?>
	</div>

	<data class="u-acquired" value="<?php echo esc_attr( $pkiw_title ); ?>" hidden></data>
</article>
<?php
echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
