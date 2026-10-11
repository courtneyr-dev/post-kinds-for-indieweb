<?php
/**
 * Like Card Block - Server-side Render
 *
 * Renders the like card in the two-layer pk-card system: plugin owns
 * structure (badge → label → title → sub → note → meta), theme owns paint
 * via --pk-* custom properties. When no usable title exists, the card links
 * the cited host. On the post's own single view, a repeated page title can
 * move to hidden data while the visible link reads "Read the original."
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

$pkiw_href      = esc_url_raw( (string) $pkiw_url );
$pkiw_synthetic = '' !== $pkiw_href && (
	'' === trim( (string) $pkiw_title )
	|| \PKIW\card_name_is_url( (string) $pkiw_title, (string) $pkiw_url )
);
$pkiw_link_text = '';
if ( $pkiw_synthetic ) {
	$pkiw_link_text = \PKIW\url_host( $pkiw_href );
	if ( '' === $pkiw_link_text ) {
		$pkiw_link_text = (string) preg_replace( '/[?#].*$/s', '', $pkiw_href );
	}
}

$pkiw_post     = get_post();
$pkiw_own_view = $pkiw_post instanceof WP_Post && is_singular() && get_queried_object_id() === $pkiw_post->ID;

$pkiw_like_title_is_heading = false;
if ( $pkiw_own_view && '' !== trim( (string) $pkiw_url ) && ! $pkiw_synthetic && '' !== trim( (string) $pkiw_title ) ) {
	$pkiw_plain                 = static fn( string $text ): string => trim( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	$pkiw_normalized_post_title = $pkiw_plain( $pkiw_post->post_title );
	$pkiw_normalized_card_title = $pkiw_plain( (string) $pkiw_title );
	$pkiw_default               = '' !== $pkiw_normalized_post_title && mb_strtolower( $pkiw_normalized_post_title, 'UTF-8' ) === mb_strtolower( $pkiw_normalized_card_title, 'UTF-8' );

	/**
	 * Filter whether the page heading already names the liked page.
	 *
	 * @since 1.9.0
	 *
	 * @param bool    $is_heading Whether the page's H1 already shows this title.
	 * @param WP_Post $post       The like post.
	 */
	$pkiw_like_title_is_heading = (bool) apply_filters( 'pkiw_like_card_title_is_heading', $pkiw_default, $pkiw_post );
}

$pkiw_wrapper_attrs = get_block_wrapper_attributes(
	[
		'class' => 'pk-card k-like h-cite u-like-of',
	]
);

[ $pkiw_liked_iso, $pkiw_liked_display ] = \PKIW\card_calendar_date( (string) $pkiw_liked_at );

$pkiw_date_is_fallback = false;
if ( '' === $pkiw_liked_iso && $pkiw_own_view ) {
	$pkiw_liked_iso        = (string) get_the_date( 'Y-m-d', $pkiw_post );
	$pkiw_liked_display    = (string) get_the_date( '', $pkiw_post );
	$pkiw_date_is_fallback = true;
}

ob_start();
?>
<article <?php echo $pkiw_wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="pk-badge"><?php echo get_kind_icon_svg( 'like' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
	<div class="pk-body">
		<span class="pk-kindlabel"><?php echo esc_html( get_kind_label( __( 'Like', 'post-kinds-for-indieweb-in-block-themes' ), 'like', 'like-card' ) ); ?></span>

		<div class="pk-caption">
			<?php if ( $pkiw_like_title_is_heading ) : ?>
				<?php $pkiw_read_original = __( 'Read the original', 'post-kinds-for-indieweb-in-block-themes' ); ?>
				<data class="p-name" value="<?php echo esc_attr( $pkiw_title ); ?>" hidden></data>
				<p class="pk-original"><a class="u-url" href="<?php echo esc_url( $pkiw_url ); ?>" target="_blank" rel="<?php echo esc_attr( $pkiw_link_rel ); ?>"<?php echo \PKIW\pkiw_new_tab_label_attr( $pkiw_read_original ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html( $pkiw_read_original ); ?></a></p>
			<?php elseif ( $pkiw_title || $pkiw_synthetic ) : ?>
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

		<?php if ( $pkiw_liked_iso ) : ?>
			<div class="pk-meta">
				<time<?php echo $pkiw_date_is_fallback ? '' : ' class="dt-published"'; ?> datetime="<?php echo esc_attr( $pkiw_liked_iso ); ?>"><?php echo esc_html( $pkiw_liked_display ); ?></time>
			</div>
		<?php endif; ?>
	</div>
</article>
<?php
echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
