<?php
/**
 * Repost Card Block - Server-side Render
 *
 * Generates the repost card HTML from block attributes. The linked title
 * carries the `u-repost-of` microformats2 class so parsers see the
 * repost target.
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

$pkiw_title       = $attributes['title'] ?? '';
$pkiw_url         = $attributes['url'] ?? '';
$pkiw_description = $attributes['description'] ?? '';
$pkiw_image       = $attributes['image'] ?? '';
$pkiw_image_alt   = $attributes['imageAlt'] ?? '';
$pkiw_author      = $attributes['author'] ?? '';
$pkiw_reposted_at = $attributes['repostedAt'] ?? '';
$pkiw_rel         = $attributes['rel'] ?? '';
$pkiw_layout      = $attributes['layout'] ?? 'horizontal';

$pkiw_link_rel = $pkiw_rel
	? 'noopener noreferrer ' . esc_attr( $pkiw_rel )
	: 'noopener noreferrer';

$pkiw_wrapper_attrs = get_block_wrapper_attributes(
	[
		'class' => 'repost-card layout-' . esc_attr( $pkiw_layout ),
	]
);

ob_start();
?>
<div <?php echo $pkiw_wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="post-kinds-card h-cite">
		<?php if ( $pkiw_image ) : ?>
			<div class="post-kinds-card__media">
				<img
					src="<?php echo esc_url( $pkiw_image ); ?>"
					alt="<?php echo esc_attr( $pkiw_image_alt ? $pkiw_image_alt : $pkiw_title ); ?>"
					class="post-kinds-card__image u-photo"
					loading="lazy"
				/>
			</div>
		<?php endif; ?>

		<div class="post-kinds-card__content">
			<span class="post-kinds-card__badge">
				&#8635; <?php esc_html_e( 'Reposted', 'post-kinds-for-indieweb' ); ?>
			</span>

			<?php if ( $pkiw_title ) : ?>
				<h3 class="post-kinds-card__title p-name">
					<?php if ( $pkiw_url ) : ?>
						<a href="<?php echo esc_url( $pkiw_url ); ?>" class="u-url u-repost-of" target="_blank" rel="<?php echo esc_attr( $pkiw_link_rel ); ?>">
							<?php echo esc_html( $pkiw_title ); ?>
						</a>
					<?php else : ?>
						<?php echo esc_html( $pkiw_title ); ?>
					<?php endif; ?>
				</h3>
			<?php endif; ?>

			<?php if ( $pkiw_author ) : ?>
				<p class="post-kinds-card__subtitle p-author h-card">
					<span class="p-name"><?php echo esc_html( $pkiw_author ); ?></span>
				</p>
			<?php endif; ?>

			<?php if ( $pkiw_description ) : ?>
				<p class="post-kinds-card__notes p-content">
					<?php echo esc_html( $pkiw_description ); ?>
				</p>
			<?php endif; ?>

			<?php if ( $pkiw_reposted_at ) : ?>
				<time class="post-kinds-card__timestamp dt-published" datetime="<?php echo esc_attr( gmdate( 'c', strtotime( $pkiw_reposted_at ) ) ); ?>">
					<?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $pkiw_reposted_at ) ) ); ?>
				</time>
			<?php endif; ?>
		</div>
	</div>
</div>
<?php
echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
