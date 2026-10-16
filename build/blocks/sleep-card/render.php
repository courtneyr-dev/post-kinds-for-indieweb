<?php
/**
 * Sleep Card Block - Server-side Render
 *
 * Prints one record from Sleep_Archive::facts(): the duration, the moon phase
 * and the night. Bedtime and wake time print only when the card's own
 * showTimesPublicly attribute AND the post's toggle row both say so. The
 * Stream shape never prints a time, opted in or not.
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

use function PKIW\get_kind_label;

$pkiw_post_id = ( $block instanceof \WP_Block && ! empty( $block->context['postId'] ) ) ? (int) $block->context['postId'] : (int) get_the_ID();
$pkiw_facts   = \PKIW\Sleep_Archive::facts( $pkiw_post_id );

// A post the requester can't read, or one behind a password, has no record.
if ( [] === $pkiw_facts ) {
	return;
}

if ( wp_style_is( 'pkiw-sleep-night', 'registered' ) ) {
	wp_enqueue_style( 'pkiw-sleep-night' );
}

$pkiw_in_stream = function_exists( 'PKIW\is_rendering_stream_card' ) && \PKIW\is_rendering_stream_card();
$pkiw_minutes   = (int) $pkiw_facts['duration_minutes'];

ob_start();

if ( $pkiw_in_stream ) {
	// The skeleton every card shares on the Stream. The title is linked and
	// the date added under it by the Stream renderer, with a date-only
	// datetime. No bedtime or wake time, opted in or not.
	$pkiw_wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'pk-card k-sleep h-entry' ] );
	?>
<article <?php echo $pkiw_wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<span class="pk-kindlabel"><?php echo esc_html( get_kind_label( __( 'Sleep', 'post-kinds-for-indieweb-in-block-themes' ), 'sleep', 'sleep-card' ) ); ?></span>
	<h2 class="pk-title<?php echo $pkiw_facts['name_is_title'] ? ' p-name' : ''; ?>"><?php echo esc_html( (string) $pkiw_facts['name'] ); ?></h2>
	<span class="pkiw-sleep__moon" aria-hidden="true"><?php echo \PKIW\Sleep_Archive::moon_html( (string) $pkiw_facts['phase'], $pkiw_post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup from the theme's pkiw_sleep_moon_html filter. ?></span>
	<?php if ( $pkiw_minutes > 0 ) : ?>
		<p class="pkiw-sleep__duration p-sleep"><time datetime="<?php echo esc_attr( (string) $pkiw_facts['duration_iso'] ); ?>"><?php echo esc_html( (string) $pkiw_facts['duration_label'] ); ?></time></p>
	<?php endif; ?>
	<p class="pkiw-sleep__phase"><?php echo esc_html( (string) $pkiw_facts['phase_label'] ); ?></p>
</article>
	<?php
} else {
	// The card's own toggle, on top of the facts: both halves, every render path.
	$pkiw_times = true === ( $attributes['showTimesPublicly'] ?? null )
		&& '' !== (string) $pkiw_facts['bedtime_iso']
		&& '' !== (string) $pkiw_facts['wake_iso'];

	$pkiw_wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'pk-card k-sleep pkiw-sleep' ] );
	?>
<div <?php echo $pkiw_wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<span class="pkiw-sleep__moon" aria-hidden="true"><?php echo \PKIW\Sleep_Archive::moon_html( (string) $pkiw_facts['phase'], $pkiw_post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup from the theme's pkiw_sleep_moon_html filter. ?></span>
	<dl class="pkiw-sleep__record">
		<?php if ( $pkiw_minutes > 0 ) : ?>
			<div class="pkiw-sleep__row"><dt><?php esc_html_e( 'Duration', 'post-kinds-for-indieweb-in-block-themes' ); ?></dt><dd class="p-sleep"><time datetime="<?php echo esc_attr( (string) $pkiw_facts['duration_iso'] ); ?>"><?php echo esc_html( (string) $pkiw_facts['duration_label'] ); ?></time></dd></div>
		<?php endif; ?>
		<div class="pkiw-sleep__row"><dt><?php esc_html_e( 'Moon phase', 'post-kinds-for-indieweb-in-block-themes' ); ?></dt><dd><?php echo esc_html( (string) $pkiw_facts['phase_label'] ); ?></dd></div>
		<div class="pkiw-sleep__row"><dt><?php esc_html_e( 'Date', 'post-kinds-for-indieweb-in-block-themes' ); ?></dt><dd><time datetime="<?php echo esc_attr( (string) $pkiw_facts['night'] ); ?>"><?php echo esc_html( (string) $pkiw_facts['night_display'] ); ?></time></dd></div>
		<?php if ( $pkiw_times ) : ?>
			<div class="pkiw-sleep__row"><dt><?php esc_html_e( 'Bedtime', 'post-kinds-for-indieweb-in-block-themes' ); ?></dt><dd><time datetime="<?php echo esc_attr( (string) $pkiw_facts['bedtime_iso'] ); ?>"><?php echo esc_html( (string) $pkiw_facts['bedtime_label'] ); ?></time></dd></div>
			<div class="pkiw-sleep__row"><dt><?php esc_html_e( 'Wake time', 'post-kinds-for-indieweb-in-block-themes' ); ?></dt><dd><time datetime="<?php echo esc_attr( (string) $pkiw_facts['wake_iso'] ); ?>"><?php echo esc_html( (string) $pkiw_facts['wake_label'] ); ?></time></dd></div>
		<?php endif; ?>
	</dl>
	<?php if ( $pkiw_minutes > 0 ) : ?>
		<div class="pkiw-sleep__chart" aria-hidden="true"><span class="pkiw-sleep__bar" style="--pkiw-sleep-ratio:<?php echo esc_attr( rtrim( rtrim( number_format( (float) $pkiw_facts['duration_ratio'], 4, '.', '' ), '0' ), '.' ) ); ?>"></span></div>
	<?php endif; ?>
</div>
	<?php
}

echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
