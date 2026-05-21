<?php
/**
 * Mood Card Block - Server-side Render
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

$pkiw_mood      = $attributes['mood'] ?? '';
$pkiw_emoji     = $attributes['emoji'] ?? '😊';
$pkiw_note      = $attributes['note'] ?? '';
$pkiw_intensity = isset( $attributes['intensity'] ) ? (int) $attributes['intensity'] : 0;
$pkiw_mood_at   = $attributes['moodAt'] ?? '';
$pkiw_layout    = $attributes['layout'] ?? 'horizontal';

$pkiw_wrapper_attrs = get_block_wrapper_attributes(
	[
		'class' => 'mood-card layout-' . esc_attr( $pkiw_layout ),
	]
);

$pkiw_mood_iso     = '';
$pkiw_mood_display = '';
if ( $pkiw_mood_at ) {
	$pkiw_ts = strtotime( $pkiw_mood_at );
	if ( $pkiw_ts ) {
		$pkiw_mood_iso     = gmdate( 'c', $pkiw_ts );
		$pkiw_mood_display = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $pkiw_ts );
	}
}

ob_start();
?>
<div <?php echo $pkiw_wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="post-kinds-card post-kinds-card--mood h-entry">
		<div class="post-kinds-card__emoji-section">
			<div class="post-kinds-card__emoji-display">
				<span class="post-kinds-card__emoji-large" role="img" aria-label="<?php echo esc_attr( $pkiw_mood ? $pkiw_mood : __( 'mood', 'post-kinds-for-indieweb' ) ); ?>">
					<?php echo esc_html( $pkiw_emoji ); ?>
				</span>
			</div>
			<div class="post-kinds-card__intensity-dots" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: intensity */ __( 'Intensity: %d out of 5', 'post-kinds-for-indieweb' ), $pkiw_intensity ) ); ?>">
				<?php
				for ( $pkiw_i = 0; $pkiw_i < 5; $pkiw_i++ ) :
					$pkiw_filled = $pkiw_i < $pkiw_intensity ? ' filled' : '';
					?>
					<span class="post-kinds-card__intensity-dot<?php echo esc_attr( $pkiw_filled ); ?>"></span>
				<?php endfor; ?>
			</div>
		</div>
		<div class="post-kinds-card__content">
			<?php if ( $pkiw_mood ) : ?>
				<h3 class="post-kinds-card__title p-name"><?php echo esc_html( $pkiw_mood ); ?></h3>
			<?php endif; ?>
			<?php if ( $pkiw_note ) : ?>
				<p class="post-kinds-card__notes p-content"><?php echo wp_kses_post( $pkiw_note ); ?></p>
			<?php endif; ?>
			<?php if ( $pkiw_mood_iso ) : ?>
				<time class="post-kinds-card__timestamp dt-published" datetime="<?php echo esc_attr( $pkiw_mood_iso ); ?>">
					<?php echo esc_html( $pkiw_mood_display ); ?>
				</time>
			<?php endif; ?>
		</div>
	</div>
</div>
<?php
echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
