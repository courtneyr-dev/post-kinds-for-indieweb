<?php
/**
 * Like Card Block - Server-side Render
 *
 * Renders the like card in the two-layer pk-card system: plugin owns
 * structure (badge → label → title → sub → note → meta), theme owns paint
 * via --pk-* custom properties. The linked title carries the `u-like-of`
 * microformats2 class so parsers see the liked URL.
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
$pkiw_url         = $attributes['url'] ?? '';
$pkiw_description = $attributes['description'] ?? '';
$pkiw_image       = $attributes['image'] ?? '';
$pkiw_image_alt   = $attributes['imageAlt'] ?? '';
$pkiw_author      = $attributes['author'] ?? '';
$pkiw_liked_at    = $attributes['likedAt'] ?? '';
$pkiw_rel         = $attributes['rel'] ?? '';

$pkiw_link_rel = $pkiw_rel
	? 'noopener noreferrer ' . esc_attr( $pkiw_rel )
	: 'noopener noreferrer';

$pkiw_wrapper_attrs = get_block_wrapper_attributes(
	[
		'class' => 'pk-card k-like h-cite',
	]
);

ob_start();
?>
<article <?php echo $pkiw_wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="pk-badge"><?php echo get_kind_icon_svg( 'like' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
	<div class="pk-body">
		<p class="pk-kindlabel"><?php esc_html_e( 'Like', 'post-kinds-for-indieweb' ); ?></p>

		<?php if ( $pkiw_title ) : ?>
			<h2 class="pk-title p-name">
				<?php if ( $pkiw_url ) : ?>
					<a class="u-url u-like-of" href="<?php echo esc_url( $pkiw_url ); ?>" target="_blank" rel="<?php echo esc_attr( $pkiw_link_rel ); ?>"><?php echo esc_html( $pkiw_title ); ?></a>
				<?php else : ?>
					<?php echo esc_html( $pkiw_title ); ?>
				<?php endif; ?>
			</h2>
		<?php endif; ?>

		<?php if ( $pkiw_author ) : ?>
			<p class="pk-sub">
				<span class="p-author h-card"><span class="p-name"><?php echo esc_html( $pkiw_author ); ?></span></span>
			</p>
		<?php endif; ?>

		<?php if ( $pkiw_description ) : ?>
			<p class="pk-note p-content"><?php echo esc_html( $pkiw_description ); ?></p>
		<?php endif; ?>

		<?php if ( $pkiw_image ) : ?>
			<div class="pk-embed pk-embed--photo"><img class="u-photo" src="<?php echo esc_url( $pkiw_image ); ?>" alt="<?php echo esc_attr( $pkiw_image_alt ? $pkiw_image_alt : $pkiw_title ); ?>" loading="lazy" /></div>
		<?php endif; ?>

		<?php if ( $pkiw_liked_at ) : ?>
			<div class="pk-meta">
				<time class="dt-published" datetime="<?php echo esc_attr( gmdate( 'c', strtotime( $pkiw_liked_at ) ) ); ?>"><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $pkiw_liked_at ) ) ); ?></time>
			</div>
		<?php endif; ?>
	</div>
</article>
<?php
echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
