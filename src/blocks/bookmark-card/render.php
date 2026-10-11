<?php
/**
 * Bookmark Card Block - Server-side Render
 *
 * Renders the bookmark card in the two-layer pk-card system: plugin owns
 * structure (badge → label → title → sub → note → meta), theme owns paint
 * via --pk-* custom properties. When no usable title exists, the card links
 * the cited host instead of hiding the bookmarked URL.
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
use function PKIW\get_kind_label;

$pkiw_title         = $attributes['title'] ?? '';
$pkiw_url           = $attributes['url'] ?? '';
$pkiw_description   = $attributes['description'] ?? '';
$pkiw_image         = $attributes['image'] ?? '';
$pkiw_image_alt     = $attributes['imageAlt'] ?? '';
$pkiw_author        = $attributes['author'] ?? '';
$pkiw_bookmarked_at = $attributes['bookmarkedAt'] ?? '';
$pkiw_rel           = $attributes['rel'] ?? '';

$pkiw_link_rel = $pkiw_rel
	? 'noopener noreferrer ' . esc_attr( $pkiw_rel )
	: 'noopener noreferrer';

$pkiw_synthetic = '' !== trim( (string) $pkiw_url ) && (
	'' === trim( (string) $pkiw_title )
	|| \PKIW\card_name_is_url( (string) $pkiw_title, (string) $pkiw_url )
);
$pkiw_link_text = '';
if ( $pkiw_synthetic ) {
	$pkiw_link_text = \PKIW\url_host( (string) $pkiw_url );
	$pkiw_link_text = '' !== $pkiw_link_text ? $pkiw_link_text : (string) $pkiw_url;
}

$pkiw_wrapper_attrs = get_block_wrapper_attributes(
	[
		'class' => 'pk-card k-bookmark h-cite u-bookmark-of',
	]
);

[ $pkiw_bookmarked_iso, $pkiw_bookmarked_display ] = \PKIW\card_calendar_date( (string) $pkiw_bookmarked_at );

ob_start();
?>
<article <?php echo $pkiw_wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="pk-badge"><?php echo get_kind_icon_svg( 'bookmark' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
	<div class="pk-body">
		<span class="pk-kindlabel"><?php echo esc_html( get_kind_label( __( 'Bookmark', 'post-kinds-for-indieweb-in-block-themes' ), 'bookmark', 'bookmark-card' ) ); ?></span>

		<div class="pk-caption">
			<?php if ( $pkiw_title || $pkiw_synthetic ) : ?>
				<h2 class="<?php echo $pkiw_synthetic ? 'pk-title' : 'pk-title p-name'; ?>">
					<?php if ( $pkiw_url ) : ?>
						<a class="u-url" href="<?php echo esc_url( $pkiw_url ); ?>" target="_blank" rel="<?php echo esc_attr( $pkiw_link_rel ); ?>"<?php echo \PKIW\pkiw_new_tab_label_attr( $pkiw_synthetic ? $pkiw_link_text : $pkiw_title ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html( $pkiw_synthetic ? $pkiw_link_text : $pkiw_title ); ?></a>
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
		</div>

		<?php if ( $pkiw_description ) : ?>
			<p class="pk-note p-content"><?php echo esc_html( $pkiw_description ); ?></p>
		<?php endif; ?>

		<?php if ( $pkiw_image ) : ?>
			<div class="pk-embed pk-embed--photo"><img class="u-photo" src="<?php echo esc_url( $pkiw_image ); ?>" alt="<?php echo esc_attr( $pkiw_image_alt ); ?>" loading="lazy" /></div>
		<?php endif; ?>

		<?php if ( $pkiw_bookmarked_iso ) : ?>
			<div class="pk-meta">
				<time class="dt-published" datetime="<?php echo esc_attr( $pkiw_bookmarked_iso ); ?>"><?php echo esc_html( $pkiw_bookmarked_display ); ?></time>
			</div>
		<?php endif; ?>
	</div>
</article>
<?php
echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
