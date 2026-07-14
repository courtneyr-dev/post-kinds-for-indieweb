<?php
/**
 * Mood Card Block - Server-side Render
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

$pkiw_mood    = $attributes['mood'] ?? '';
$pkiw_emoji   = $attributes['emoji'] ?? '😊';
$pkiw_note    = $attributes['note'] ?? '';
$pkiw_mood_at = $attributes['moodAt'] ?? '';

$pkiw_wrapper_attrs = get_block_wrapper_attributes(
	[
		'class' => 'pk-card k-mood h-entry',
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
<article <?php echo $pkiw_wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="pk-badge"><?php echo get_kind_icon_svg( 'mood' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
	<div class="pk-body">
		<p class="pk-kindlabel"><?php esc_html_e( 'Mood', 'post-kinds-for-indieweb' ); ?></p>

		<div class="pk-mood">
			<?php if ( $pkiw_emoji ) : ?>
				<span class="pk-mood__emoji" aria-hidden="true"><?php echo esc_html( $pkiw_emoji ); ?></span>
			<?php endif; ?>
			<?php if ( $pkiw_note ) : ?>
				<p class="pk-mood__note p-content"><?php echo wp_kses_post( $pkiw_note ); ?></p>
			<?php endif; ?>
		</div>

		<?php if ( $pkiw_mood_iso ) : ?>
			<div class="pk-meta">
				<time class="dt-published" datetime="<?php echo esc_attr( $pkiw_mood_iso ); ?>"><?php echo esc_html( $pkiw_mood_display ); ?></time>
			</div>
		<?php endif; ?>
	</div>

	<?php if ( $pkiw_mood ) : ?>
		<data class="p-name" value="<?php echo esc_attr( $pkiw_mood ); ?>" hidden></data>
	<?php endif; ?>
</article>
<?php
echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
