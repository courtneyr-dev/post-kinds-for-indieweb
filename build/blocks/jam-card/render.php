<?php
/**
 * Jam Card Block - Server-side Render
 *
 * Renders the jam card in the two-layer pk-card system: plugin owns
 * structure (badge → label → title → sub → embed/media → meta), theme owns
 * paint via --pk-* custom properties. Appends a cached oEmbed player when the
 * jam URL matches a registered provider.
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

use function PostKindsForIndieWeb\get_cached_embed_html;
use function PostKindsForIndieWeb\get_kind_icon_svg;

$pkiw_title     = $attributes['title'] ?? '';
$pkiw_artist    = $attributes['artist'] ?? '';
$pkiw_album     = $attributes['album'] ?? '';
$pkiw_cover     = $attributes['cover'] ?? '';
$pkiw_cover_alt = $attributes['coverAlt'] ?? '';
$pkiw_url       = $attributes['url'] ?? '';
$pkiw_note      = $attributes['note'] ?? '';
$pkiw_jammed_at = $attributes['jammedAt'] ?? '';
$pkiw_rel       = $attributes['rel'] ?? '';

$pkiw_link_rel = $pkiw_rel
	? 'noopener noreferrer ' . esc_attr( $pkiw_rel )
	: 'noopener noreferrer';

$pkiw_embed = $pkiw_url ? get_cached_embed_html( $pkiw_url ) : false;

$pkiw_wrapper_attrs = get_block_wrapper_attributes(
	[
		'class' => 'pk-card k-jam h-cite',
	]
);

ob_start();
?>
<article <?php echo $pkiw_wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="pk-badge"><?php echo get_kind_icon_svg( 'jam' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
	<div class="pk-body">
		<p class="pk-kindlabel"><?php esc_html_e( 'Jam', 'post-kinds-for-indieweb' ); ?></p>

		<?php if ( $pkiw_title ) : ?>
			<h2 class="pk-title p-name">
				<?php if ( $pkiw_url ) : ?>
					<a class="u-url u-jam-of" href="<?php echo esc_url( $pkiw_url ); ?>" target="_blank" rel="<?php echo esc_attr( $pkiw_link_rel ); ?>"><?php echo esc_html( $pkiw_title ); ?></a>
				<?php else : ?>
					<?php echo esc_html( $pkiw_title ); ?>
				<?php endif; ?>
			</h2>
		<?php endif; ?>

		<?php if ( $pkiw_artist || $pkiw_album ) : ?>
			<p class="pk-sub">
				<?php if ( $pkiw_artist ) : ?>
					<span class="p-author h-card"><span class="p-name"><?php echo esc_html( $pkiw_artist ); ?></span></span>
				<?php endif; ?>
				<?php
				if ( $pkiw_artist && $pkiw_album ) :
					?>
					&mdash; <?php endif; ?>
				<?php if ( $pkiw_album ) : ?>
					<em><?php echo esc_html( $pkiw_album ); ?></em>
				<?php endif; ?>
			</p>
		<?php endif; ?>

		<?php if ( $pkiw_note ) : ?>
			<p class="pk-note p-content"><?php echo esc_html( $pkiw_note ); ?></p>
		<?php endif; ?>

		<?php if ( $pkiw_embed ) : ?>
			<div class="pk-embed pk-embed--audio"><?php echo $pkiw_embed; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
		<?php elseif ( $pkiw_cover ) : ?>
			<div class="pk-embed pk-embed--photo"><img class="u-photo" src="<?php echo esc_url( $pkiw_cover ); ?>" alt="<?php echo esc_attr( $pkiw_cover_alt ? $pkiw_cover_alt : $pkiw_title . ' by ' . $pkiw_artist ); ?>" loading="lazy" /></div>
		<?php endif; ?>

		<div class="pk-meta">
			<?php if ( $pkiw_url ) : ?>
				<a class="pk-link" href="<?php echo esc_url( $pkiw_url ); ?>" target="_blank" rel="<?php echo esc_attr( $pkiw_link_rel ); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 3l14 9-14 9z"/></svg><?php esc_html_e( 'Jam', 'post-kinds-for-indieweb' ); ?></a>
			<?php endif; ?>
			<?php
			if ( $pkiw_url && $pkiw_jammed_at ) :
				?>
				<span class="pk-dot"></span><?php endif; ?>
			<?php if ( $pkiw_jammed_at ) : ?>
				<time class="dt-published" datetime="<?php echo esc_attr( gmdate( 'c', (int) strtotime( $pkiw_jammed_at ) ) ); ?>"><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) strtotime( $pkiw_jammed_at ) ) ); ?></time>
			<?php endif; ?>
		</div>
	</div>
</article>
<?php
echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
