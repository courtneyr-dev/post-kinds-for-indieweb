<?php
/**
 * Jam Card Block - Server-side Render
 *
 * Generates the full jam card HTML from block attributes and appends
 * an oEmbed player when the URL matches a whitelisted provider.
 *
 * @package PostKindsForIndieWeb
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content (empty for dynamic blocks).
 * @var WP_Block $block      Block instance.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$pkiw_title     = $attributes['title'] ?? '';
$pkiw_artist    = $attributes['artist'] ?? '';
$pkiw_album     = $attributes['album'] ?? '';
$pkiw_cover     = $attributes['cover'] ?? '';
$pkiw_cover_alt = $attributes['coverAlt'] ?? '';
$pkiw_url       = $attributes['url'] ?? '';
$pkiw_note      = $attributes['note'] ?? '';
$pkiw_jammed_at = $attributes['jammedAt'] ?? '';
$pkiw_rel       = $attributes['rel'] ?? '';
$pkiw_layout    = $attributes['layout'] ?? 'horizontal';

$pkiw_link_rel = $pkiw_rel
	? 'noopener noreferrer ' . esc_attr( $pkiw_rel )
	: 'noopener noreferrer';

$pkiw_wrapper_attrs = get_block_wrapper_attributes(
	[
		'class' => 'jam-card layout-' . esc_attr( $pkiw_layout ),
	]
);

ob_start();
?>
<div <?php echo $pkiw_wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="post-kinds-card h-cite">
		<span class="post-kinds-card__type-icon" aria-hidden="true">&#127908;</span>
		<?php if ( $pkiw_cover ) : ?>
			<div class="post-kinds-card__media">
				<img
					src="<?php echo esc_url( $pkiw_cover ); ?>"
					alt="<?php echo esc_attr( $pkiw_cover_alt ? $pkiw_cover_alt : $pkiw_title . ' by ' . $pkiw_artist ); ?>"
					class="post-kinds-card__image u-photo"
					loading="lazy"
				/>
			</div>
		<?php endif; ?>

		<div class="post-kinds-card__content">
			<span class="post-kinds-card__badge">
				&#127925; Now Playing
			</span>

			<?php if ( $pkiw_title ) : ?>
				<h3 class="post-kinds-card__title p-name">
					<?php if ( $pkiw_url ) : ?>
						<a href="<?php echo esc_url( $pkiw_url ); ?>" class="u-url u-jam-of" target="_blank" rel="<?php echo esc_attr( $pkiw_link_rel ); ?>">
							<?php echo esc_html( $pkiw_title ); ?>
						</a>
					<?php else : ?>
						<?php echo esc_html( $pkiw_title ); ?>
					<?php endif; ?>
				</h3>
			<?php endif; ?>

			<?php if ( $pkiw_artist ) : ?>
				<p class="post-kinds-card__subtitle p-author h-card">
					<span class="p-name"><?php echo esc_html( $pkiw_artist ); ?></span>
				</p>
			<?php endif; ?>

			<?php if ( $pkiw_album ) : ?>
				<p class="post-kinds-card__meta"><?php echo esc_html( $pkiw_album ); ?></p>
			<?php endif; ?>

			<?php if ( $pkiw_note ) : ?>
				<p class="post-kinds-card__notes p-content">
					<?php echo esc_html( $pkiw_note ); ?>
				</p>
			<?php endif; ?>

			<?php if ( $pkiw_jammed_at ) : ?>
				<time class="post-kinds-card__timestamp dt-published" datetime="<?php echo esc_attr( gmdate( 'c', strtotime( $pkiw_jammed_at ) ) ); ?>">
					<?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $pkiw_jammed_at ) ) ); ?>
				</time>
			<?php endif; ?>
		</div>

	</div>

	<?php
	if ( $pkiw_url ) {
		$pkiw_embed = wp_oembed_get( $pkiw_url );
		if ( $pkiw_embed ) {
			echo '<div class="post-kinds-card__embed-section">' . $pkiw_embed . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
	?>
</div>
<?php
echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
