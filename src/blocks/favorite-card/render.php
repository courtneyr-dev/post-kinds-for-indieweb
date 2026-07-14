<?php
/**
 * Favorite Card Block - Server-side Render
 *
 * Renders the favorite card in the two-layer pk-card system: plugin owns
 * structure (badge → label → title → sub → media → note → meta), theme owns
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

$pkiw_title        = $attributes['title'] ?? '';
$pkiw_url          = $attributes['url'] ?? '';
$pkiw_description  = $attributes['description'] ?? '';
$pkiw_image        = $attributes['image'] ?? '';
$pkiw_image_alt    = $attributes['imageAlt'] ?? '';
$pkiw_author       = $attributes['author'] ?? '';
$pkiw_favorited_at = $attributes['favoritedAt'] ?? '';
$pkiw_rel          = $attributes['rel'] ?? '';

$pkiw_link_rel = $pkiw_rel ? 'noopener noreferrer ' . $pkiw_rel : 'noopener noreferrer';

$pkiw_wrapper_attrs = get_block_wrapper_attributes(
	[
		'class' => 'pk-card k-favorite h-cite',
	]
);

ob_start();
?>
<article <?php echo $pkiw_wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="pk-badge"><?php echo get_kind_icon_svg( 'favorite' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
	<div class="pk-body">
		<p class="pk-kindlabel"><?php esc_html_e( 'Favorite', 'post-kinds-for-indieweb-in-block-themes' ); ?></p>

		<?php if ( $pkiw_title ) : ?>
			<h2 class="pk-title p-name">
				<?php if ( $pkiw_url ) : ?>
					<a class="u-url u-favorite-of" href="<?php echo esc_url( $pkiw_url ); ?>" target="_blank" rel="<?php echo esc_attr( $pkiw_link_rel ); ?>"><?php echo esc_html( $pkiw_title ); ?></a>
				<?php else : ?>
					<?php echo esc_html( $pkiw_title ); ?>
				<?php endif; ?>
			</h2>
		<?php endif; ?>

		<?php if ( $pkiw_author ) : ?>
			<p class="pk-sub"><span class="p-author h-card"><span class="p-name"><?php echo esc_html( $pkiw_author ); ?></span></span></p>
		<?php endif; ?>

		<?php if ( $pkiw_image ) : ?>
			<div class="pk-media">
				<img class="pk-thumb u-photo" src="<?php echo esc_url( $pkiw_image ); ?>" alt="<?php echo esc_attr( $pkiw_image_alt ? $pkiw_image_alt : $pkiw_title ); ?>" loading="lazy" />
			</div>
		<?php endif; ?>

		<?php if ( $pkiw_description ) : ?>
			<p class="pk-note p-content"><?php echo esc_html( $pkiw_description ); ?></p>
		<?php endif; ?>

		<?php if ( $pkiw_favorited_at ) : ?>
			<div class="pk-meta">
				<time class="dt-published" datetime="<?php echo esc_attr( gmdate( 'c', (int) strtotime( $pkiw_favorited_at ) ) ); ?>"><?php echo esc_html( wp_date( get_option( 'date_format' ), (int) strtotime( $pkiw_favorited_at ) ) ); ?></time>
			</div>
		<?php endif; ?>
	</div>
</article>
<?php
echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
